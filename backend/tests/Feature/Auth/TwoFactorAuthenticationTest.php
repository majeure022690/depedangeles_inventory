<?php

namespace Tests\Feature\Auth;

use App\Enums\Permission;
use App\Http\Controllers\Api\Auth\TwoFactorController;
use App\Models\User;
use App\Services\TrustedDeviceService;
use App\Services\TwoFactorAuthService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function loginAndGetSetupToken(User $user): string
    {
        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'CorrectHorse123!',
        ])->assertOk()->assertJsonPath('requires_2fa_setup', true);

        return $response->json('setup_token');
    }

    private function currentCodeFor(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }

    private function createEnrolledUser(string $secret, ?array $recoveryCodes = null): User
    {
        $tfa = new TwoFactorAuthService;

        return User::factory()->create([
            'password' => Hash::make('CorrectHorse123!'),
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $tfa->hashRecoveryCodes($recoveryCodes ?? $tfa->generateRecoveryCodes()),
            'two_factor_confirmed_at' => now(),
        ])->givePermissionTo(Permission::EquipmentView->value);
    }

    private function loginAndGetVerifyToken(User $user): string
    {
        return $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'CorrectHorse123!',
        ])->assertOk()->assertJsonPath('requires_2fa', true)->json('two_fa_token');
    }

    public function test_new_user_must_complete_two_factor_setup_before_a_session_is_established(): void
    {
        $user = User::factory()->create(['password' => Hash::make('CorrectHorse123!')])->givePermissionTo(Permission::EquipmentView->value);

        $setupToken = $this->loginAndGetSetupToken($user);

        $setupResponse = $this->postJson('/api/2fa/setup', ['setup_token' => $setupToken])->assertOk();
        $secret = $setupResponse->json('data.secret');
        $this->assertNotEmpty($secret);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $setupResponse->json('data.qr_code'));
        $this->assertGuest();

        $confirmResponse = $this->postJson('/api/2fa/setup/confirm', [
            'setup_token' => $setupToken,
            'code' => $this->currentCodeFor($secret),
        ])->assertOk();

        $confirmResponse->assertJsonPath('data.email', $user->email);
        $this->assertCount(8, $confirmResponse->json('data.recovery_codes'));
        $this->assertAuthenticatedAs($user);

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasTwoFactorEnabled());
        $this->assertSame($secret, $fresh->two_factor_secret);
        $this->assertNotSame($secret, $fresh->getRawOriginal('two_factor_secret'), 'The secret must be stored encrypted.');
    }

    public function test_setup_confirm_rejects_an_invalid_code(): void
    {
        $user = User::factory()->create(['password' => Hash::make('CorrectHorse123!')])->givePermissionTo(Permission::EquipmentView->value);
        $setupToken = $this->loginAndGetSetupToken($user);

        $this->postJson('/api/2fa/setup', ['setup_token' => $setupToken])->assertOk();

        $this->postJson('/api/2fa/setup/confirm', [
            'setup_token' => $setupToken,
            'code' => '000000',
        ])->assertStatus(422);

        $this->assertGuest();
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_enrolled_user_must_verify_a_code_before_a_session_is_established(): void
    {
        $secret = (new TwoFactorAuthService)->generateSecret();
        $user = $this->createEnrolledUser($secret);

        $twoFaToken = $this->loginAndGetVerifyToken($user);
        $this->assertGuest();

        $this->postJson('/api/2fa/verify', [
            'two_fa_token' => $twoFaToken,
            'code' => $this->currentCodeFor($secret),
        ])->assertOk()->assertJsonPath('data.email', $user->email);

        $this->assertAuthenticatedAs($user);
    }

    public function test_factory_twofactorconfirmed_state_logs_in_through_the_verify_step(): void
    {
        $secret = (new TwoFactorAuthService)->generateSecret();
        $user = User::factory()->twoFactorConfirmed($secret)->create(['password' => Hash::make('CorrectHorse123!')])->givePermissionTo(Permission::EquipmentView->value);

        $this->postJson('/api/2fa/verify', [
            'two_fa_token' => $this->loginAndGetVerifyToken($user),
            'code' => $this->currentCodeFor($secret),
        ])->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_verify_rejects_an_invalid_code(): void
    {
        $user = $this->createEnrolledUser((new TwoFactorAuthService)->generateSecret());

        $this->postJson('/api/2fa/verify', [
            'two_fa_token' => $this->loginAndGetVerifyToken($user),
            'code' => '000000',
        ])->assertStatus(422);

        $this->assertGuest();
    }

    public function test_verify_accepts_a_recovery_code_case_insensitively_and_consumes_it(): void
    {
        $tfa = new TwoFactorAuthService;
        $recoveryCodes = $tfa->generateRecoveryCodes();
        $user = $this->createEnrolledUser($tfa->generateSecret(), $recoveryCodes);

        $this->postJson('/api/2fa/verify', [
            'two_fa_token' => $this->loginAndGetVerifyToken($user),
            'recovery_code' => strtolower($recoveryCodes[0]),
        ])->assertOk();

        $this->assertAuthenticatedAs($user);
        $this->assertCount(7, $user->fresh()->two_factor_recovery_codes);
    }

    public function test_authenticated_user_can_regenerate_recovery_codes(): void
    {
        $user = $this->createEnrolledUser((new TwoFactorAuthService)->generateSecret());

        $response = $this->actingAs($user)->postJson('/api/2fa/recovery-codes/regenerate', [
            'current_password' => 'CorrectHorse123!',
        ])->assertOk();

        $this->assertCount(8, $response->json('data.recovery_codes'));
    }

    public function test_regenerate_recovery_codes_requires_the_correct_password(): void
    {
        $user = $this->createEnrolledUser((new TwoFactorAuthService)->generateSecret());

        $this->actingAs($user)->postJson('/api/2fa/recovery-codes/regenerate', [
            'current_password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_users_manage_holder_can_reset_two_factor_for_another_user(): void
    {
        $admin = User::factory()->create(['password' => Hash::make('AdminPass123!')]);
        $admin->assignRole('admin');

        $target = $this->createEnrolledUser((new TwoFactorAuthService)->generateSecret());
        $target->trustedDevices()->create(['token_hash' => hash('sha256', 'device'), 'expires_at' => now()->addDays(30)]);

        $this->actingAs($admin)->postJson("/api/users/{$target->id}/2fa/reset", [
            'current_password' => 'AdminPass123!',
        ])->assertOk();

        $fresh = $target->fresh();
        $this->assertFalse($fresh->hasTwoFactorEnabled());
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
        $this->assertSame(0, $fresh->trustedDevices()->count());

        $log = Activity::where('event', 'user.two_factor_reset')->where('subject_id', $target->id)->sole();
        $this->assertSame($admin->id, $log->causer_id);
    }

    public function test_reset_two_factor_requires_the_admins_current_password(): void
    {
        $admin = User::factory()->create(['password' => Hash::make('AdminPass123!')]);
        $admin->assignRole('admin');
        $target = $this->createEnrolledUser((new TwoFactorAuthService)->generateSecret());

        $this->actingAs($admin)->postJson("/api/users/{$target->id}/2fa/reset", [
            'current_password' => 'wrong-password',
        ])->assertUnprocessable();

        $this->assertTrue($target->fresh()->hasTwoFactorEnabled());
    }

    public function test_admin_cannot_reset_their_own_two_factor(): void
    {
        $admin = $this->createEnrolledUser((new TwoFactorAuthService)->generateSecret());
        $admin->assignRole('admin');

        $this->actingAs($admin)->postJson("/api/users/{$admin->id}/2fa/reset", [
            'current_password' => 'CorrectHorse123!',
        ])->assertForbidden();

        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
    }

    public function test_user_without_users_manage_cannot_reset_two_factor_for_another_user(): void
    {
        $user = User::factory()->create(['password' => Hash::make('UserPass123!')]);
        $user->assignRole('encoder');

        $target = $this->createEnrolledUser((new TwoFactorAuthService)->generateSecret());

        $this->actingAs($user)->postJson("/api/users/{$target->id}/2fa/reset", [
            'current_password' => 'UserPass123!',
        ])->assertForbidden();

        $this->assertTrue($target->fresh()->hasTwoFactorEnabled());
    }

    public function test_verify_token_cannot_be_used_to_reenroll_two_factor(): void
    {
        $secret = (new TwoFactorAuthService)->generateSecret();
        $user = $this->createEnrolledUser($secret);
        $verifyToken = $this->loginAndGetVerifyToken($user);

        $this->postJson('/api/2fa/setup', ['setup_token' => $verifyToken])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('setup_token');

        $attackerSecret = (new TwoFactorAuthService)->generateSecret();
        Cache::put("2fa_setup_secret:{$user->id}", $attackerSecret, now()->addMinutes(10));

        $this->postJson('/api/2fa/setup/confirm', [
            'setup_token' => $verifyToken,
            'code' => $this->currentCodeFor($attackerSecret),
        ])->assertUnprocessable();

        $this->assertGuest();
        $this->assertSame($secret, $user->fresh()->two_factor_secret);
    }

    public function test_setup_token_cannot_be_used_on_the_verify_endpoint(): void
    {
        $user = User::factory()->create(['password' => Hash::make('CorrectHorse123!')])->givePermissionTo(Permission::EquipmentView->value);
        $setupToken = $this->loginAndGetSetupToken($user);

        $this->postJson('/api/2fa/verify', [
            'two_fa_token' => $setupToken,
            'code' => '123456',
        ])->assertUnprocessable()->assertJsonValidationErrors('two_fa_token');

        $this->assertGuest();
    }

    public function test_setup_is_refused_for_a_user_who_is_already_enrolled(): void
    {
        $secret = (new TwoFactorAuthService)->generateSecret();
        $user = $this->createEnrolledUser($secret);
        $forgedToken = TwoFactorController::SETUP_PREFIX.'forged';
        Cache::put($forgedToken, ['user_id' => $user->id], now()->addMinutes(5));

        $this->postJson('/api/2fa/setup', ['setup_token' => $forgedToken])->assertUnprocessable();

        $this->assertSame($secret, $user->fresh()->two_factor_secret);
    }

    public function test_a_user_deactivated_mid_login_cannot_finish_two_factor(): void
    {
        $secret = (new TwoFactorAuthService)->generateSecret();
        $user = $this->createEnrolledUser($secret);
        $token = $this->loginAndGetVerifyToken($user);

        $user->update(['is_active' => false]);

        $this->postJson('/api/2fa/verify', [
            'two_fa_token' => $token,
            'code' => $this->currentCodeFor($secret),
        ])->assertUnprocessable()->assertJsonValidationErrors('two_fa_token');

        $this->assertGuest();
    }

    public function test_a_user_whose_permissions_are_removed_mid_login_cannot_finish_two_factor(): void
    {
        $secret = (new TwoFactorAuthService)->generateSecret();
        $user = $this->createEnrolledUser($secret);
        $token = $this->loginAndGetVerifyToken($user);

        $user->revokePermissionTo(Permission::EquipmentView->value);

        $response = $this->postJson('/api/2fa/verify', [
            'two_fa_token' => $token,
            'code' => $this->currentCodeFor($secret),
            'remember_device' => true,
        ])->assertForbidden()->assertJsonPath('code', 'no_access');

        $this->assertNull($response->getCookie(TrustedDeviceService::COOKIE, false));
        $this->assertSame(0, $user->trustedDevices()->count());
        $this->assertGuest('web');

        $user->givePermissionTo(Permission::EquipmentView->value);

        $this->postJson('/api/2fa/verify', [
            'two_fa_token' => $token,
            'code' => $this->currentCodeFor($secret),
        ])->assertUnprocessable()->assertJsonValidationErrors('two_fa_token');
    }

    public function test_a_recovery_code_is_not_spent_when_access_was_removed_mid_login(): void
    {
        $codes = (new TwoFactorAuthService)->generateRecoveryCodes();
        $user = $this->createEnrolledUser((new TwoFactorAuthService)->generateSecret(), $codes);
        $token = $this->loginAndGetVerifyToken($user);

        $user->revokePermissionTo(Permission::EquipmentView->value);

        $this->postJson('/api/2fa/verify', [
            'two_fa_token' => $token,
            'recovery_code' => $codes[0],
        ])->assertForbidden()->assertJsonPath('code', 'no_access');

        $this->assertCount(count($codes), $user->fresh()->two_factor_recovery_codes);
        $this->assertGuest('web');
    }

    public function test_a_totp_code_cannot_be_replayed(): void
    {
        $secret = (new TwoFactorAuthService)->generateSecret();
        $user = $this->createEnrolledUser($secret);
        $code = $this->currentCodeFor($secret);

        $this->postJson('/api/2fa/verify', [
            'two_fa_token' => $this->loginAndGetVerifyToken($user),
            'code' => $code,
        ])->assertOk();

        $this->app['auth']->guard('web')->logout();

        $this->postJson('/api/2fa/verify', [
            'two_fa_token' => $this->loginAndGetVerifyToken($user),
            'code' => $code,
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }
}
