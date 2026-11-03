<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const GUARD = 'web';

    private const PERMISSION = 'inventory_count.delete';

    private const SOURCE = 'equipment.delete';

    public function up(): void
    {
        if (! DB::table('roles')->where('guard_name', self::GUARD)->exists()) {
            return;
        }

        DB::transaction(function () {
            $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->where('guard_name', self::GUARD)->value('id')
                ?? DB::table('permissions')->insertGetId([
                    'name' => self::PERMISSION,
                    'guard_name' => self::GUARD,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            $roles = DB::table('role_has_permissions')
                ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
                ->where('permissions.name', self::SOURCE)
                ->where('permissions.guard_name', self::GUARD)
                ->orderBy('roles.id')
                ->get(['roles.id', 'roles.name']);

            if ($roles->isEmpty()) {
                return;
            }

            DB::table('role_has_permissions')->insertOrIgnore($roles->map(fn ($role) => [
                'permission_id' => (int) $permissionId,
                'role_id' => (int) $role->id,
            ])->all());

            $migration = pathinfo(__FILE__, PATHINFO_FILENAME);

            DB::table('activity_log')->insert([
                'log_name' => 'default',
                'description' => $migration,
                'event' => 'system.permissions_granted',
                'properties' => json_encode([
                    'migration' => $migration,
                    'grants' => [self::PERMISSION => $roles->map(fn ($role) => ['id' => (int) $role->id, 'name' => $role->name])->all()],
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::transaction(function () {
            $ids = DB::table('permissions')->where('guard_name', self::GUARD)->where('name', self::PERMISSION)->pluck('id');

            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
