<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const GUARD = 'web';

    private const VIEW_ALL = [
        'stakeholder_profile.view_all' => ['stakeholder_profile.view', 'stakeholder_profile.edit'],
        'internet_connectivity.view_all' => ['internet_connectivity.view', 'internet_connectivity.edit'],
    ];

    private const VIEW_ALL_RESTORE_ROLES = ['super_administrator', 'system_administrator'];

    private const DIVISION_ENCODER = [
        'label' => 'Division Encoder',
        'description' => 'Day-to-day data entry across every office: can view/create/edit Personnel, Equipment, and '.
            'ISP account records, log accountability transactions and ISP speed test/subscription cost entries, '.
            'but cannot delete records or manage reference data/users.',
        'data_scope' => 'all',
    ];

    private const LEGACY_ENCODER = [
        'label' => 'Encoder',
        'description' => 'Day-to-day data entry: can view/create/edit Personnel, Equipment, and ISP account '.
            'records, log accountability transactions and ISP speed test/subscription cost entries, but '.
            'cannot delete records or manage reference data/users.',
    ];

    private const OFFICE_ENCODER = [
        'name' => 'office_encoder',
        'label' => 'Office Encoder',
        'description' => 'Day-to-day data entry limited to records at the user\'s own office: can view/create/edit '.
            'Personnel, Equipment, and ISP account records, log accountability transactions and ISP speed '.
            'test/subscription cost entries, but cannot delete records or manage reference data/users.',
        'data_scope' => 'school',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $changes = array_filter([
                'view_all_replaced' => array_values(array_filter(array_map(
                    fn (string $viewAll) => $this->replaceViewAll($viewAll, self::VIEW_ALL[$viewAll]),
                    array_keys(self::VIEW_ALL),
                ))),
                ...$this->splitEncoder(),
            ]);

            if ($changes !== []) {
                $this->audit($changes);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::transaction(function () {
            $roleIds = DB::table('roles')
                ->where('guard_name', self::GUARD)
                ->whereIn('name', self::VIEW_ALL_RESTORE_ROLES)
                ->pluck('id');

            foreach (array_keys(self::VIEW_ALL) as $viewAll) {
                $permissionId = $this->ensurePermission($viewAll);
                foreach ($roleIds as $roleId) {
                    $this->grantToRole((int) $roleId, [$permissionId]);
                }
            }

            $officeEncoderId = $this->roleId(self::OFFICE_ENCODER['name']);
            if ($officeEncoderId !== null && ! DB::table('model_has_roles')->where('role_id', $officeEncoderId)->exists()) {
                DB::table('role_has_permissions')->where('role_id', $officeEncoderId)->delete();
                DB::table('roles')->where('id', $officeEncoderId)->delete();
            }

            DB::table('roles')
                ->where('name', 'encoder')
                ->where('guard_name', self::GUARD)
                ->update([...self::LEGACY_ENCODER, 'updated_at' => now()]);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<string, mixed>
     */
    private function splitEncoder(): array
    {
        $encoder = DB::table('roles')->where('name', 'encoder')->where('guard_name', self::GUARD)->first();
        if ($encoder === null) {
            return [];
        }

        $changes = [];
        $before = ['label' => $encoder->label, 'description' => $encoder->description, 'data_scope' => $encoder->data_scope];

        if ($before !== self::DIVISION_ENCODER) {
            DB::table('roles')->where('id', $encoder->id)->update([
                ...self::DIVISION_ENCODER,
                'updated_at' => now(),
            ]);

            $changes['encoder_relabeled'] = [
                'role_id' => (int) $encoder->id,
                'role_name' => $encoder->name,
                'old' => $before,
                'attributes' => self::DIVISION_ENCODER,
            ];
        }

        if ($this->roleId(self::OFFICE_ENCODER['name']) !== null) {
            return $changes;
        }

        $officeEncoderId = DB::table('roles')->insertGetId([
            ...self::OFFICE_ENCODER,
            'guard_name' => self::GUARD,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->grantToRole(
            $officeEncoderId,
            DB::table('role_has_permissions')->where('role_id', $encoder->id)->pluck('permission_id'),
        );

        $changes['office_encoder_created'] = [
            'role_id' => $officeEncoderId,
            'role_name' => self::OFFICE_ENCODER['name'],
            'data_scope' => self::OFFICE_ENCODER['data_scope'],
            'permissions_copied_from_role_id' => (int) $encoder->id,
            'permissions' => DB::table('role_has_permissions')
                ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                ->where('role_has_permissions.role_id', $officeEncoderId)
                ->orderBy('permissions.name')
                ->pluck('permissions.name')
                ->all(),
        ];

        return $changes;
    }

    /**
     * @param  array<int, string>  $replacements
     * @return array<string, mixed>|null
     */
    private function replaceViewAll(string $viewAll, array $replacements): ?array
    {
        $viewAllId = $this->permissionId($viewAll);
        if ($viewAllId === null) {
            return null;
        }

        $replacementIds = collect($replacements)->map(fn (string $name) => $this->ensurePermission($name));

        $roles = DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->where('role_has_permissions.permission_id', $viewAllId)
            ->orderBy('roles.id')
            ->get(['roles.id', 'roles.name']);

        foreach ($roles as $role) {
            $this->grantToRole((int) $role->id, $replacementIds);
        }

        $directHolders = DB::table('model_has_permissions')->where('permission_id', $viewAllId)->get(['model_type', 'model_id']);
        foreach ($directHolders as $holder) {
            DB::table('model_has_permissions')->insertOrIgnore($replacementIds->map(fn (int $id) => [
                'permission_id' => $id,
                'model_type' => $holder->model_type,
                'model_id' => $holder->model_id,
            ])->all());
        }

        DB::table('role_has_permissions')->where('permission_id', $viewAllId)->delete();
        DB::table('model_has_permissions')->where('permission_id', $viewAllId)->delete();
        DB::table('permissions')->where('id', $viewAllId)->delete();

        return [
            'permission' => $viewAll,
            'replaced_with' => $replacements,
            'roles' => $roles->map(fn ($role) => ['id' => (int) $role->id, 'name' => $role->name])->all(),
            'direct_holders' => $directHolders->map(fn ($holder) => [
                'model_type' => $holder->model_type,
                'model_id' => (int) $holder->model_id,
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function audit(array $properties): void
    {
        $migration = pathinfo(__FILE__, PATHINFO_FILENAME);

        DB::table('activity_log')->insert([
            'log_name' => 'default',
            'description' => $migration,
            'event' => 'system.data_scope_migration',
            'properties' => json_encode(['migration' => $migration, ...$properties]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  iterable<int|string>  $permissionIds
     */
    private function grantToRole(int $roleId, iterable $permissionIds): void
    {
        $rows = collect($permissionIds)->map(fn ($id) => [
            'permission_id' => (int) $id,
            'role_id' => $roleId,
        ])->values()->all();

        if ($rows !== []) {
            DB::table('role_has_permissions')->insertOrIgnore($rows);
        }
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
};
