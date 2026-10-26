<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DeveloperAdminSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DeveloperAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', DeveloperAdminSeeder::EMAIL)->sole();
    }

    public function test_admin_is_seeded_without_a_password_even_when_allowlisted(): void
    {
        config(['auth.local_login_emails' => [DeveloperAdminSeeder::EMAIL]]);

        $this->artisan('db:seed', ['--class' => DeveloperAdminSeeder::class])
            ->expectsOutputToContain('php artisan users:set-password '.DeveloperAdminSeeder::EMAIL)
            ->assertSuccessful();

        $this->assertNull($this->admin()->password);
        $this->assertTrue($this->admin()->hasRole('super_administrator'));
    }

    public function test_reseeding_keeps_an_allowlisted_admins_existing_password(): void
    {
        User::factory()->create(['email' => DeveloperAdminSeeder::EMAIL, 'password' => Hash::make('SetByConsole123!')]);
        config(['auth.local_login_emails' => [DeveloperAdminSeeder::EMAIL]]);

        $this->seed(DeveloperAdminSeeder::class);

        $this->assertTrue(Hash::check('SetByConsole123!', $this->admin()->password));
    }

    public function test_admin_outside_the_allowlist_is_seeded_without_a_password(): void
    {
        config(['auth.local_login_emails' => []]);

        $this->seed(DeveloperAdminSeeder::class);

        $this->assertNull($this->admin()->password);
        $this->assertTrue($this->admin()->hasRole('super_administrator'));
    }

    public function test_reseeding_outside_the_allowlist_removes_an_existing_local_password(): void
    {
        User::factory()->create(['email' => DeveloperAdminSeeder::EMAIL, 'password' => Hash::make('OldLocal123!')]);
        config(['auth.local_login_emails' => []]);

        $this->seed(DeveloperAdminSeeder::class);

        $this->assertNull($this->admin()->password);
        $this->assertSame(1, User::where('email', DeveloperAdminSeeder::EMAIL)->count());
    }
}
