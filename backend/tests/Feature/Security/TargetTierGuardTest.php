<?php

namespace Tests\Feature\Security;

use App\Enums\Permission;
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
 * A users.manage holder may edit, deactivate, delete or reset 2FA only for
 * accounts whose permissions are a subset of their own — otherwise
 * users.manage alone could take over an admin (edit email, reset password,
 * reset 2FA).
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

    private function usersManageActor(): User
    {
        return $this->userWithPermissions(
            [Permission::UsersManage, Permission::EquipmentView],
            ['password' => Hash::make(self::PASSWORD)],
        );
    }

    private function enrolledAdmin(): User
    {
        $tfa = new TwoFactorAuthService;

        return $this->userWithRole('admin', [
            'two_factor_secret' => $tfa->generateSecret(),
            'two_factor_recovery_codes' => $tfa->hashRecoveryCodes($tfa->generateRecoveryCodes()),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function test_users_manage_only_actor_cannot_change_an_admins_email(): void
    {
        $admin = $this->enrolledAdmin();

        $this->actingAs($this->usersManageActor())->putJson("/api/users/{$admin->id}", [
            'name' => $admin->name,
            'email' => 'attacker@example.com',
            'role_ids' => [],
        ])->assertForbidden();

        $this->assertNotSame('attacker@example.com', $admin->fresh()->email);
        $this->assertSame(['admin'], $admin->fresh()->roles()->pluck('name')->all());
    }

    public function test_users_manage_only_actor_cannot_deactivate_an_admin(): void
    {
        $admin = $this->enrolledAdmin();

        $this->actingAs($this->usersManageActor())->putJson("/api/users/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'is_active' => false,
            'role_ids' => $admin->roles()->pluck('roles.id')->all(),
        ])->assertForbidden();

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_users_manage_only_actor_cannot_reset_an_admins_two_factor(): void
    {
        $admin = $this->enrolledAdmin();

        $this->actingAs($this->usersManageActor())->postJson("/api/users/{$admin->id}/2fa/reset", [
            'current_password' => self::PASSWORD,
        ])->assertForbidden();

        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
    }

    public function test_users_manage_only_actor_cannot_delete_an_admin(): void
    {
        $admin = $this->enrolledAdmin();

        $this->actingAs($this->usersManageActor())->deleteJson("/api/users/{$admin->id}")->assertForbidden();

        $this->assertNotNull($admin->fresh());
    }

    public function test_users_manage_only_actor_can_still_manage_a_lower_tier_user(): void
    {
        $actor = $this->usersManageActor();
        $target = $this->userWithPermissions([Permission::EquipmentView]);
        $tfa = new TwoFactorAuthService;
        $target->forceFill([
            'two_factor_secret' => $tfa->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($actor)->putJson("/api/users/{$target->id}", [
            'name' => 'Renamed',
            'email' => 'renamed@example.com',
            'is_active' => false,
            'role_ids' => [],
        ])->assertOk()->assertJsonPath('data.can_manage', true);

        $this->assertSame('renamed@example.com', $target->fresh()->email);
        $this->assertFalse($target->fresh()->is_active);

        $this->actingAs($actor)->postJson("/api/users/{$target->id}/2fa/reset", [
            'current_password' => self::PASSWORD,
        ])->assertOk();
        $this->assertFalse($target->fresh()->hasTwoFactorEnabled());

        $this->actingAs($actor)->deleteJson("/api/users/{$target->id}")->assertNoContent();
        $this->assertNull($target->fresh());
    }

    public function test_users_list_flags_which_rows_the_actor_can_manage(): void
    {
        $actor = $this->usersManageActor();
        $admin = $this->enrolledAdmin();
        $lower = $this->userWithPermissions([Permission::EquipmentView]);
        $noAccess = $this->userWithRole('pending');

        $rows = collect($this->actingAs($actor)->getJson('/api/users?per_page=100')->assertOk()->json('data'))
            ->keyBy('id');

        $this->assertFalse($rows[$admin->id]['can_manage']);
        $this->assertFalse($rows[$admin->id]['can_edit']);
        $this->assertTrue($rows[$actor->id]['can_edit']);
        $this->assertTrue($rows[$lower->id]['can_manage']);
        $this->assertTrue($rows[$noAccess->id]['can_manage']);
        $this->assertFalse($rows[$actor->id]['can_manage'], 'own row is never manageable here');
    }

    public function test_an_admin_can_manage_every_other_row(): void
    {
        $actor = $this->usersManageActor();
        $admin = $this->enrolledAdmin();
        $otherAdmin = $this->userWithRole('admin');

        $rows = collect($this->actingAs($admin)->getJson('/api/users?per_page=100')->assertOk()->json('data'))
            ->keyBy('id');

        $this->assertTrue($rows[$actor->id]['can_manage']);
        $this->assertTrue($rows[$otherAdmin->id]['can_manage']);
        $this->assertFalse($rows[$admin->id]['can_manage']);
    }

    public function test_service_backstops_refuse_an_out_of_tier_target(): void
    {
        $actor = $this->usersManageActor();
        $admin = $this->enrolledAdmin();
        $service = app(UserRoleService::class);

        foreach ([
            fn () => $service->updateUser($actor, $admin, ['name' => 'x', 'email' => 'x@example.com'], []),
            fn () => $service->syncRoles($actor, $admin, []),
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
        $this->assertNotSame('x@example.com', $fresh->email);
        $this->assertSame(['admin'], $fresh->roles()->pluck('name')->all());
        $this->assertTrue($fresh->hasTwoFactorEnabled());
    }
}
