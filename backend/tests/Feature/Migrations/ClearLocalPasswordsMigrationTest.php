<?php

namespace Tests\Feature\Migrations;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClearLocalPasswordsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_27_000004_clear_local_passwords_outside_allowlist.php');
    }

    public function test_clears_every_password_except_allowlisted_accounts_and_keeps_the_rows(): void
    {
        $developer = User::factory()->create(['email' => 'Dev@Example.test', 'password' => Hash::make('DevPass123!')]);
        $copied = User::factory()->create(['email' => 'teacher@deped.gov.ph']);
        $alreadyEmpty = User::factory()->create(['password' => null]);
        config(['auth.local_login_emails' => ['dev@example.test']]);

        $this->migration()->up();

        $this->assertTrue(Hash::check('DevPass123!', $developer->fresh()->password));
        $this->assertNull($copied->fresh()->password);
        $this->assertNull($alreadyEmpty->fresh()->password);
        $this->assertSame(3, User::count());
    }

    public function test_an_empty_allowlist_clears_every_password(): void
    {
        $users = User::factory()->count(2)->create();
        config(['auth.local_login_emails' => []]);

        $this->migration()->up();

        $this->assertSame(0, User::query()->whereNotNull('password')->count());
        $this->assertSame(2, User::count());
        $users->each(fn (User $user) => $this->assertNotNull($user->fresh()));
    }

    public function test_down_is_a_no_op(): void
    {
        $user = User::factory()->create(['password' => null]);

        $this->migration()->down();

        $this->assertNull($user->fresh()->password);
    }
}
