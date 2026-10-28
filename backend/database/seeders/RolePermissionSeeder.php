<?php

namespace Database\Seeders;

use App\Enums\DataScope;
use App\Enums\Permission as PermissionEnum;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    private const SUPER_ADMIN = 'super_administrator';

    private const SYSTEM_ADMIN_EXCLUDED = ['roles.create', 'roles.edit', 'roles.delete'];

    private const EQUIPMENT_DASHBOARD_PERMISSIONS = [
        PermissionEnum::DashboardPpe,
        PermissionEnum::DashboardSemiExpendable,
        PermissionEnum::DashboardEquipmentOverview,
        PermissionEnum::DashboardEquipmentDistribution,
    ];

    private const ENCODER_PERMISSIONS = [
        ...self::EQUIPMENT_DASHBOARD_PERMISSIONS,
        PermissionEnum::DashboardInternetConnectivity,
        PermissionEnum::PersonnelView,
        PermissionEnum::PersonnelCreate,
        PermissionEnum::PersonnelEdit,
        PermissionEnum::EquipmentView,
        PermissionEnum::EquipmentCreate,
        PermissionEnum::EquipmentEdit,
        PermissionEnum::EquipmentTransactionsCreate,
        PermissionEnum::IspAccountsView,
        PermissionEnum::IspAccountsCreate,
        PermissionEnum::IspAccountsEdit,
        PermissionEnum::StakeholderProfileView,
        PermissionEnum::StakeholderProfileEdit,
        PermissionEnum::InternetConnectivityView,
        PermissionEnum::InternetConnectivityEdit,
    ];

    /**
     * @return array<string, array{label: string, description: string, data_scope: DataScope, permissions: array<int, string>}>
     */
    private static function roleDefinitions(): array
    {
        $all = array_map(fn (PermissionEnum $permission) => $permission->value, PermissionEnum::cases());
        $encoder = array_map(fn (PermissionEnum $permission) => $permission->value, self::ENCODER_PERMISSIONS);

        return [
            self::SUPER_ADMIN => [
                'label' => 'Super Administrator',
                'description' => 'Every permission, always. Cannot be renamed, deleted, or have permissions removed.',
                'data_scope' => DataScope::All,
                'permissions' => $all,
            ],
            'system_administrator' => [
                'label' => 'System Administrator',
                'description' => 'Every permission except creating, editing, or deleting roles.',
                'data_scope' => DataScope::All,
                'permissions' => array_values(array_diff($all, self::SYSTEM_ADMIN_EXCLUDED)),
            ],
            'encoder' => [
                'label' => 'Division Encoder',
                'description' => 'Day-to-day data entry across every office: can view/create/edit Personnel, '.
                    'Equipment, and ISP account records, log accountability transactions and ISP speed '.
                    'test/subscription cost entries, but cannot delete records or manage reference data/users.',
                'data_scope' => DataScope::All,
                'permissions' => $encoder,
            ],
            'office_encoder' => [
                'label' => 'Office Encoder',
                'description' => 'Day-to-day data entry limited to records at the user\'s own office: can '.
                    'view/create/edit Personnel, Equipment, and ISP account records, log accountability '.
                    'transactions and ISP speed test/subscription cost entries, but cannot delete records or '.
                    'manage reference data/users.',
                'data_scope' => DataScope::School,
                'permissions' => $encoder,
            ],
            'viewer' => [
                'label' => 'Viewer',
                'description' => 'Read-only access to Personnel, Equipment, Stakeholder Profile, and '.
                    'Internet Connectivity Survey records.',
                'data_scope' => DataScope::Own,
                'permissions' => [
                    ...array_map(fn (PermissionEnum $permission) => $permission->value, self::EQUIPMENT_DASHBOARD_PERMISSIONS),
                    PermissionEnum::PersonnelView->value,
                    PermissionEnum::EquipmentView->value,
                    PermissionEnum::StakeholderProfileView->value,
                    PermissionEnum::InternetConnectivityView->value,
                ],
            ],
        ];
    }

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        $roles = self::roleDefinitions();

        foreach ($roles as $name => $definition) {
            $attributes = [
                'label' => $definition['label'],
                'description' => $definition['description'],
                'data_scope' => $definition['data_scope'],
            ];

            if ($name === self::SUPER_ADMIN) {
                $role = Role::query()->updateOrCreate(['name' => $name, 'guard_name' => 'web'], $attributes);
                $role->syncPermissions($definition['permissions']);

                continue;
            }

            $role = Role::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web'], $attributes);

            if ($role->wasRecentlyCreated) {
                $role->syncPermissions($definition['permissions']);
            }
        }

        $registrar->forgetCachedPermissions();

        $this->command->info(sprintf(
            'Seeded %d permissions and %d roles.',
            count(PermissionEnum::cases()),
            count($roles),
        ));
    }
}
