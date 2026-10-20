<?php

namespace Tests\Feature\Migrations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SplitAdminPermissionsAndReworkRolesTest extends TestCase
{
    use RefreshDatabase;

    private const USER_MORPH = 'App\\Models\\User';

    private const USERS_SPLIT = ['users.create', 'users.delete', 'users.edit', 'users.reset_two_factor', 'users.view'];

    private const ROLES_SPLIT = ['roles.create', 'roles.delete', 'roles.edit', 'roles.view'];

    private function migration(): object
    {
        return require database_path('migrations/2026_10_20_000002_split_admin_permissions_and_rework_roles.php');
    }

    /**
     * @return array<string, int>
     */
    private function seedLegacyState(): array
    {
        $this->migration()->down();

        DB::table('model_has_roles')->delete();
        DB::table('model_has_permissions')->delete();
        DB::table('role_has_permissions')->delete();
        DB::table('roles')->delete();
        DB::table('permissions')->delete();

        $permissions = ['equipment.view', 'equipment.edit', 'users.manage', 'roles.manage', 'audit_log.view'];
        foreach ($permissions as $i => $name) {
            DB::table('permissions')->insert(['id' => 10 + $i, 'name' => $name, 'guard_name' => 'web']);
        }

        $roles = [
            1 => ['pending', 'Pending Approval'],
            2 => ['admin', 'Administrator'],
            3 => ['encoder', 'Encoder'],
            4 => ['viewer', 'Viewer'],
            5 => ['account-manager', 'Account Manager'],
            6 => ['auditor', 'Auditor'],
        ];
        foreach ($roles as $id => [$name, $label]) {
            DB::table('roles')->insert(['id' => $id, 'name' => $name, 'guard_name' => 'web', 'label' => $label]);
        }

        $grants = [2 => [10, 11, 12, 13, 14], 3 => [10, 11], 4 => [10], 5 => [12], 6 => [14]];
        foreach ($grants as $roleId => $permissionIds) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }

        $users = [];
        foreach (['admin', 'pendingOnly', 'multi', 'tie', 'direct'] as $key) {
            $users[$key] = User::factory()->create()->id;
        }

        $assignments = [
            'admin' => [2],
            'pendingOnly' => [1],
            'multi' => [1, 4, 3],
            'tie' => [6, 4],
        ];
        foreach ($assignments as $key => $roleIds) {
            foreach ($roleIds as $roleId) {
                DB::table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => self::USER_MORPH, 'model_id' => $users[$key]]);
            }
        }

        DB::table('model_has_permissions')->insert(['permission_id' => 13, 'model_type' => self::USER_MORPH, 'model_id' => $users['direct']]);

        return $users;
    }

    /**
     * @return array<int, string>
     */
    private function rolePermissions(string $role): array
    {
        return DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->where('roles.name', $role)
            ->orderBy('permissions.name')
            ->pluck('permissions.name')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function rolesOf(int $userId): array
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_id', $userId)
            ->orderBy('roles.name')
            ->pluck('roles.name')
            ->all();
    }

    public function test_up_splits_permissions_and_reworks_roles(): void
    {
        $users = $this->seedLegacyState();

        $this->migration()->up();

        $this->assertSame(0, DB::table('permissions')->whereIn('name', ['users.manage', 'roles.manage'])->count());

        $superAdmin = DB::table('roles')->where('name', 'super_administrator')->first();
        $this->assertSame(2, (int) $superAdmin->id);
        $this->assertSame('Super Administrator', $superAdmin->label);
        $this->assertSame(DB::table('permissions')->orderBy('name')->pluck('name')->all(), $this->rolePermissions('super_administrator'));
        $this->assertCount(12, $this->rolePermissions('super_administrator'));

        $this->assertSame('System Administrator', DB::table('roles')->where('name', 'system_administrator')->value('label'));
        $this->assertSame(
            array_values(array_diff($this->rolePermissions('super_administrator'), ['roles.create', 'roles.edit', 'roles.delete'])),
            $this->rolePermissions('system_administrator'),
        );

        $this->assertSame(self::USERS_SPLIT, $this->rolePermissions('account-manager'));
        $this->assertSame(['equipment.edit', 'equipment.view'], $this->rolePermissions('encoder'));

        $this->assertNull(DB::table('roles')->where('name', 'pending')->first());
        $this->assertNull(DB::table('roles')->where('name', 'admin')->first());

        $this->assertSame(['super_administrator'], $this->rolesOf($users['admin']));
        $this->assertSame([], $this->rolesOf($users['pendingOnly']));
        $this->assertSame(['encoder'], $this->rolesOf($users['multi']));
        $this->assertSame(['viewer'], $this->rolesOf($users['tie']));

        $this->assertSame(
            self::ROLES_SPLIT,
            DB::table('model_has_permissions')
                ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
                ->where('model_id', $users['direct'])
                ->orderBy('permissions.name')
                ->pluck('permissions.name')
                ->all(),
        );

        $this->assertTrue(User::findOrFail($users['admin'])->hasPermissionTo('users.reset_two_factor'));
        $this->assertTrue(User::findOrFail($users['admin'])->hasPermissionTo('roles.delete'));
    }

    public function test_down_restores_legacy_permissions_and_roles(): void
    {
        $users = $this->seedLegacyState();
        $this->migration()->up();

        $this->migration()->down();

        $this->assertSame(0, DB::table('permissions')->whereIn('name', [...self::USERS_SPLIT, ...self::ROLES_SPLIT])->count());
        $this->assertNull(DB::table('roles')->where('name', 'system_administrator')->first());
        $this->assertNull(DB::table('roles')->where('name', 'super_administrator')->first());

        $admin = DB::table('roles')->where('name', 'admin')->first();
        $this->assertSame(2, (int) $admin->id);
        $this->assertSame('Administrator', $admin->label);
        $this->assertSame(
            ['audit_log.view', 'equipment.edit', 'equipment.view', 'roles.manage', 'users.manage'],
            $this->rolePermissions('admin'),
        );
        $this->assertSame(['users.manage'], $this->rolePermissions('account-manager'));
        $this->assertSame([], $this->rolePermissions('pending'));
        $this->assertSame(['admin'], $this->rolesOf($users['admin']));

        $this->migration()->up();
    }

    public function test_up_is_safe_on_an_unseeded_database(): void
    {
        $this->migration()->down();
        DB::table('roles')->delete();
        DB::table('permissions')->delete();

        $this->migration()->up();

        $this->assertSame([...self::ROLES_SPLIT, ...self::USERS_SPLIT], DB::table('permissions')->orderBy('name')->pluck('name')->all());
        $this->assertSame(['system_administrator'], DB::table('roles')->pluck('name')->all());
    }
}
