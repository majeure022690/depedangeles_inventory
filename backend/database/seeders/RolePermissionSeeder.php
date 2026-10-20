<?php

namespace Database\Seeders;

use App\Enums\DataScope;
use App\Enums\Permission as PermissionEnum;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the `permissions` table from App\Enums\Permission and composes the
 * four default roles from it. Idempotent; guard `web`.
 */
class RolePermissionSeeder extends Seeder
{
    private const SYSTEM_ADMIN_EXCLUDED = ['roles.create', 'roles.edit', 'roles.delete'];

    /**
     * @return array<string, array{label: string, description: string, permissions: array<int, string>}>
     */
    private static function roleDefinitions(): array
    {
        $all = array_map(fn (PermissionEnum $permission) => $permission->value, PermissionEnum::cases());

        return [
            'super_administrator' => [
                'label' => 'Super Administrator',
                'description' => 'Every permission, always. Cannot be renamed, deleted, or have permissions removed.',
                'permissions' => $all,
            ],
            'system_administrator' => [
                'label' => 'System Administrator',
                'description' => 'Every permission except creating, editing, or deleting roles.',
                'permissions' => array_values(array_diff($all, self::SYSTEM_ADMIN_EXCLUDED)),
            ],
            'encoder' => [
                'label' => 'Encoder',
                'description' => 'Day-to-day data entry: can view/create/edit Personnel, Equipment, and ISP account '.
                    'records, log accountability transactions and ISP speed test/subscription cost entries, but '.
                    'cannot delete records or manage reference data/users.',
                'permissions' => [
                    PermissionEnum::PersonnelView->value,
                    PermissionEnum::PersonnelCreate->value,
                    PermissionEnum::PersonnelEdit->value,
                    PermissionEnum::EquipmentView->value,
                    PermissionEnum::EquipmentCreate->value,
                    PermissionEnum::EquipmentEdit->value,
                    PermissionEnum::EquipmentTransactionsCreate->value,
                    PermissionEnum::IspAccountsView->value,
                    PermissionEnum::IspAccountsCreate->value,
                    PermissionEnum::IspAccountsEdit->value,
                    PermissionEnum::StakeholderProfileView->value,
                    PermissionEnum::StakeholderProfileEdit->value,
                    PermissionEnum::InternetConnectivityView->value,
                    PermissionEnum::InternetConnectivityEdit->value,
                ],
            ],
            'viewer' => [
                'label' => 'Viewer',
                'data_scope' => DataScope::Own,
                'description' => 'Read-only access to Personnel, Equipment, Stakeholder Profile, and '.
                    'Internet Connectivity Survey records.',
                'permissions' => [
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
            $role = Role::query()->updateOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['label' => $definition['label'], 'description' => $definition['description'], 'data_scope' => $definition['data_scope'] ?? DataScope::All],
            );

            $role->syncPermissions($definition['permissions']);
        }

        $registrar->forgetCachedPermissions();

        $this->command->info(sprintf(
            'Seeded %d permissions and %d roles.',
            count(PermissionEnum::cases()),
            count($roles),
        ));
    }
}
