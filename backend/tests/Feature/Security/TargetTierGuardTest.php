<?php

namespace Tests\Feature\Security;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\TwoFactorAuthService;
use App\Services\UserRoleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

/**
 * A users.edit / users.reset_two_factor holder may change, deactivate or
 * reset 2FA only for accounts whose permissions are a subset of their own,
 * otherwise those permissions alone could take over an administrator.
 */
class TargetTierGuardTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private const PASSWORD = 'ActorPass123!x';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function accountManager(): User
    {
        return $this->userWithPermissions(
            [Permission::UsersView, Permission::UsersEdit, Permission::UsersResetTwoFactor, Permission::EquipmentView],
            ['password' => Hash::make(self::PASSWORD)],
        );
    }

    private function enrolledAdmin(): User
    {
        $tfa = new TwoFactorAuthService;

        return $this->userWithRole('system_administrator', [
            'two_factor_secret' => $tfa->generateSecret(),
            'two_factor_recovery_codes' => $tfa->hashRecoveryCodes($tfa->generateRecoveryCodes()),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function roleId(string $name): int
    {
        return Role::where('name', $name)->firstOrFail()->id;
    }

    public function test_account_manager_cannot_change_an_admins_role(): void
    {
        $admin = $this->enrolledAdmin();

        $this->actingAs($this->accountManager())->putJson("/api/users/{$admin->id}", [
            'role_id' => $this->roleId('viewer'),
        ])->assertForbidden();

        $this->assertSame(['system_administrator'], $admin->fresh()->roles()->pluck('name')->all());
    }

    public function test_account_manager_cannot_deactivate_an_admin(): void
    {
        $admin = $this->enrolledAdmin();

        $this->actingAs($this->accountManager())->putJson("/api/users/{$admin->id}", [
            'is_active' => false,
            'role_id' => $this->roleId('system_administrator'),
        ])->assertForbidden();

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_account_manager_cannot_reset_an_admins_two_factor(): void
    {
        $admin = $this->enrolledAdmin();

        $this->actingAs($this->accountManager())->postJson("/api/users/{$admin->id}/2fa/reset", [
            'current_password' => self::PASSWORD,
        ])->assertForbidden();

        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
    }

    public function test_account_manager_can_still_manage_a_lower_tier_user(): void
    {
        $actor = $this->accountManager();
        $target = $this->userWithPermissions([Permission::EquipmentView]);
        $tfa = new TwoFactorAuthService;
        $target->forceFill([
            'two_factor_secret' => $tfa->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($actor)->putJson("/api/users/{$target->id}", [
            'is_active' => false,
            'role_id' => $target->roles()->value('roles.id'),
        ])->assertOk()->assertJsonPath('data.can_edit', true);

        $this->assertFalse($target->fresh()->is_active);

        $this->actingAs($actor)->postJson("/api/users/{$target->id}/2fa/reset", [
            'current_password' => self::PASSWORD,
        ])->assertOk();
        $this->assertFalse($target->fresh()->hasTwoFactorEnabled());
    }

    public function test_users_list_flags_which_rows_the_actor_can_edit(): void
    {
        $actor = $this->accountManager();
        $admin = $this->enrolledAdmin();
        $lower = $this->userWithPermissions([Permission::EquipmentView]);
        $unassigned = User::factory()->create();

        $rows = collect($this->actingAs($actor)->getJson('/api/users?per_page=100')->assertOk()->json('data'))
            ->keyBy('id');

        $this->assertFalse($rows[$admin->id]['can_edit']);
        $this->assertFalse($rows[$admin->id]['can_reset_two_factor']);
        $this->assertTrue($rows[$lower->id]['can_edit']);
        $this->assertTrue($rows[$unassigned->id]['can_edit']);
        $this->assertTrue($rows[$actor->id]['can_edit']);
        $this->assertFalse($rows[$actor->id]['can_reset_two_factor']);
        $this->assertSame([false], $rows->pluck('can_delete')->unique()->values()->all());
    }

    public function test_an_admin_can_edit_every_row(): void
    {
        $actor = $this->accountManager();
        $admin = $this->enrolledAdmin();
        $otherAdmin = $this->userWithRole('system_administrator');

        $rows = collect($this->actingAs($admin)->getJson('/api/users?per_page=100')->assertOk()->json('data'))
            ->keyBy('id');

        $this->assertTrue($rows[$actor->id]['can_edit']);
        $this->assertTrue($rows[$otherAdmin->id]['can_edit']);
        $this->assertTrue($rows[$admin->id]['can_edit']);
    }

    public function test_service_backstops_refuse_an_out_of_tier_target(): void
    {
        $actor = $this->accountManager();
        $admin = $this->enrolledAdmin();
        $service = app(UserRoleService::class);

        foreach ([
            fn () => $service->updateUser($actor, $admin, ['is_active' => false], [$this->roleId('viewer')]),
            fn () => $service->syncRoles($actor, $admin, [$this->roleId('viewer')]),
            fn () => $service->deleteUser($actor, $admin),
            fn () => $service->resetTwoFactor($actor, $admin),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected AuthorizationException');
            } catch (AuthorizationException) {
                // expected
            }
        }

        $fresh = $admin->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame(['system_administrator'], $fresh->roles()->pluck('name')->all());
        $this->assertTrue($fresh->hasTwoFactorEnabled());
    }
}
