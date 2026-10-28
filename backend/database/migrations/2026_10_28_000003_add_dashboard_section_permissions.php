<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const GUARD = 'web';

    private const GRANTS = [
        'dashboard.ppe' => 'equipment.view',
        'dashboard.semi_expendable' => 'equipment.view',
        'dashboard.equipment_overview' => 'equipment.view',
        'dashboard.equipment_distribution' => 'equipment.view',
        'dashboard.internet_connectivity' => 'isp_accounts.view',
    ];

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
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function permissionId(string $name): ?int
    {
        $id = DB::table('permissions')->where('name', $name)->where('guard_name', self::GUARD)->value('id');

        return $id === null ? null : (int) $id;
    }
};
