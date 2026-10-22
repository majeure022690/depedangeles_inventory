<?php

namespace Tests\Feature\Auth;

use App\Enums\Permission;
use App\Http\Controllers\Api\Auth\TwoFactorController;
use App\Models\Role;
use App\Models\User;
use App\Services\TrustedDeviceService;
use App\Services\TwoFactorAuthService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class NoAccessGateTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'CorrectHorse123!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->withCredentials();

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

    private function login(User $user): TestResponse
    {
        return $this->postJson('/api/login', ['email' => $user->email, 'password' => self::PASSWORD]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function user(array $attributes = []): User
    {
        return User::factory()->create(['password' => Hash::make(self::PASSWORD), ...$attributes]);
    }

    private function enrolled(): User
    {
        $tfa = new TwoFactorAuthService;

        return $this->user([
            'two_factor_secret' => $tfa->generateSecret(),
            'two_factor_recovery_codes' => $tfa->hashRecoveryCodes($tfa->generateRecoveryCodes()),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function signOut(): void
    {
        $this->app['auth']->guard('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    public function test_a_direct_permission_without_any_role_is_enough(): void
    {
        $user = $this->user()->givePermissionTo(Permission::EquipmentView->value);
        $this->assertCount(0, $user->roles);

        $this->login($user)->assertOk()->assertJsonPath('requires_2fa_setup', true);
    }

    public function test_a_role_with_zero_permissions_is_refused(): void
    {
        $empty = Role::create(['name' => 'empty_role', 'label' => 'Empty', 'guard_name' => 'web']);
        $user = $this->user();
        $user->assignRole($empty);

        $this->login($user)
            ->assertForbidden()
            ->assertJsonPath('code', 'no_access')
            ->assertJsonMissingPath('setup_token');

        $this->assertGuest('web');
    }

    public function test_a_trusted_device_does_not_let_a_roleless_user_in(): void
    {
        $user = $this->enrolled();
        $user->assignRole('viewer');

        $twoFaToken = $this->login($user)->assertOk()->json('two_fa_token');
        $cookie = $this->postJson('/api/2fa/verify', [
            'two_fa_token' => $twoFaToken,
            'code' => (new Google2FA)->getCurrentOtp($user->two_factor_secret),
            'remember_device' => true,
        ])->assertOk()->getCookie(TrustedDeviceService::COOKIE);
        $this->assertNotNull($cookie);
        $this->signOut();

        $this->withCookie(TrustedDeviceService::COOKIE, $cookie->getValue());
        $this->login($user)->assertOk()->assertJsonPath('data.id', $user->id);
        $this->signOut();

        $user->syncRoles([]);

        $this->login($user)
            ->assertForbidden()
            ->assertJsonPath('code', 'no_access')
            ->assertJsonMissingPath('data');

        $this->assertGuest('web');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_an_inactive_roleless_user_gets_the_generic_credentials_error(): void
    {
        $user = $this->user(['is_active' => false]);

        $this->login($user)
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Invalid credentials.')
            ->assertJsonMissingPath('code');

        $this->assertGuest('web');
        $this->assertSame(0, Activity::where('event', 'auth.no_access')->count());
    }

    public function test_a_wrong_password_for_a_roleless_user_is_still_the_generic_422(): void
    {
        $user = $this->user();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJsonMissingPath('code');
    }

    public function test_refused_logins_still_count_towards_the_login_limiter(): void
    {
        $user = $this->user();

        for ($i = 0; $i < 5; $i++) {
            $this->login($user)->assertForbidden();
        }

        $this->login($user)->assertStatus(429);
        $this->assertSame(1, Activity::where('event', 'auth.no_access')->count());
    }

    public function test_two_factor_setup_with_a_token_issued_before_roles_were_removed_is_refused(): void
    {
        $user = $this->user()->givePermissionTo(Permission::EquipmentView->value);
        $setupToken = $this->login($user)->assertOk()->json('setup_token');
        $confirmToken = $this->login($user)->assertOk()->json('setup_token');

        $this->postJson('/api/2fa/setup', ['setup_token' => $confirmToken])->assertOk();

        $user->revokePermissionTo(Permission::EquipmentView->value);

        $this->postJson('/api/2fa/setup', ['setup_token' => $setupToken])
            ->assertForbidden()->assertJsonPath('code', 'no_access');
        $this->postJson('/api/2fa/setup/confirm', ['setup_token' => $confirmToken, 'code' => '123456'])
            ->assertForbidden()->assertJsonPath('code', 'no_access');

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
        $this->assertGuest('web');
        $this->assertNull(Cache::get($setupToken));
        $this->assertNull(Cache::get($confirmToken));
        $this->assertNull(Cache::get("2fa_setup_secret:{$user->id}"));

        $user->givePermissionTo(Permission::EquipmentView->value);

        $this->postJson('/api/2fa/setup', ['setup_token' => $setupToken])
            ->assertUnprocessable()->assertJsonValidationErrors('setup_token');
        $this->postJson('/api/2fa/setup/confirm', ['setup_token' => $confirmToken, 'code' => '123456'])
            ->assertUnprocessable()->assertJsonValidationErrors('setup_token');
        $this->assertEqualsCanonicalizing(
            ['two_factor_setup', 'two_factor_setup_confirm'],
            Activity::where('event', 'auth.no_access')->get()->map(fn (Activity $a) => $a->properties['stage'])->all(),
        );
    }

    public function test_a_hand_crafted_setup_token_for_a_roleless_user_is_refused(): void
    {
        $user = $this->user();
        $token = TwoFactorController::SETUP_PREFIX.Str::random(40);
        Cache::put($token, TwoFactorController::pendingPayload($user), now()->addMinutes(5));

        $this->postJson('/api/2fa/setup', ['setup_token' => $token])
            ->assertForbidden()
            ->assertJsonPath('code', 'no_access')
            ->assertJsonMissingPath('data.secret');
    }

    public function test_logout_by_a_user_who_lost_access_still_ends_the_session(): void
    {
        $user = $this->user()->givePermissionTo(Permission::EquipmentView->value);
        $this->actingAs($user)->getJson('/api/user')->assertOk();

        $user->revokePermissionTo(Permission::EquipmentView->value);
        $this->app['auth']->forgetGuards();

        $this->actingAs($user->fresh())->postJson('/api/logout')
            ->assertForbidden()
            ->assertJsonPath('code', 'no_access');

        $this->assertGuest('web');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_a_policy_denial_keeps_its_plain_403_and_the_session(): void
    {
        $user = $this->user()->givePermissionTo(Permission::EquipmentView->value);

        $this->actingAs($user)->postJson('/api/equipment', [])
            ->assertForbidden()
            ->assertJsonMissingPath('code');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame(0, Activity::where('event', 'auth.no_access')->count());
    }

    public function test_repeated_refusals_at_the_same_stage_are_audited_once(): void
    {
        $user = $this->user();

        $this->login($user)->assertForbidden();
        $this->login($user)->assertForbidden();

        $this->assertSame(1, Activity::where('event', 'auth.no_access')->count());
    }

    public function test_the_refusal_is_audited_against_the_user(): void
    {
        $user = $this->user();

        $this->login($user)->assertForbidden();

        $activity = Activity::where('event', 'auth.no_access')->sole();
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame($user->id, $activity->subject_id);
        $this->assertSame('login', $activity->properties['stage']);
    }
}
