<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\EnforceIdleTimeout;
use App\Models\User;
use App\Services\SecuritySettings;
use App\Services\TwoFactorAuthService;
use App\Services\UserRoleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

/**
 * Deactivation and an admin 2FA reset end live sessions; idle sessions
 * are ended server-side after idle_timeout_seconds.
 */
class SessionTerminationTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function storeSessionFor(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode('x'),
            'last_activity' => now()->getTimestamp(),
        ]);
    }

    public function test_deactivating_a_user_deletes_their_database_sessions(): void
    {
        $target = $this->userWithRole('viewer');
        $other = $this->userWithRole('viewer');
        $this->storeSessionFor($target, 'target-session');
        $this->storeSessionFor($other, 'other-session');

        app(UserRoleService::class)->updateUser(
            $this->userWithRole('admin'),
            $target,
            ['name' => $target->name, 'email' => $target->email, 'is_active' => false],
            $target->roles()->pluck('roles.id')->all(),
        );

        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session']);
    }

    public function test_an_admin_two_factor_reset_deletes_the_users_database_sessions(): void
    {
        $tfa = new TwoFactorAuthService;
        $target = $this->userWithRole('viewer', [
            'two_factor_secret' => $tfa->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->storeSessionFor($target, 'target-session');

        app(UserRoleService::class)->resetTwoFactor($this->userWithRole('admin'), $target);

        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
    }

    public function test_an_unrelated_save_keeps_the_users_sessions(): void
    {
        $user = $this->userWithRole('viewer');
        $this->storeSessionFor($user, 'kept');

        $user->update(['name' => 'Renamed']);

        $this->assertDatabaseHas('sessions', ['id' => 'kept']);
    }

    public function test_a_deactivated_user_with_a_live_session_gets_401_and_is_logged_out(): void
    {
        $user = $this->userWithRole('admin');

        $this->actingAs($user)->getJson('/api/user')->assertOk();

        // Simulates a non-database session driver: the row isn't deleted,
        // the flag flips underneath an open session.
        User::whereKey($user->id)->update(['is_active' => false]);
        $user->refresh();

        $this->getJson('/api/user')->assertUnauthorized();
        $this->getJson('/api/dashboard/summary')->assertUnauthorized();
        $this->assertGuest('web');
    }

    public function test_an_idle_session_is_ended_server_side(): void
    {
        SecuritySettings::update('idle_timeout_seconds', 60);
        $user = $this->userWithRole('admin');

        $this->actingAs($user)->getJson('/api/user')->assertOk();

        $this->travel(59)->seconds();
        $this->getJson('/api/user')->assertOk();

        $this->travel(61)->seconds();
        $this->getJson('/api/user')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Your session has expired due to inactivity.');
        $this->assertGuest('web');
    }

    public function test_activity_within_the_timeout_keeps_the_session_alive(): void
    {
        SecuritySettings::update('idle_timeout_seconds', 60);
        $user = $this->userWithRole('admin');

        $this->actingAs($user)->getJson('/api/session-settings')->assertOk();

        foreach (range(1, 4) as $ignored) {
            $this->travel(50)->seconds();
            $this->getJson('/api/session-settings')->assertOk();
        }

        $this->assertIsInt(session(EnforceIdleTimeout::SESSION_KEY));
    }
}
