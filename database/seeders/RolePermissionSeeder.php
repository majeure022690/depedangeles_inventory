<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds the `permissions` table from App\Enums\Permission (the actual
 * source of truth — see that enum's doc-comment) and composes three
 * default roles from them. Roles are named bundles of permissions; the
 * bundle is data assembled here, never a branch in application code.
 *
 * Idempotent: safe to re-run without truncating first (updateOrCreate +
 * sync rather than insert).
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * @return array<string, array{label: string, description: string, permissions: array<int, PermissionEnum>}>
     */
    private static function roleDefinitions(): array
    {
        return [
            // Deny-by-default holding pen for self-registered accounts.
            // Zero permissions, deliberately — this is not "least
            // privilege", it's NO privilege, until a Division ICT Admin
            // reviews the account and assigns a real role. See
            // CreateNewUser, which grants this role (never 'viewer') to
            // every new self-registration. A user whose only role is this
            // one has an empty permissionNames() list, so every Policy/
            // Gate check bottoms out at User::hasPermissionTo() returning
            // false — verified end-to-end in
            // tests/Feature/Auth/RegistrationApprovalTest.php.
            'pending' => [
                'label' => 'Pending Approval',
                'description' => 'No permissions. Automatically assigned to every new self-registration; '.
                    'grants access to nothing until a Division ICT Admin reviews the account and assigns a '.
                    'real role.',
                'permissions' => [],
            ],
            'division-ict-admin' => [
                'label' => 'Division ICT Admin',
                'description' => 'Full access — all permissions, including reference-data, role, and user '.
                    'administration.',
                // Every permission that exists — this is the only role
                // that should have Permission::ReferenceDataManage, per
                // the lookup-normalization ADR (Step 2 item 8). It rides
                // along automatically via ::cases(); no other role
                // definition below lists it. Permission::RolesManage
                // (roles.manage — create/edit/delete role definitions,
                // distinct from and more sensitive than users.manage's
                // "assign an existing role to a user") is seeded here the
                // same automatic way — this is the ONLY role that should
                // hold it, since granting roles.manage effectively grants
                // the ability to redefine what every other role means.
                'permissions' => PermissionEnum::cases(),
            ],
            'encoder' => [
                'label' => 'Encoder',
                'description' => 'Day-to-day data entry: can view/create/edit Personnel, Equipment, and ISP account '.
                    'records, log accountability transactions and ISP speed test/subscription cost entries, but '.
                    'cannot delete records or manage reference data/users.',
                'permissions' => [
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
                    // Stakeholder Profile / Internet Connectivity Survey are
                    // basic operational records (division contact/address
                    // info, ISP/connectivity survey data) an encoder
                    // maintains day-to-day — not consequential like
                    // deleting equipment or managing users/reference data, so they
                    // ride along with the rest of encoder's view+edit
                    // access rather than being admin-only.
                    PermissionEnum::StakeholderProfileView,
                    PermissionEnum::StakeholderProfileEdit,
                    PermissionEnum::InternetConnectivityView,
                    PermissionEnum::InternetConnectivityEdit,
                ],
            ],
            'viewer' => [
                'label' => 'Viewer',
                'description' => 'Read-only access to Personnel, Equipment, ISP account, Stakeholder Profile, and '.
                    'Internet Connectivity Survey records. Assigned manually by a Division ICT Admin once an '.
                    'account is approved — never granted automatically to new self-registrations (see \'pending\').',
                'permissions' => [
                    PermissionEnum::PersonnelView,
                    PermissionEnum::EquipmentView,
                    PermissionEnum::IspAccountsView,
                    // View-only, no *Edit: mirrors the read-only pattern
                    // above for the other three resources. See
                    // Permission::StakeholderProfileView's doc-comment for
                    // why these singletons get a separate .view permission
                    // in the first place.
                    PermissionEnum::StakeholderProfileView,
                    PermissionEnum::InternetConnectivityView,
                ],
            ],
        ];
    }

    public function run(): void
    {
        // 1. Materialize every enum case into a `permissions` row.
        $permissionModelsByName = collect(PermissionEnum::cases())
            ->mapWithKeys(function (PermissionEnum $permission) {
                $model = Permission::query()->updateOrCreate(['name' => $permission->value]);

                return [$permission->value => $model];
            });

        $roles = self::roleDefinitions();

        // 2. Create/update each role and sync its permission set.
        foreach ($roles as $name => $definition) {
            $role = Role::query()->updateOrCreate(
                ['name' => $name],
                ['label' => $definition['label'], 'description' => $definition['description']],
            );

            $permissionIds = collect($definition['permissions'])
                ->map(fn (PermissionEnum $permission) => $permissionModelsByName[$permission->value]->id)
                ->all();

            $role->permissions()->sync($permissionIds);
        }

        $this->command?->info(sprintf(
            'Seeded %d permissions and %d roles.',
            $permissionModelsByName->count(),
            count($roles),
        ));
    }
}
