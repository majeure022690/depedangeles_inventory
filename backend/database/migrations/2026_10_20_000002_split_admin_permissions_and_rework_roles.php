<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Splits users.manage / roles.manage into per-action permissions, renames
 * `admin` to `super_administrator` (same id), adds `system_administrator`,
 * drops `pending`, and reduces every model to a single role. Works on the
 * raw Spatie tables so it does not depend on App\Enums\Permission.
 */
return new class extends Migration
{
    private const GUARD = 'web';

    private const SPLITS = [
        'users.manage' => ['users.view', 'users.create', 'users.edit', 'users.delete', 'users.reset_two_factor'],
        'roles.manage' => ['roles.view', 'roles.create', 'roles.edit', 'roles.delete'],
    ];

    private const SYSTEM_ADMIN_EXCLUDED = ['roles.create', 'roles.edit', 'roles.delete'];

    private const SUPER_ADMIN = [
        'name' => 'super_administrator',
        'label' => 'Super Administrator',
        'description' => 'Every permission, always. Cannot be renamed, deleted, or have permissions removed.',
    ];

    private const SYSTEM_ADMIN = [
        'name' => 'system_administrator',
        'label' => 'System Administrator',
        'description' => 'Every permission except creating, editing, or deleting roles.',
    ];

    private const LEGACY_ADMIN = [
        'name' => 'admin',
        'label' => 'Administrator',
        'description' => 'Full access — all permissions, including reference-data, role, and user administration.',
    ];

    private const LEGACY_PENDING = [
        'name' => 'pending',
        'label' => 'Pending Approval',
        'description' => 'No permissions. Grants access to nothing until an Administrator assigns a real role.',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::SPLITS as $legacy => $replacements) {
                $this->splitPermission($legacy, $replacements);
            }

            DB::table('roles')
                ->where('name', self::LEGACY_ADMIN['name'])
                ->where('guard_name', self::GUARD)
                ->update([
                    'name' => self::SUPER_ADMIN['name'],
                    'label' => self::SUPER_ADMIN['label'],
                    'description' => self::SUPER_ADMIN['description'],
                    'updated_at' => now(),
                ]);

            $superAdminId = $this->roleId(self::SUPER_ADMIN['name']);
            if ($superAdminId !== null) {
                $this->grantToRole($superAdminId, DB::table('permissions')->where('guard_name', self::GUARD)->pluck('id'));
            }

            $systemAdminId = $this->roleId(self::SYSTEM_ADMIN['name']) ?? $this->insertRole(self::SYSTEM_ADMIN);
            $this->grantToRole($systemAdminId, DB::table('permissions')
                ->where('guard_name', self::GUARD)
                ->whereNotIn('name', self::SYSTEM_ADMIN_EXCLUDED)
                ->pluck('id'));

            $pendingId = $this->roleId(self::LEGACY_PENDING['name']);
            if ($pendingId !== null) {
                DB::table('model_has_roles')->where('role_id', $pendingId)->delete();
                DB::table('role_has_permissions')->where('role_id', $pendingId)->delete();
                DB::table('roles')->where('id', $pendingId)->delete();
            }

            $this->collapseToSingleRole();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::transaction(function () {
            foreach (self::SPLITS as $legacy => $replacements) {
                $this->mergePermissions($replacements, $legacy);
            }

            $systemAdminId = $this->roleId(self::SYSTEM_ADMIN['name']);
            if ($systemAdminId !== null) {
                DB::table('model_has_roles')->where('role_id', $systemAdminId)->delete();
                DB::table('role_has_permissions')->where('role_id', $systemAdminId)->delete();
                DB::table('roles')->where('id', $systemAdminId)->delete();
            }

            DB::table('roles')
                ->where('name', self::SUPER_ADMIN['name'])
                ->where('guard_name', self::GUARD)
                ->update([
                    'name' => self::LEGACY_ADMIN['name'],
                    'label' => self::LEGACY_ADMIN['label'],
                    'description' => self::LEGACY_ADMIN['description'],
                    'updated_at' => now(),
                ]);

            if ($this->roleId(self::LEGACY_PENDING['name']) === null) {
                $this->insertRole(self::LEGACY_PENDING);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  array<int, string>  $replacements
     */
    private function splitPermission(string $legacy, array $replacements): void
    {
        $replacementIds = collect($replacements)->map(fn (string $name) => $this->ensurePermission($name));
        $legacyId = $this->permissionId($legacy);

        if ($legacyId === null) {
            return;
        }

        foreach (DB::table('role_has_permissions')->where('permission_id', $legacyId)->pluck('role_id') as $roleId) {
            $this->grantToRole((int) $roleId, $replacementIds);
        }

        $directHolders = DB::table('model_has_permissions')->where('permission_id', $legacyId)->get(['model_type', 'model_id']);
        foreach ($directHolders as $holder) {
            DB::table('model_has_permissions')->insertOrIgnore($replacementIds->map(fn (int $id) => [
                'permission_id' => $id,
                'model_type' => $holder->model_type,
                'model_id' => $holder->model_id,
            ])->all());
        }

        DB::table('role_has_permissions')->where('permission_id', $legacyId)->delete();
        DB::table('model_has_permissions')->where('permission_id', $legacyId)->delete();
        DB::table('permissions')->where('id', $legacyId)->delete();
    }

    /**
     * Only holders of every replacement get the legacy permission back, so
     * reversing never widens anyone's access.
     *
     * @param  array<int, string>  $replacements
     */
    private function mergePermissions(array $replacements, string $legacy): void
    {
        $legacyId = $this->ensurePermission($legacy);
        $replacementIds = DB::table('permissions')
            ->where('guard_name', self::GUARD)
            ->whereIn('name', $replacements)
            ->pluck('id');

        if ($replacementIds->count() === count($replacements)) {
            $roleIds = DB::table('role_has_permissions')
                ->whereIn('permission_id', $replacementIds)
                ->groupBy('role_id')
                ->havingRaw('COUNT(*) = ?', [count($replacements)])
                ->pluck('role_id');
            foreach ($roleIds as $roleId) {
                $this->grantToRole((int) $roleId, collect([$legacyId]));
            }

            $directHolders = DB::table('model_has_permissions')
                ->whereIn('permission_id', $replacementIds)
                ->groupBy('model_type', 'model_id')
                ->havingRaw('COUNT(*) = ?', [count($replacements)])
                ->get(['model_type', 'model_id']);
            foreach ($directHolders as $holder) {
                DB::table('model_has_permissions')->insertOrIgnore([
                    'permission_id' => $legacyId,
                    'model_type' => $holder->model_type,
                    'model_id' => $holder->model_id,
                ]);
            }
        }

        DB::table('role_has_permissions')->whereIn('permission_id', $replacementIds)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $replacementIds)->delete();
        DB::table('permissions')->whereIn('id', $replacementIds)->delete();
    }

    private function collapseToSingleRole(): void
    {
        $permissionCounts = DB::table('role_has_permissions')
            ->select('role_id', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('role_id')
            ->pluck('aggregate', 'role_id');

        DB::table('model_has_roles')
            ->get(['role_id', 'model_type', 'model_id'])
            ->groupBy(fn ($row) => $row->model_type."\0".$row->model_id)
            ->filter(fn (Collection $rows) => $rows->count() > 1)
            ->each(function (Collection $rows) use ($permissionCounts) {
                $keep = (int) $rows
                    ->sort(fn ($a, $b) => [(int) ($permissionCounts[$b->role_id] ?? 0), (int) $a->role_id]
                        <=> [(int) ($permissionCounts[$a->role_id] ?? 0), (int) $b->role_id])
                    ->first()
                    ->role_id;

                DB::table('model_has_roles')
                    ->where('model_type', $rows->first()->model_type)
                    ->where('model_id', $rows->first()->model_id)
                    ->where('role_id', '!=', $keep)
                    ->delete();
            });
    }

    /**
     * @param  Collection<int, int|string>  $permissionIds
     */
    private function grantToRole(int $roleId, Collection $permissionIds): void
    {
        DB::table('role_has_permissions')->insertOrIgnore($permissionIds->map(fn ($id) => [
            'permission_id' => (int) $id,
            'role_id' => $roleId,
        ])->values()->all());
    }

    private function ensurePermission(string $name): int
    {
        return $this->permissionId($name) ?? DB::table('permissions')->insertGetId([
            'name' => $name,
            'guard_name' => self::GUARD,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function permissionId(string $name): ?int
    {
        $id = DB::table('permissions')->where('name', $name)->where('guard_name', self::GUARD)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function roleId(string $name): ?int
    {
        $id = DB::table('roles')->where('name', $name)->where('guard_name', self::GUARD)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  array{name: string, label: string, description: string}  $role
     */
    private function insertRole(array $role): int
    {
        return DB::table('roles')->insertGetId([
            ...$role,
            'guard_name' => self::GUARD,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
