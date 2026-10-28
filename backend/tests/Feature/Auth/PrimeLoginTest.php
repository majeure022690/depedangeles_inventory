<?php

namespace Tests\Feature\Auth;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\User;
use App\Rules\CurrentPassword;
use App\Services\ExternalUserAuthService;
use App\Services\PrimeUnreachable;
use App\Services\PrimeUserSync;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Timebox;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\Concerns\FakesPrime;
use Tests\TestCase;

class PrimeLoginTest extends TestCase
{
    use CreatesUsersWithRoles, FakesPrime, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->fakePrime();
    }

    private function primeUser(string $status = 'Active', string $password = 'PrimePass123!'): void
    {
        DB::connection('external_users')->table('users')->insert([
            'full_name' => 'Juan Dela Cruz',
            'email_address' => 'juan@deped.gov.ph',
            'password' => Hash::make($password),
            'user_status' => $status,
        ]);
    }

    private function login(string $password = 'PrimePass123!')
    {
        return $this->postJson('/api/login', ['email' => 'juan@deped.gov.ph', 'password' => $password]);
    }

    public function test_first_prime_login_without_permissions_is_refused_but_synced_locally(): void
    {
        $this->primeUser();

        $this->login()
            ->assertForbidden()
            ->assertExactJson([
                'code' => 'no_access',
                'message' => "Your account doesn't have access to DICTIS yet. Please contact the Division ICT Unit.",
            ]);

        $this->assertGuest();
        $this->assertFalse(session()->has(auth()->guard('web')->getName()));

        $user = User::where('email', 'juan@deped.gov.ph')->firstOrFail();
        $this->assertSame('Juan Dela Cruz', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->hasTwoFactorEnabled());

        $activity = Activity::where('event', 'auth.no_access')->sole();
        $this->assertSame($user->id, $activity->subject_id);
        $this->assertSame('login', $activity->properties['stage']);
    }

    public function test_prime_login_proceeds_to_two_factor_once_a_role_is_granted(): void
    {
        $this->primeUser();
        $this->login()->assertForbidden();

        $this->grantPermissions(User::where('email', 'juan@deped.gov.ph')->firstOrFail(), [Permission::EquipmentView], DataScope::Own);

        $this->login()->assertOk()->assertJsonPath('requires_2fa_setup', true)->assertJsonStructure(['setup_token']);
        $this->assertGuest();
    }

    public function test_sync_keeps_the_local_office(): void
    {
        $this->primeUser();
        $local = User::factory()->create(['email' => 'juan@deped.gov.ph'])->givePermissionTo(Permission::EquipmentView->value);
        $officeId = $local->office_id;

        $this->login()->assertOk();

        $this->assertSame($officeId, $local->fresh()->office_id);
    }

    public function test_wrong_prime_password_does_not_fall_back_to_the_local_copy(): void
    {
        $this->primeUser(password: 'NewPrimePass123!');
        User::factory()->create(['email' => 'juan@deped.gov.ph', 'password' => Hash::make('PrimePass123!')]);

        $this->login()->assertStatus(422)->assertJsonPath('errors.email.0', 'Invalid credentials.');
        $this->assertGuest();
    }

    public function test_deactivated_prime_user_is_refused_and_deactivated_locally(): void
    {
        $this->primeUser(status: 'Deactivated');
        $local = User::factory()->create(['email' => 'juan@deped.gov.ph', 'password' => Hash::make('PrimePass123!')]);

        $this->login()->assertStatus(422)->assertJsonPath('errors.email.0', 'Invalid credentials.');
        $this->assertFalse($local->fresh()->is_active);
        $this->assertGuest();
    }

    public function test_prime_login_does_not_store_a_password_hash_locally(): void
    {
        $this->primeUser();
        $existing = User::factory()->create(['email' => 'other@deped.gov.ph']);
        DB::connection('external_users')->table('users')->insert([
            'full_name' => 'Other', 'email_address' => 'other@deped.gov.ph', 'password' => Hash::make('OtherPass123!'), 'user_status' => 'Active',
        ]);
        $originalHash = $existing->password;

        $this->login()->assertForbidden();
        $this->postJson('/api/login', ['email' => 'other@deped.gov.ph', 'password' => 'OtherPass123!'])->assertForbidden();

        $this->assertNull(User::where('email', 'juan@deped.gov.ph')->value('password'));
        $this->assertSame($originalHash, $existing->fresh()->password);
    }

    public function test_sync_command_creates_accounts_without_a_password(): void
    {
        $this->primeUser();

        $this->artisan('users:sync-external')->assertSuccessful();

        $this->assertNull(User::where('email', 'juan@deped.gov.ph')->value('password'));
    }

    public function test_allowlisted_account_not_in_prime_logs_in_locally_and_still_needs_two_factor(): void
    {
        $user = $this->localAccount(allowlisted: true);

        $log = $this->captureSecurityLog(fn () => $this->postJson('/api/login', ['email' => $user->email, 'password' => 'LocalPass123!'])
            ->assertOk()
            ->assertJsonPath('requires_2fa_setup', true));

        $this->assertGuest();
        $this->assertStringContainsString('Local break-glass login', $log);
        $this->assertStringContainsString('"reason":"not_in_prime"', $log);
    }

    public function test_allowlisted_account_logs_in_locally_when_prime_is_down_and_still_needs_two_factor(): void
    {
        $user = $this->localAccount(allowlisted: true, twoFactor: true);
        $this->breakPrime();

        $log = $this->captureSecurityLog(fn () => $this->postJson('/api/login', ['email' => $user->email, 'password' => 'LocalPass123!'])
            ->assertOk()
            ->assertJsonPath('requires_2fa', true));

        $this->assertGuest();
        $this->assertStringContainsString('Local break-glass login', $log);
        $this->assertStringContainsString('"reason":"prime_unreachable"', $log);
        $this->assertTrue(Cache::has(ExternalUserAuthService::UNREACHABLE_KEY));
    }

    public function test_allowlist_matches_case_insensitively(): void
    {
        $user = $this->localAccount(allowlisted: false);
        config(['auth.local_login_emails' => [' DEV@Example.TEST ']]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'LocalPass123!'])->assertOk();
    }

    public function test_local_password_is_refused_when_prime_is_down_and_not_allowlisted(): void
    {
        $user = $this->localAccount(allowlisted: false);
        $this->breakPrime();

        $log = $this->captureSecurityLog(fn () => $this->postJson('/api/login', ['email' => $user->email, 'password' => 'LocalPass123!'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Invalid credentials.'));

        $this->assertGuest();
        $this->assertStringContainsString('Failed login attempt', $log);
        $this->assertStringNotContainsString('Local break-glass login', $log);
    }

    public function test_disabled_prime_is_never_dialled_and_counts_as_unreachable(): void
    {
        config(['auth.prime_enabled' => false]);
        $dialled = false;

        try {
            ExternalUserAuthService::guard(function () use (&$dialled) {
                $dialled = true;
            });
            $this->fail('A disabled PRIME should count as unreachable.');
        } catch (PrimeUnreachable) {
            // Expected.
        }

        $this->assertFalse($dialled);
    }

    public function test_with_prime_disabled_an_allowlisted_account_logs_in_locally(): void
    {
        config(['auth.prime_enabled' => false]);
        $user = $this->localAccount(allowlisted: true, twoFactor: true);

        $log = $this->captureSecurityLog(fn () => $this->postJson('/api/login', ['email' => $user->email, 'password' => 'LocalPass123!'])
            ->assertOk()
            ->assertJsonPath('requires_2fa', true));

        $this->assertStringContainsString('"reason":"prime_disabled"', $log);
    }

    public function test_with_prime_disabled_a_non_allowlisted_account_is_refused(): void
    {
        config(['auth.prime_enabled' => false]);
        $user = $this->localAccount(allowlisted: false);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'LocalPass123!'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Invalid credentials.');
        $this->assertGuest();
    }

    public function test_local_password_is_refused_for_an_account_prime_does_not_know_unless_allowlisted(): void
    {
        $user = $this->localAccount(allowlisted: false);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'LocalPass123!'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Invalid credentials.');
        $this->assertGuest();
    }

    public function test_prime_refusal_never_falls_back_even_for_an_allowlisted_account(): void
    {
        $this->primeUser(password: 'NewPrimePass123!');
        User::factory()->create(['email' => 'juan@deped.gov.ph', 'password' => Hash::make('PrimePass123!')])->givePermissionTo(Permission::EquipmentView->value);
        config(['auth.local_login_emails' => ['juan@deped.gov.ph']]);

        $this->login()->assertStatus(422)->assertJsonPath('errors.email.0', 'Invalid credentials.');
        $this->assertGuest();
    }

    public function test_an_email_matching_several_prime_rows_is_refused_without_fallback(): void
    {
        $this->primeUser();
        $this->primeUser();
        User::factory()->create(['email' => 'juan@deped.gov.ph', 'password' => Hash::make('PrimePass123!')])->givePermissionTo(Permission::EquipmentView->value);
        config(['auth.local_login_emails' => ['juan@deped.gov.ph']]);

        $log = $this->captureSecurityLog(fn () => $this->login()
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Invalid credentials.'));

        $this->assertGuest();
        $this->assertStringContainsString('Ambiguous PRIME account', $log);
        $this->assertStringNotContainsString('Local break-glass login', $log);
    }

    public function test_an_unexpected_prime_error_is_a_refusal_not_an_outage(): void
    {
        // A non-bcrypt PRIME hash makes Hash::check throw — not a connection failure.
        $this->prime('users')->insert(['full_name' => 'Dev', 'email_address' => 'dev@example.test', 'password' => 'not-a-bcrypt-hash', 'user_status' => 'Active']);
        $user = $this->localAccount(allowlisted: true);

        $log = $this->captureSecurityLog(fn () => $this->postJson('/api/login', ['email' => $user->email, 'password' => 'LocalPass123!'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Invalid credentials.'));

        $this->assertGuest();
        $this->assertStringContainsString('PRIME authentication failed unexpectedly; refusing', $log);
        $this->assertStringNotContainsString('Local break-glass login', $log);
    }

    public function test_failed_logins_take_the_same_minimum_time(): void
    {
        $this->app->bind(Timebox::class, fn () => new Timebox);
        $this->primeUser(status: 'Deactivated');

        foreach (['nobody@example.test', 'juan@deped.gov.ph'] as $email) {
            $start = microtime(true);
            $this->postJson('/api/login', ['email' => $email, 'password' => 'whatever'])->assertStatus(422);
            $this->assertGreaterThanOrEqual(0.39, microtime(true) - $start, $email);
        }
    }

    private function localAccount(bool $allowlisted, bool $twoFactor = false): User
    {
        $factory = User::factory();
        $user = ($twoFactor ? $factory->twoFactorConfirmed() : $factory)
            ->create(['email' => 'dev@example.test', 'password' => Hash::make('LocalPass123!')])
            ->givePermissionTo(Permission::EquipmentView->value);

        config(['auth.local_login_emails' => $allowlisted ? ['dev@example.test'] : []]);

        return $user;
    }

    private function captureSecurityLog(callable $request): string
    {
        $path = storage_path('logs/test-prime-login-security.log');
        @unlink($path);
        config(['logging.channels.security' => ['driver' => 'single', 'path' => $path, 'level' => 'debug']]);
        Log::forgetChannel('security');

        try {
            $request();

            return (string) @file_get_contents($path);
        } finally {
            Log::forgetChannel('security');
            @unlink($path);
        }
    }

    public function test_sync_command_imports_active_users_and_deactivates_refused_ones(): void
    {
        DB::connection('external_users')->table('users')->insert([
            ['full_name' => 'Active One', 'email_address' => 'a@deped.gov.ph', 'password' => Hash::make('x'), 'user_status' => 'Active'],
            ['full_name' => 'Gone Two', 'email_address' => 'b@deped.gov.ph', 'password' => Hash::make('x'), 'user_status' => 'Deactivated'],
            ['full_name' => 'Renamed Three', 'email_address' => 'c@deped.gov.ph', 'password' => Hash::make('x'), 'user_status' => 'Deactivated'],
        ]);
        $existing = User::factory()->create(['email' => 'c@deped.gov.ph', 'name' => 'Old Name']);

        $this->artisan('users:sync-external')->assertSuccessful();

        $this->assertTrue(User::where('email', 'a@deped.gov.ph')->value('is_active'));
        $this->assertFalse(User::where('email', 'b@deped.gov.ph')->exists());
        $this->assertSame('Renamed Three', $existing->fresh()->name);
        $this->assertFalse($existing->fresh()->is_active);
    }

    private function listAdmin(): User
    {
        return $this->userWithPermissions([Permission::UsersView], [], DataScope::All);
    }

    public function test_users_page_refreshes_visible_rows_from_prime_live(): void
    {
        $admin = $this->listAdmin();
        $this->primeAccount('juan@deped.gov.ph', name: 'Juan Dela Cruz');
        $this->primeAccount('gone@deped.gov.ph', status: 'Deactivated', name: 'Gone Person');
        $renamed = User::factory()->create(['email' => 'JUAN@deped.gov.ph', 'name' => 'Old Name']);
        $gone = User::factory()->create(['email' => 'gone@deped.gov.ph', 'name' => 'Gone Person']);

        $this->actingAs($admin)->getJson('/api/users?search=deped.gov.ph&sort=name&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.0.email', 'gone@deped.gov.ph')
            ->assertJsonPath('data.0.is_active', false)
            ->assertJsonPath('data.1.name', 'Juan Dela Cruz');

        $this->assertSame('Juan Dela Cruz', $renamed->fresh()->name);
        $this->assertFalse($gone->fresh()->is_active);

        $renameLog = Activity::query()->whereMorphedTo('subject', $renamed)->where('description', 'Synced from PRIME')->sole();
        $this->assertNull($renameLog->causer_id);
        $this->assertSame(['name' => 'Juan Dela Cruz'], $renameLog->attribute_changes['attributes']);
        $this->assertSame(1, Activity::query()->whereMorphedTo('subject', $gone)->where('description', 'Synced from PRIME')->whereNull('causer_id')->count());
    }

    public function test_users_page_does_not_reactivate_or_resave_unchanged_rows(): void
    {
        $admin = $this->listAdmin();
        $this->primeAccount('juan@deped.gov.ph', name: 'Juan Dela Cruz');
        $local = User::factory()->create(['email' => 'juan@deped.gov.ph', 'name' => 'Juan Dela Cruz', 'is_active' => false]);

        $this->actingAs($admin)->getJson('/api/users?search=juan')->assertOk()->assertJsonPath('data.0.is_active', false);

        $this->assertFalse($local->fresh()->is_active);
        $this->assertSame(0, Activity::query()->where('description', 'Synced from PRIME')->count());
    }

    public function test_users_page_leaves_rows_off_the_current_page_untouched(): void
    {
        $admin = $this->listAdmin();
        $this->primeAccount('aaa@deped.gov.ph', name: 'Renamed A');
        $this->primeAccount('zzz@deped.gov.ph', status: 'Deactivated', name: 'Renamed Z');
        $onPage = User::factory()->create(['email' => 'aaa@deped.gov.ph', 'name' => 'Old A']);
        $offPage = User::factory()->create(['email' => 'zzz@deped.gov.ph', 'name' => 'Old Z']);

        $this->actingAs($admin)->getJson('/api/users?search=deped.gov.ph&sort=email&direction=asc&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Renamed A');

        $this->assertSame('Renamed A', $onPage->fresh()->name);
        $this->assertSame('Old Z', $offPage->fresh()->name);
        $this->assertTrue($offPage->fresh()->is_active);
    }

    public function test_first_users_page_load_sets_the_high_water_mark_without_importing_the_directory(): void
    {
        $admin = $this->listAdmin();
        $this->primeAccount('one@deped.gov.ph');
        $top = $this->primeAccount('two@deped.gov.ph');

        $this->actingAs($admin)->getJson('/api/users')->assertOk();

        $this->assertSame($top, Cache::get(PrimeUserSync::HIGH_WATER_MARK_KEY));
        $this->assertFalse(User::where('email', 'one@deped.gov.ph')->exists());
        $this->assertFalse(User::where('email', 'two@deped.gov.ph')->exists());
    }

    public function test_users_page_imports_new_prime_accounts_above_the_high_water_mark(): void
    {
        $admin = $this->listAdmin();
        $old = $this->primeAccount('old@deped.gov.ph');
        Cache::forever(PrimeUserSync::HIGH_WATER_MARK_KEY, $old);
        $this->primeAccount('refused@deped.gov.ph', status: 'Deactivated');
        $new = $this->primeAccount('new@deped.gov.ph', name: 'New Person');

        $this->actingAs($admin)->getJson('/api/users?search=new@')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'New Person')
            ->assertJsonPath('data.0.is_active', true);

        $created = User::where('email', 'new@deped.gov.ph')->sole();
        $this->assertNull($created->password);
        $this->assertFalse(User::where('email', 'old@deped.gov.ph')->exists());
        $this->assertFalse(User::where('email', 'refused@deped.gov.ph')->exists());
        $this->assertSame($new, Cache::get(PrimeUserSync::HIGH_WATER_MARK_KEY));
        $this->assertSame(1, Activity::query()->whereMorphedTo('subject', $created)->where('event', 'created')
            ->where('description', 'Synced from PRIME')->whereNull('causer_id')->count());

        $this->actingAs($admin)->getJson('/api/users')->assertOk();
        $this->assertSame(1, User::where('email', 'new@deped.gov.ph')->count());
    }

    public function test_import_skips_nameless_prime_rows_truncates_long_names_and_still_advances_the_mark(): void
    {
        Schema::connection('external_users')->table('users', fn (Blueprint $table) => $table->string('full_name')->nullable()->change());
        $admin = $this->listAdmin();
        Cache::forever(PrimeUserSync::HIGH_WATER_MARK_KEY, 0);
        $this->prime('users')->insert(['full_name' => null, 'email_address' => 'null-name@deped.gov.ph']);
        $this->primeAccount('long@deped.gov.ph', name: str_repeat('é', 300));
        $top = $this->prime('users')->insertGetId(['full_name' => '   ', 'email_address' => 'blank@deped.gov.ph']);
        Log::spy();

        $this->actingAs($admin)->getJson('/api/users')->assertOk();

        $this->assertFalse(User::where('email', 'null-name@deped.gov.ph')->exists());
        $this->assertFalse(User::where('email', 'blank@deped.gov.ph')->exists());
        $this->assertSame(str_repeat('é', 255), User::where('email', 'long@deped.gov.ph')->value('name'));
        $this->assertSame($top, Cache::get(PrimeUserSync::HIGH_WATER_MARK_KEY));
        Log::shouldHaveReceived('warning')->with('PRIME user has no name; name not synced', ['prime_user_id' => $top])->once();
    }

    public function test_visible_row_with_a_blank_prime_name_keeps_its_name_but_is_still_deactivated(): void
    {
        $admin = $this->listAdmin();
        $this->primeAccount('juan@deped.gov.ph', status: 'Deactivated', name: ' ');
        $local = User::factory()->create(['email' => 'juan@deped.gov.ph', 'name' => 'Juan Dela Cruz']);

        $this->actingAs($admin)->getJson('/api/users?search=juan')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Juan Dela Cruz')
            ->assertJsonPath('data.0.is_active', false);

        $this->assertSame('Juan Dela Cruz', $local->fresh()->name);
        $this->assertFalse($local->fresh()->is_active);
    }

    public function test_sync_command_skips_nameless_and_emailless_prime_rows(): void
    {
        $this->prime('users')->insert([
            ['full_name' => ' ', 'email_address' => 'blank@deped.gov.ph'],
            ['full_name' => 'No Email', 'email_address' => ''],
        ]);
        $this->primeAccount('ok@deped.gov.ph', name: str_repeat('a', 300));

        $this->artisan('users:sync-external')->assertSuccessful();

        $this->assertSame(['ok@deped.gov.ph'], User::pluck('email')->all());
        $this->assertSame(255, mb_strlen((string) User::where('email', 'ok@deped.gov.ph')->value('name')));
    }

    public function test_sync_command_advances_the_high_water_mark(): void
    {
        $this->primeAccount('a@deped.gov.ph');
        $top = $this->primeAccount('b@deped.gov.ph');

        $this->artisan('users:sync-external')->assertSuccessful();

        $this->assertSame($top, Cache::get(PrimeUserSync::HIGH_WATER_MARK_KEY));
    }

    public function test_current_user_picks_up_a_prime_name_change_on_reload(): void
    {
        $this->primeUser();
        $user = User::factory()->create(['email' => 'juan@deped.gov.ph', 'name' => 'Old Name'])->givePermissionTo(Permission::EquipmentView->value);

        $this->actingAs($user)->getJson('/api/user')->assertOk()->assertJsonPath('data.name', 'Juan Dela Cruz');
    }

    public function test_current_user_deactivated_in_prime_is_signed_out_on_reload(): void
    {
        $this->primeUser(status: 'Deactivated');
        $user = User::factory()->create(['email' => 'juan@deped.gov.ph'])->givePermissionTo(Permission::EquipmentView->value);

        $this->actingAs($user)->getJson('/api/user')->assertUnauthorized();
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_users_page_still_loads_when_prime_is_down(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo(Permission::UsersView->value);
        config(['database.connections.external_users' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x']]);
        DB::purge('external_users');

        $this->actingAs($admin)->getJson('/api/users')->assertOk();
    }

    public function test_users_page_shows_the_local_copy_and_logs_when_prime_is_down(): void
    {
        $admin = $this->listAdmin();
        $local = User::factory()->create(['email' => 'juan@deped.gov.ph', 'name' => 'Local Name']);
        Cache::forever(PrimeUserSync::HIGH_WATER_MARK_KEY, 5);
        $this->breakPrime();
        Log::spy();

        $this->actingAs($admin)->getJson('/api/users?search=juan')->assertOk()->assertJsonPath('data.0.name', 'Local Name');

        $this->assertSame('Local Name', $local->fresh()->name);
        $this->assertSame(5, Cache::get(PrimeUserSync::HIGH_WATER_MARK_KEY));
        $this->assertTrue(Cache::has(ExternalUserAuthService::UNREACHABLE_KEY));
        Log::shouldHaveReceived('warning')->with('PRIME user sync failed; showing the local copy', \Mockery::type('array'))->twice();
    }

    // --- PRIME-down circuit breaker -----------------------------------------

    /**
     * Fails one PRIME read so the breaker opens, then brings PRIME back with
     * different data: anything that still reads PRIME would now see it.
     */
    private function openBreakerThenRestorePrime(): void
    {
        $this->breakPrime();

        try {
            ExternalUserAuthService::guard(fn () => ExternalUserAuthService::users()->count());
            $this->fail('PRIME should have been unreachable.');
        } catch (\PDOException) {
            // Expected: the failed connect opens the breaker.
        }

        $this->assertTrue(Cache::has(ExternalUserAuthService::UNREACHABLE_KEY));
        $this->fakePrime();
    }

    public function test_a_prime_failure_opens_the_breaker_and_the_list_then_skips_prime(): void
    {
        $admin = $this->listAdmin();
        $local = User::factory()->create(['email' => 'juan@deped.gov.ph', 'name' => 'Local Name']);
        Cache::forever(PrimeUserSync::HIGH_WATER_MARK_KEY, 0);
        $this->openBreakerThenRestorePrime();
        $this->primeAccount('juan@deped.gov.ph', name: 'Prime Name');
        $this->primeAccount('new@deped.gov.ph');

        $this->actingAs($admin)->getJson('/api/users?search=juan')->assertOk()->assertJsonPath('data.0.name', 'Local Name');

        $this->assertSame('Local Name', $local->fresh()->name);
        $this->assertFalse(User::where('email', 'new@deped.gov.ph')->exists());

        $this->travel(ExternalUserAuthService::UNREACHABLE_SECONDS + 1)->seconds();

        $this->actingAs($admin)->getJson('/api/users?search=juan')->assertOk()->assertJsonPath('data.0.name', 'Prime Name');
        $this->assertTrue(User::where('email', 'new@deped.gov.ph')->exists());
    }

    public function test_current_user_reload_skips_prime_while_the_breaker_is_open(): void
    {
        $user = User::factory()->create(['email' => 'juan@deped.gov.ph', 'name' => 'Local Name'])->givePermissionTo(Permission::EquipmentView->value);
        $this->openBreakerThenRestorePrime();
        $this->primeUser(status: 'Deactivated');

        $this->actingAs($user)->getJson('/api/user')->assertOk()->assertJsonPath('data.name', 'Local Name');
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_allowlisted_break_glass_login_works_while_the_breaker_is_open(): void
    {
        $user = $this->localAccount(allowlisted: true);
        $this->openBreakerThenRestorePrime();
        // Were PRIME consulted, it would refuse this password with no fallback.
        $this->prime('users')->insert(['full_name' => 'Dev', 'email_address' => $user->email, 'password' => Hash::make('OtherPass123!'), 'user_status' => 'Active']);

        $log = $this->captureSecurityLog(fn () => $this->postJson('/api/login', ['email' => $user->email, 'password' => 'LocalPass123!'])->assertOk());

        $this->assertStringContainsString('"reason":"prime_unreachable"', $log);
    }

    public function test_other_accounts_are_refused_while_the_breaker_is_open(): void
    {
        $this->openBreakerThenRestorePrime();
        $this->primeUser();
        User::factory()->create(['email' => 'juan@deped.gov.ph'])->givePermissionTo(Permission::EquipmentView->value);

        $this->login()->assertStatus(422)->assertJsonPath('errors.email.0', 'Invalid credentials.');
        $this->assertGuest();
    }

    public function test_current_password_reports_prime_unavailable_while_the_breaker_is_open(): void
    {
        $user = User::factory()->create(['email' => 'juan@deped.gov.ph']);
        $this->openBreakerThenRestorePrime();
        $this->primeUser();

        $validator = Validator::make(['current_password' => 'PrimePass123!'], ['current_password' => [new CurrentPassword($user)]]);

        $this->assertSame(
            [__("Your password can't be checked right now because PRIME is unavailable. Please try again later.")],
            $validator->errors()->get('current_password'),
        );
    }

    public function test_the_full_sync_still_tries_prime_while_the_breaker_is_open_and_opens_it_on_failure(): void
    {
        Cache::put(ExternalUserAuthService::UNREACHABLE_KEY, true, ExternalUserAuthService::UNREACHABLE_SECONDS);
        $this->primeAccount('a@deped.gov.ph');

        $this->artisan('users:sync-external')->assertSuccessful();
        $this->assertTrue(User::where('email', 'a@deped.gov.ph')->exists());

        Cache::forget(ExternalUserAuthService::UNREACHABLE_KEY);
        $this->breakPrime();

        $this->artisan('users:sync-external')->assertFailed();
        $this->assertTrue(Cache::has(ExternalUserAuthService::UNREACHABLE_KEY));
    }

    /**
     * A PRIME data/schema error (stand-in for e.g. MySQL 1267 illegal mix of
     * collations) is not an outage: it must not open the breaker.
     */
    private function makePrimeQueriesFail(): void
    {
        Schema::connection('external_users')->drop('users');
    }

    public function test_a_prime_query_error_during_login_refuses_without_break_glass_or_breaker(): void
    {
        $user = $this->localAccount(allowlisted: true);
        $this->makePrimeQueriesFail();

        $log = $this->captureSecurityLog(fn () => $this->postJson('/api/login', ['email' => $user->email, 'password' => 'LocalPass123!'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Invalid credentials.'));

        $this->assertGuest();
        $this->assertFalse(Cache::has(ExternalUserAuthService::UNREACHABLE_KEY));
        $this->assertStringContainsString('PRIME query failed; refusing', $log);
        $this->assertStringNotContainsString('Local break-glass login', $log);
    }

    public function test_a_prime_query_error_on_the_list_shows_the_local_copy_without_opening_the_breaker(): void
    {
        $admin = $this->listAdmin();
        User::factory()->create(['email' => 'juan@deped.gov.ph', 'name' => 'Local Name']);
        Cache::forever(PrimeUserSync::HIGH_WATER_MARK_KEY, 0);
        $this->makePrimeQueriesFail();

        $this->actingAs($admin)->getJson('/api/users?search=juan')->assertOk()->assertJsonPath('data.0.name', 'Local Name');

        $this->assertFalse(Cache::has(ExternalUserAuthService::UNREACHABLE_KEY));
    }

    public function test_a_prime_query_error_on_reload_keeps_the_local_copy_without_opening_the_breaker(): void
    {
        $user = User::factory()->create(['email' => 'juan@deped.gov.ph', 'name' => 'Local Name'])->givePermissionTo(Permission::EquipmentView->value);
        $this->makePrimeQueriesFail();

        $this->actingAs($user)->getJson('/api/user')->assertOk()->assertJsonPath('data.name', 'Local Name');

        $this->assertFalse(Cache::has(ExternalUserAuthService::UNREACHABLE_KEY));
    }

    public function test_only_connection_failures_count_as_prime_unreachable(): void
    {
        $query = fn (string $message, ?array $errorInfo = null) => new QueryException('external_users', 'select', [], tap(new \PDOException($message), function (\PDOException $e) use ($errorInfo) {
            $e->errorInfo = $errorInfo;
        }));

        $this->assertTrue(ExternalUserAuthService::isUnreachable($query('SQLSTATE[HY000] [2002] No connection could be made because the target machine actively refused it')));
        $this->assertTrue(ExternalUserAuthService::isUnreachable($query('SQLSTATE[HY000] [2003] Can\'t connect to MySQL server')));
        $this->assertTrue(ExternalUserAuthService::isUnreachable($query('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', ['HY000', 2006, 'MySQL server has gone away'])));
        $this->assertTrue(ExternalUserAuthService::isUnreachable($query('SQLSTATE[08S01]: Communication link failure', ['08S01', 2013, 'Lost connection'])));

        $collation = $query('SQLSTATE[HY000]: General error: 1267 Illegal mix of collations (latin1_swedish_ci,IMPLICIT) and (utf8mb4_unicode_ci,COERCIBLE)', ['HY000', 1267, 'Illegal mix of collations']);
        $this->assertFalse(ExternalUserAuthService::isUnreachable($collation));
        $this->assertTrue(ExternalUserAuthService::isQueryError($collation));
        $this->assertFalse(ExternalUserAuthService::isUnreachable($query("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'x'", ['42S22', 1054, 'Unknown column'])));
    }

    public function test_a_non_ascii_login_email_is_rejected_before_prime_is_queried(): void
    {
        $this->breakPrime();

        $this->postJson('/api/login', ['email' => "juan\u{1F600}@deped.gov.ph", 'password' => 'whatever'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertFalse(Cache::has(ExternalUserAuthService::UNREACHABLE_KEY));
    }

    // --- duplicate PRIME emails ---------------------------------------------

    public function test_users_page_skips_a_visible_row_whose_email_is_on_several_prime_rows(): void
    {
        $admin = $this->listAdmin();
        $this->primeAccount('juan@deped.gov.ph', name: 'First Juan');
        // Same case: the fake PRIME is SQLite, whose IN is case-sensitive (PRIME's MySQL collation isn't).
        $this->primeAccount('juan@deped.gov.ph', status: 'Deactivated', name: 'Second Juan');
        $local = User::factory()->create(['email' => 'juan@deped.gov.ph', 'name' => 'Local Name']);

        $log = $this->captureSecurityLog(fn () => $this->actingAs($admin)->getJson('/api/users?search=juan')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Local Name'));

        $this->assertTrue($local->fresh()->is_active);
        $this->assertStringContainsString('Ambiguous PRIME account: several rows share an email', $log);
    }

    public function test_import_skips_a_new_prime_row_whose_email_is_also_on_an_older_row(): void
    {
        $admin = $this->listAdmin();
        $this->primeAccount('twin@deped.gov.ph', status: 'Deactivated');
        $mark = $this->primeAccount('other@deped.gov.ph');
        Cache::forever(PrimeUserSync::HIGH_WATER_MARK_KEY, $mark);
        $this->primeAccount('Twin@deped.gov.ph', name: 'New Twin');
        $top = $this->primeAccount('fresh@deped.gov.ph');

        $log = $this->captureSecurityLog(fn () => $this->actingAs($admin)->getJson('/api/users')->assertOk());

        $this->assertFalse(User::whereRaw('LOWER(email) = ?', ['twin@deped.gov.ph'])->exists());
        $this->assertTrue(User::where('email', 'fresh@deped.gov.ph')->exists());
        $this->assertSame($top, Cache::get(PrimeUserSync::HIGH_WATER_MARK_KEY));
        $this->assertStringContainsString('Ambiguous PRIME account', $log);
    }

    public function test_sync_command_and_reload_skip_an_email_on_several_prime_rows(): void
    {
        $this->primeAccount('twin@deped.gov.ph', name: 'First');
        $this->primeAccount('twin@deped.gov.ph', status: 'Deactivated', name: 'Second');
        $this->primeAccount('ok@deped.gov.ph');

        $this->artisan('users:sync-external')->assertSuccessful();

        $this->assertFalse(User::where('email', 'twin@deped.gov.ph')->exists());
        $this->assertTrue(User::where('email', 'ok@deped.gov.ph')->exists());

        $user = User::factory()->create(['email' => 'twin@deped.gov.ph', 'name' => 'Local'])->givePermissionTo(Permission::EquipmentView->value);
        $this->actingAs($user)->getJson('/api/user')->assertOk()->assertJsonPath('data.name', 'Local');
        $this->assertTrue($user->fresh()->is_active);
    }
}
