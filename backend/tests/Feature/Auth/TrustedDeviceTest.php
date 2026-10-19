<?php

namespace Tests\Feature\Auth;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\TrustedDeviceService;
use App\Services\TwoFactorAuthService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TrustedDeviceTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'CorrectHorse123!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->withCredentials();
    }

    public function test_trusting_a_device_skips_the_code_on_the_next_login(): void
    {
        $user = $this->enrolledUser('encoder');

        $login = $this->login($user)->assertJsonPath('requires_2fa', true)->assertJsonPath('trust_device_days', 30);
        $token = $this->verify($user, $login->json('two_fa_token'), true)->assertOk()->getCookie(TrustedDeviceService::COOKIE)?->getValue();

        $this->assertSame('dictis_trusted_device', TrustedDeviceService::COOKIE);
        $this->assertNotEmpty($token);
        $this->assertSame(1, $user->trustedDevices()->count());
        $this->assertNotSame($token, $user->trustedDevices()->value('token_hash'));
        $this->assertSame(hash('sha256', $token), $user->trustedDevices()->value('token_hash'));

        $this->signOut();
        $this->withCookie(TrustedDeviceService::COOKIE, $token);
        $this->login($user)->assertOk()->assertJsonPath('data.email', $user->email)->assertJsonMissingPath('requires_2fa');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->trustedDevices()->value('last_used_at'));
    }

    public function test_a_device_is_not_trusted_unless_the_user_asks(): void
    {
        $user = $this->enrolledUser('encoder');

        $login = $this->login($user);
        $response = $this->verify($user, $login->json('two_fa_token'), false)->assertOk();

        $this->assertNull($response->getCookie(TrustedDeviceService::COOKIE, false));
        $this->assertSame(0, $user->trustedDevices()->count());
    }

    public function test_wrong_password_is_still_rejected_on_a_trusted_device(): void
    {
        $user = $this->enrolledUser('encoder');
        $token = $this->trustDevice($user);

        $this->signOut();
        $this->withCookie(TrustedDeviceService::COOKIE, $token);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'WrongPassword123!'])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_another_users_token_or_an_expired_token_does_not_skip_the_code(): void
    {
        $owner = $this->enrolledUser('encoder');
        $other = $this->enrolledUser('encoder');
        $token = $this->trustDevice($owner);

        $this->signOut();
        $this->withCookie(TrustedDeviceService::COOKIE, $token);
        $this->login($other)->assertJsonPath('requires_2fa', true);

        $owner->trustedDevices()->update(['expires_at' => now()->subMinute()]);
        $this->login($owner)->assertJsonPath('requires_2fa', true);
        $this->assertGuest();
    }

    public function test_holders_of_roles_manage_or_users_manage_always_need_the_code(): void
    {
        $rolesManageOnly = Role::create(['name' => 'role-editor', 'label' => 'Role Editor']);
        $rolesManageOnly->givePermissionTo(Permission::RolesManage->value);
        $usersManageOnly = Role::create(['name' => 'account-manager', 'label' => 'Account Manager']);
        $usersManageOnly->givePermissionTo(Permission::UsersManage->value);

        foreach (['admin', 'role-editor', 'account-manager'] as $role) {
            $user = $this->enrolledUser($role);

            $login = $this->login($user)->assertJsonPath('trust_device_days', 0);
            $response = $this->verify($user, $login->json('two_fa_token'), true)->assertOk();

            $this->assertNull($response->getCookie(TrustedDeviceService::COOKIE, false), "{$role} was given a trusted-device cookie.");
            $this->assertSame(0, $user->trustedDevices()->count());
            $this->signOut();
        }
    }

    public function test_an_existing_trust_stops_working_once_the_user_gains_users_manage(): void
    {
        $user = $this->enrolledUser('encoder');
        $token = $this->trustDevice($user);

        $user->assignRole('admin');

        $this->withCookie(TrustedDeviceService::COOKIE, $token);
        $this->login($user)->assertJsonPath('requires_2fa', true)->assertJsonPath('trust_device_days', 0);
        $this->assertGuest();
    }

    public function test_setting_the_days_to_zero_turns_the_feature_off(): void
    {
        $user = $this->enrolledUser('encoder');
        $token = $this->trustDevice($user);
        SystemSetting::query()->updateOrCreate(['key' => 'trusted_device_days'], ['value' => '0', 'type' => 'integer']);

        $this->signOut();
        $this->withCookie(TrustedDeviceService::COOKIE, $token);
        $this->login($user)->assertJsonPath('requires_2fa', true)->assertJsonPath('trust_device_days', 0);
    }

    public function test_password_change_two_factor_reset_and_deactivation_revoke_trusted_devices(): void
    {
        $user = $this->enrolledUser('encoder');

        $this->trustDevice($user);
        $user->update(['password' => Hash::make('AnotherHorse456!')]);
        $this->assertSame(0, $user->trustedDevices()->count());

        $user->update(['password' => Hash::make(self::PASSWORD)]);
        $this->trustDevice($user);
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        $this->assertSame(0, $user->trustedDevices()->count());

        $user->forceFill(['two_factor_secret' => (new TwoFactorAuthService)->generateSecret(), 'two_factor_confirmed_at' => now()])->save();
        $this->trustDevice($user);
        $user->update(['is_active' => false]);
        $this->assertSame(0, $user->trustedDevices()->count());
    }

    public function test_users_can_list_and_revoke_only_their_own_trusted_devices(): void
    {
        $user = $this->enrolledUser('encoder');
        $other = $this->enrolledUser('encoder');
        $token = $this->trustDevice($user);
        $this->trustDevice($user);
        $othersDevice = $other->trustedDevices()->create([
            'token_hash' => hash('sha256', 'other'),
            'expires_at' => now()->addDays(30),
        ]);

        $this->signOut();
        $this->withCookie(TrustedDeviceService::COOKIE, $token);
        $this->login($user)->assertOk();

        $list = $this->getJson('/api/trusted-devices')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.trust_device_days', 30)
            ->assertJsonMissingPath('data.0.token_hash');
        $this->assertSame(1, collect($list->json('data'))->where('is_current', true)->count());

        $this->deleteJson("/api/trusted-devices/{$othersDevice->id}")->assertNotFound();
        $this->assertModelExists($othersDevice);

        $current = collect($list->json('data'))->firstWhere('is_current', true);
        $this->deleteJson("/api/trusted-devices/{$current['id']}")->assertOk();
        $this->assertSame(1, $user->trustedDevices()->count());

        $this->deleteJson('/api/trusted-devices')->assertOk();
        $this->assertSame(0, $user->trustedDevices()->count());
        $this->assertModelExists($othersDevice);
    }

    public function test_guests_cannot_list_trusted_devices(): void
    {
        $this->getJson('/api/trusted-devices')->assertUnauthorized();
    }

    private function enrolledUser(string $role): User
    {
        $tfa = new TwoFactorAuthService;
        $user = User::factory()->create([
            'password' => Hash::make(self::PASSWORD),
            'two_factor_secret' => $tfa->generateSecret(),
            'two_factor_recovery_codes' => $tfa->hashRecoveryCodes($tfa->generateRecoveryCodes()),
            'two_factor_confirmed_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function login(User $user): TestResponse
    {
        return $this->postJson('/api/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
    }

    private function verify(User $user, string $twoFaToken, bool $remember): TestResponse
    {
        Cache::forget("2fa_last_timestep:{$user->id}");

        return $this->postJson('/api/2fa/verify', [
            'two_fa_token' => $twoFaToken,
            'code' => (new Google2FA)->getCurrentOtp($user->fresh()->two_factor_secret),
            'remember_device' => $remember,
        ]);
    }

    private function trustDevice(User $user): string
    {
        $login = $this->login($user);
        $token = $this->verify($user, $login->json('two_fa_token'), true)->assertOk()->getCookie(TrustedDeviceService::COOKIE)->getValue();
        $this->signOut();

        return $token;
    }

    private function signOut(): void
    {
        $this->app['auth']->guard('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }
}
