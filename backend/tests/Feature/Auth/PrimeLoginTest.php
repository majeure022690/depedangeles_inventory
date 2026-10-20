<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PrimeLoginTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_active_prime_user_logs_in_and_is_synced_locally(): void
    {
        $this->primeUser();

        $this->login()->assertOk()->assertJsonPath('requires_2fa_setup', true);

        $user = User::where('email', 'juan@deped.gov.ph')->firstOrFail();
        $this->assertSame('Juan Dela Cruz', $user->name);
        $this->assertTrue($user->is_active);
    }

    public function test_sync_keeps_the_local_office(): void
    {
        $this->primeUser();
        $local = User::factory()->create(['email' => 'juan@deped.gov.ph']);
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
        $user = User::factory()->create(['password' => Hash::make('LocalPass123!')]);

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
}
