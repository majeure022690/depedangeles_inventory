<?php

namespace Tests\Feature\Auth;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class PrimeLoginTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        config(['database.connections.external_users' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('external_users');

        Schema::connection('external_users')->create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('full_name');
            $table->string('email_address');
            $table->string('password');
            $table->string('user_status');
        });
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

    public function test_local_only_account_still_logs_in(): void
    {
        $user = User::factory()->create(['password' => Hash::make('LocalPass123!')])->givePermissionTo(Permission::EquipmentView->value);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'LocalPass123!'])->assertOk();
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

    public function test_users_page_reflects_prime_changes_on_load(): void
    {
        $admin = $this->userWithPermissions([Permission::UsersView], [], DataScope::All);
        $this->primeUser();
        $local = User::factory()->create(['email' => 'juan@deped.gov.ph', 'name' => 'Old Name']);

        $this->actingAs($admin)->getJson('/api/users?search=juan')->assertOk()->assertJsonPath('data.0.name', 'Juan Dela Cruz');

        $activity = Activity::where('subject_id', $local->id)->latest('id')->firstOrFail();
        $this->assertSame('Synced from PRIME', $activity->description);
        $this->assertNull($activity->causer_id);
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
}
