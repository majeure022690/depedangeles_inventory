<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const GUARD = 'web';

    private const PERMISSION = 'email_log.manage';

    private const RESTORE_ROLES = ['super_administrator', 'system_administrator'];

    public function up(): void
    {
        DB::transaction(function () {
            $permissionId = DB::table('permissions')
                ->where('name', self::PERMISSION)
                ->where('guard_name', self::GUARD)
                ->value('id');

            if ($permissionId === null) {
                return;
            }

            $roles = DB::table('role_has_permissions')
                ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
                ->where('role_has_permissions.permission_id', $permissionId)
                ->orderBy('roles.id')
                ->get(['roles.id', 'roles.name']);

            $directHolders = DB::table('model_has_permissions')
                ->where('permission_id', $permissionId)
                ->orderBy('model_type')
                ->orderBy('model_id')
                ->get(['model_type', 'model_id']);

            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();

            $migration = pathinfo(__FILE__, PATHINFO_FILENAME);

            DB::table('activity_log')->insert([
                'log_name' => 'default',
                'description' => $migration,
                'event' => 'system.permission_removed',
                'properties' => json_encode([
                    'migration' => $migration,
                    'permission' => self::PERMISSION,
                    'roles' => $roles->map(fn ($role) => ['id' => (int) $role->id, 'name' => $role->name])->all(),
                    'direct_holders' => $directHolders->map(fn ($holder) => [
                        'model_type' => $holder->model_type,
                        'model_id' => (int) $holder->model_id,
                    ])->all(),
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
            $permissionId = DB::table('permissions')
                ->where('name', self::PERMISSION)
                ->where('guard_name', self::GUARD)
                ->value('id') ?? DB::table('permissions')->insertGetId([
                    'name' => self::PERMISSION,
                    'guard_name' => self::GUARD,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            $rows = DB::table('roles')
                ->where('guard_name', self::GUARD)
                ->whereIn('name', self::RESTORE_ROLES)
                ->pluck('id')
                ->map(fn ($roleId) => ['permission_id' => (int) $permissionId, 'role_id' => (int) $roleId])
                ->all();

            if ($rows !== []) {
                DB::table('role_has_permissions')->insertOrIgnore($rows);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
