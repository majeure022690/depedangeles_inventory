<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const GUARD = 'web';

    private const GRANTS = [
        'inventory_count.view' => 'equipment.view',
        'inventory_count.conduct' => 'equipment.transactions.create',
        'inventory_count.manage' => 'equipment.delete',
    ];

    private const TEAM_ROLE = 'inventory_team';

    public function up(): void
    {
        if (! DB::table('roles')->where('guard_name', self::GUARD)->exists()) {
            return;
        }

        $granted = [];

        DB::transaction(function () use (&$granted) {
            foreach (self::GRANTS as $permission => $source) {
                $permissionId = $this->permissionId($permission) ?? DB::table('permissions')->insertGetId([
                    'name' => $permission,
                    'guard_name' => self::GUARD,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $roles = DB::table('role_has_permissions')
                    ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                    ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
                    ->where('permissions.name', $source)
                    ->where('permissions.guard_name', self::GUARD)
                    ->orderBy('roles.id')
                    ->get(['roles.id', 'roles.name']);

                if ($roles->isEmpty()) {
                    continue;
                }

                DB::table('role_has_permissions')->insertOrIgnore($roles->map(fn ($role) => [
                    'permission_id' => (int) $permissionId,
                    'role_id' => (int) $role->id,
                ])->all());

                $granted[$permission] = $roles->map(fn ($role) => ['id' => (int) $role->id, 'name' => $role->name])->all();
            }

            if (! DB::table('roles')->where('name', self::TEAM_ROLE)->where('guard_name', self::GUARD)->exists()) {
                $roleId = DB::table('roles')->insertGetId([
                    'name' => self::TEAM_ROLE,
                    'guard_name' => self::GUARD,
                    'label' => 'Inventory Team',
                    'description' => 'Inventory Committee members: can see physical counts and mark items found or not found.',
                    'data_scope' => 'all',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('role_has_permissions')->insertOrIgnore([
                    ['permission_id' => (int) $this->permissionId('inventory_count.view'), 'role_id' => (int) $roleId],
                    ['permission_id' => (int) $this->permissionId('inventory_count.conduct'), 'role_id' => (int) $roleId],
                ]);

                $granted[self::TEAM_ROLE] = ['inventory_count.view', 'inventory_count.conduct'];
            }

            if ($granted === []) {
                return;
            }

            $migration = pathinfo(__FILE__, PATHINFO_FILENAME);

            DB::table('activity_log')->insert([
                'log_name' => 'default',
                'description' => $migration,
                'event' => 'system.permissions_granted',
                'properties' => json_encode(['migration' => $migration, 'grants' => $granted]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::transaction(function () {
            $ids = DB::table('permissions')
                ->where('guard_name', self::GUARD)
                ->whereIn('name', array_keys(self::GRANTS))
                ->pluck('id');

            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();

            $roleId = DB::table('roles')->where('name', self::TEAM_ROLE)->where('guard_name', self::GUARD)->value('id');

            if ($roleId !== null && ! DB::table('model_has_roles')->where('role_id', $roleId)->exists()) {
                DB::table('role_has_permissions')->where('role_id', $roleId)->delete();
                DB::table('roles')->where('id', $roleId)->delete();
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function permissionId(string $name): ?int
    {
        $id = DB::table('permissions')->where('name', $name)->where('guard_name', self::GUARD)->value('id');

        return $id === null ? null : (int) $id;
    }
};
