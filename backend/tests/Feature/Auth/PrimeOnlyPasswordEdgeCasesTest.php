<?php

namespace Tests\Feature\Auth;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\User;
use App\Rules\CurrentPassword;
use App\Services\ExternalUserAuthService;
use App\Services\PrimeUserSync;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use PDOException;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\Concerns\FakesPrime;
use Tests\TestCase;

/**
 * QA edge cases for PRIME-only passwords and the live Users list.
 */
class PrimeOnlyPasswordEdgeCasesTest extends TestCase
{
    use CreatesUsersWithRoles, FakesPrime, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->fakePrime();
    }

    // --- allowlist parsing -------------------------------------------------

    public function test_allowlist_env_parsing_trims_lowercases_and_drops_empty_entries(): void
    {
        $previous = $_SERVER['LOCAL_LOGIN_EMAILS'] ?? null;
        $_SERVER['LOCAL_LOGIN_EMAILS'] = ' Dev@Example.TEST , ,second@example.test,,';
        $_ENV['LOCAL_LOGIN_EMAILS'] = $_SERVER['LOCAL_LOGIN_EMAILS'];

        try {
            $config = require config_path('auth.php');
        } finally {
            if ($previous === null) {
                unset($_SERVER['LOCAL_LOGIN_EMAILS'], $_ENV['LOCAL_LOGIN_EMAILS']);
            } else {
                $_SERVER['LOCAL_LOGIN_EMAILS'] = $_ENV['LOCAL_LOGIN_EMAILS'] = $previous;
            }
        }

        $this->assertSame(['dev@example.test', 'second@example.test'], $config['local_login_emails']);
    }

    public function test_unset_allowlist_env_is_an_empty_list(): void
    {
        $previous = $_SERVER['LOCAL_LOGIN_EMAILS'] ?? null;
        $_SERVER['LOCAL_LOGIN_EMAILS'] = $_ENV['LOCAL_LOGIN_EMAILS'] = '';

        try {
            $config = require config_path('auth.php');
        } finally {
            if ($previous === null) {
                unset($_SERVER['LOCAL_LOGIN_EMAILS'], $_ENV['LOCAL_LOGIN_EMAILS']);
            } else {
                $_SERVER['LOCAL_LOGIN_EMAILS'] = $_ENV['LOCAL_LOGIN_EMAILS'] = $previous;
            }
        }

        $this->assertSame([], $config['local_login_emails']);
        config(['auth.local_login_emails' => $config['local_login_emails']]);
        $this->assertFalse(ExternalUserAuthService::allowsLocalPassword('anyone@example.test'));
    }

    public function test_allows_local_password_matches_typed_email_case_and_whitespace(): void
    {
        config(['auth.local_login_emails' => ['dev@example.test']]);

        $this->assertTrue(ExternalUserAuthService::allowsLocalPassword(' DEV@example.TEST '));
        $this->assertFalse(ExternalUserAuthService::allowsLocalPassword('dev@example.test.evil'));
        $this->assertFalse(ExternalUserAuthService::allowsLocalPassword('xdev@example.test'));
    }

    // --- break-glass login ------------------------------------------------

    public function test_allowlisted_account_without_a_local_password_cannot_log_in_when_prime_is_down(): void
    {
        User::factory()->create(['email' => 'dev@example.test', 'password' => null])->givePermissionTo(Permission::EquipmentView->value);
        config(['auth.local_login_emails' => ['dev@example.test']]);
        $this->breakPrime();

        $this->postJson('/api/login', ['email' => 'dev@example.test', 'password' => ''])->assertStatus(422);
        $this->postJson('/api/login', ['email' => 'dev@example.test', 'password' => 'anything'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Invalid credentials.');
        $this->assertGuest();
    }

    public function test_inactive_allowlisted_account_is_refused_on_break_glass(): void
    {
        User::factory()->create(['email' => 'dev@example.test', 'password' => Hash::make('LocalPass123!'), 'is_active' => false])
            ->givePermissionTo(Permission::EquipmentView->value);
        config(['auth.local_login_emails' => ['dev@example.test']]);
        $this->breakPrime();

        $this->postJson('/api/login', ['email' => 'dev@example.test', 'password' => 'LocalPass123!'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Invalid credentials.');
        $this->assertGuest();
    }

    public function test_allowlisted_account_known_to_prime_uses_prime_and_not_the_local_password(): void
    {
        $this->prime('users')->insert([
            'full_name' => 'Dev', 'email_address' => 'dev@example.test', 'password' => Hash::make('PrimePass123!'), 'user_status' => 'Active',
        ]);
        User::factory()->create(['email' => 'dev@example.test', 'password' => Hash::make('LocalPass123!')])->givePermissionTo(Permission::EquipmentView->value);
        config(['auth.local_login_emails' => ['dev@example.test']]);

        $this->postJson('/api/login', ['email' => 'dev@example.test', 'password' => 'LocalPass123!'])->assertStatus(422);
        $this->postJson('/api/login', ['email' => 'dev@example.test', 'password' => 'PrimePass123!'])->assertOk();
    }

    // --- CurrentPassword --------------------------------------------------

    /**
     * Login lets an allowlisted account that PRIME knows sign in with its
     * PRIME password; password re-confirmation must accept the same
     * password, otherwise that account can sign in but never confirm.
     */
    public function test_allowlisted_account_known_to_prime_can_confirm_with_its_prime_password(): void
    {
        $user = User::factory()->create(['email' => 'dev@example.test', 'password' => null]);
        $this->prime('users')->insert([
            'full_name' => 'Dev', 'email_address' => 'dev@example.test', 'password' => Hash::make('PrimePass123!'), 'user_status' => 'Active',
        ]);
        config(['auth.local_login_emails' => ['dev@example.test']]);

        $errors = Validator::make(['p' => 'PrimePass123!'], ['p' => [new CurrentPassword($user)]])->errors()->get('p');

        $this->assertSame([], $errors);
    }

    public function test_current_password_rejects_non_string_and_missing_user(): void
    {
        $user = User::factory()->create(['email' => 'dev@example.test']);
        config(['auth.local_login_emails' => ['dev@example.test']]);

        $this->assertNotEmpty(Validator::make(['p' => ['password']], ['p' => [new CurrentPassword($user)]])->errors()->get('p'));
        $this->assertNotEmpty(Validator::make(['p' => 'password'], ['p' => [new CurrentPassword(null)]])->errors()->get('p'));
    }

    // --- profile endpoint -------------------------------------------------

    public function test_profile_rejects_a_password_without_confirmation_and_writes_nothing(): void
    {
        $user = User::factory()->create(['name' => 'Same', 'email' => 'me@example.test', 'password' => Hash::make('LocalPass123!')])
            ->givePermissionTo(Permission::EquipmentView->value);

        $this->actingAs($user)->postJson('/api/profile', ['name' => 'Hacked', 'email' => 'x@example.test', 'password' => 'NewPassword123!'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password' => 'Passwords are managed in PRIME.'])
            ->assertJsonMissingValidationErrors(['name', 'email']);

        $fresh = $user->fresh();
        $this->assertSame('Same', $fresh->name);
        $this->assertSame('me@example.test', $fresh->email);
        $this->assertTrue(Hash::check('LocalPass123!', $fresh->password));
    }

    public function test_profile_ignores_mass_assignment_of_privileged_columns(): void
    {
        $user = User::factory()->create(['is_active' => true])->givePermissionTo(Permission::EquipmentView->value);

        $this->actingAs($user)->postJson('/api/profile', ['is_active' => false, 'office_id' => 999, 'employee_no' => 'X1'])->assertOk();

        $fresh = $user->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertNull($fresh->employee_no);
    }

    // --- live Users list --------------------------------------------------

    private function listAdmin(array $attributes = []): User
    {
        return $this->userWithPermissions([Permission::UsersView], $attributes, DataScope::All);
    }

    /**
     * PRIME restored from a backup (or its users table re-seeded) leaves the
     * cached mark above PRIME's real max. New accounts must still appear.
     */
    public function test_import_recovers_when_the_cached_mark_is_ahead_of_prime(): void
    {
        $admin = $this->listAdmin();
        $this->primeAccount('existing@deped.gov.ph');
        Cache::forever(PrimeUserSync::HIGH_WATER_MARK_KEY, 1000);
        $this->primeAccount('after-restore@deped.gov.ph', name: 'After Restore');

        $this->actingAs($admin)->getJson('/api/users')->assertOk();
        $this->actingAs($admin)->getJson('/api/users')->assertOk();

        $this->assertTrue(User::where('email', 'after-restore@deped.gov.ph')->exists());
    }

    public function test_import_does_not_duplicate_a_local_user_whose_email_differs_only_in_case(): void
    {
        $admin = $this->listAdmin();
        $local = User::factory()->create(['email' => 'juan@deped.gov.ph', 'name' => 'Old']);
        Cache::forever(PrimeUserSync::HIGH_WATER_MARK_KEY, 0);
        $this->primeAccount('Juan@DepEd.gov.ph', name: 'Juan Dela Cruz');

        $this->actingAs($admin)->getJson('/api/users')->assertOk();

        $this->assertSame(1, User::whereRaw('LOWER(email) = ?', ['juan@deped.gov.ph'])->count());
        $this->assertSame('Juan Dela Cruz', $local->fresh()->name);
    }

    public function test_import_skips_a_prime_row_with_an_empty_email(): void
    {
        $admin = $this->listAdmin();
        Cache::forever(PrimeUserSync::HIGH_WATER_MARK_KEY, 0);
        $this->primeAccount('');
        $this->primeAccount('good@deped.gov.ph');

        $this->actingAs($admin)->getJson('/api/users')->assertOk();

        $this->assertFalse(User::where('email', '')->exists());
        $this->assertTrue(User::where('email', 'good@deped.gov.ph')->exists());
    }

    public function test_import_is_not_wedged_by_a_prime_row_with_a_null_email(): void
    {
        Schema::connection('external_users')->drop('users');
        Schema::connection('external_users')->create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('full_name');
            $table->string('email_address')->nullable();
            $table->string('password')->default('x');
            $table->string('user_status')->default('Active');
            $table->unsignedInteger('department_school_id')->nullable();
        });
        $admin = $this->listAdmin();
        Cache::forever(PrimeUserSync::HIGH_WATER_MARK_KEY, 0);
        $this->prime('users')->insert(['full_name' => 'No Email', 'email_address' => null]);
        $good = $this->primeAccount('good@deped.gov.ph');

        $this->actingAs($admin)->getJson('/api/users')->assertOk();

        $this->assertTrue(User::where('email', 'good@deped.gov.ph')->exists());
        $this->assertSame($good, Cache::get(PrimeUserSync::HIGH_WATER_MARK_KEY));
    }

    public function test_admin_deactivated_in_prime_viewing_the_list_is_deactivated_and_then_shut_out(): void
    {
        $admin = $this->listAdmin(['email' => 'admin@deped.gov.ph']);
        $this->primeAccount('admin@deped.gov.ph', status: 'Deactivated', name: $admin->name);
        Cache::forever(PrimeUserSync::HIGH_WATER_MARK_KEY, 1);

        $this->actingAs($admin)->getJson('/api/users?search=admin@')->assertOk()->assertJsonPath('data.0.is_active', false);
        $this->assertFalse($admin->fresh()->is_active);

        $this->actingAs($admin->fresh())->getJson('/api/users')->assertUnauthorized();
    }

    public function test_user_without_users_view_cannot_trigger_the_prime_import(): void
    {
        $pending = $this->userWithPermissions([]);
        Cache::forever(PrimeUserSync::HIGH_WATER_MARK_KEY, 0);
        $this->primeAccount('new@deped.gov.ph');

        $this->actingAs($pending)->getJson('/api/users')->assertForbidden();

        $this->assertFalse(User::where('email', 'new@deped.gov.ph')->exists());
        $this->assertSame(0, Cache::get(PrimeUserSync::HIGH_WATER_MARK_KEY));
    }

    // --- outage classification -------------------------------------------

    /**
     * QueryException extends PDOException, so a bare `instanceof
     * PDOException` also matches every local-DB query error. Only failures
     * on the external_users connection mean PRIME is down.
     */
    public function test_a_local_database_query_error_is_not_classified_as_prime_unreachable(): void
    {
        $local = new QueryException('sqlite', 'insert into users', [], new PDOException('UNIQUE constraint failed: users.email'));
        $prime = new QueryException('external_users', 'select', [], new PDOException('SQLSTATE[HY000] [2002] Connection refused'));

        $this->assertFalse(ExternalUserAuthService::isUnreachable($local));
        $this->assertTrue(ExternalUserAuthService::isUnreachable($prime));
    }
}
