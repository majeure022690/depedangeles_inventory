<?php

namespace Tests\Feature\Auth;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\AllowsLocalLogin;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use AllowsLocalLogin, CreatesUsersWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => Hash::make('CorrectHorse123!')])->givePermissionTo(Permission::EquipmentView->value);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'CorrectHorse123!',
        ]);

        $response->assertOk();
        $response->assertJsonPath('requires_2fa_setup', true);
        $response->assertJsonStructure(['setup_token']);
        $this->assertGuest();
    }

    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        $user = User::factory()->create(['password' => Hash::make('CorrectHorse123!')]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.email.0', 'Invalid credentials.');
        $this->assertGuest();
    }

    public function test_login_does_not_reveal_whether_account_exists(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.email.0', 'Invalid credentials.');
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->inactive()->create(['password' => Hash::make('CorrectHorse123!')]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'CorrectHorse123!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.email.0', 'Invalid credentials.');
        $this->assertGuest();
    }

    public function test_authenticated_user_can_fetch_profile_with_roles_permissions_and_office(): void
    {
        $office = Office::factory()->create(['office_name' => 'Angeles City National High School']);
        $user = $this->userWithPermissions(
            [Permission::PersonnelView, Permission::EquipmentView, Permission::StakeholderProfileView, Permission::InternetConnectivityView],
            ['office_id' => $office->id],
            DataScope::Own,
        );

        $response = $this->actingAs($user)->getJson('/api/user');

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.office_id', $office->id)
            ->assertJsonPath('data.office.office_name', 'Angeles City National High School')
            ->assertJsonPath('data.office.school_id', $office->fresh()->school_id)
            ->assertJsonPath('data.roles', [$user->roles->first()->name])
            ->assertJsonPath('data.data_scope', 'own')
            ->assertJsonPath('data.role_ids', [$user->roles->first()->id])
            ->assertJsonPath('data.is_self', true)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.two_factor_enabled', false)
            ->assertJsonPath('data.avatar_url', null)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.two_factor_secret')
            ->assertJsonMissingPath('data.two_factor_recovery_codes');

        $this->assertEqualsCanonicalizing(
            ['personnel.view', 'equipment.view', 'stakeholder_profile.view', 'internet_connectivity.view'],
            $response->json('data.permissions'),
        );
    }

    public function test_user_without_permissions_cannot_log_in(): void
    {
        $user = User::factory()->create(['password' => Hash::make('CorrectHorse123!')]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'CorrectHorse123!'])
            ->assertForbidden()
            ->assertJsonPath('code', 'no_access')
            ->assertJsonMissingPath('setup_token')
            ->assertJsonMissingPath('two_fa_token');

        $this->assertGuest();
    }

    public function test_user_whose_roles_are_all_removed_is_signed_out_on_reload(): void
    {
        $user = $this->userWithPermissions([Permission::EquipmentView], [], DataScope::Own);

        $this->actingAs($user)->getJson('/api/user')->assertOk();

        $user->fresh()->syncRoles([]);
        $this->app['auth']->forgetGuards();

        $this->actingAs($user->fresh())->getJson('/api/user')
            ->assertForbidden()
            ->assertExactJson([
                'code' => 'no_access',
                'message' => "Your account doesn't have access to DICTIS yet. Please contact the Division ICT Unit.",
            ]);

        $this->assertGuest('web');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_unauthenticated_user_cannot_fetch_profile(): void
    {
        $this->getJson('/api/user')->assertStatus(401);
    }

    public function test_user_can_logout(): void
    {
        // Each request below stands in for a separate browser request
        // sharing a session cookie; forgetGuards() makes the 'web' guard
        // re-read each new request's session instead of reusing the
        // instance bound to the first one.
        $user = User::factory()->create(['password' => Hash::make('CorrectHorse123!')])->givePermissionTo(Permission::EquipmentView->value);

        $setupToken = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'CorrectHorse123!',
        ])->assertOk()->json('setup_token');

        $secret = $this->postJson('/api/2fa/setup', ['setup_token' => $setupToken])
            ->assertOk()->json('data.secret');

        $confirmResponse = $this->postJson('/api/2fa/setup/confirm', [
            'setup_token' => $setupToken,
            'code' => (new Google2FA)->getCurrentOtp($secret),
        ])->assertOk();

        $sessionCookie = $confirmResponse->getCookie(config('session.cookie'), false);
        $this->assertNotNull($sessionCookie, 'Login response did not set a session cookie.');

        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie(config('session.cookie'), $sessionCookie->getValue())
            ->postJson('/api/logout')
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie(config('session.cookie'), $sessionCookie->getValue())
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    public function test_api_responses_carry_security_headers(): void
    {
        $this->getJson('/api/user')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Permissions-Policy', 'geolocation=(), camera=(), microphone=()');
    }
}
