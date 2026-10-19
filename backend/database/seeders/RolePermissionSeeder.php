<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the `permissions` table from App\Enums\Permission (the actual
 * source of truth — see that enum's doc-comment) and composes three
 * default roles from them. Roles are named bundles of permissions; the
 * bundle is data assembled here, never a branch in application code.
 *
 * Idempotent: safe to re-run without truncating first (findOrCreate/
 * updateOrCreate + syncPermissions rather than insert). Runs on
 * spatie/laravel-permission's tables, guard `web`.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * @return array<string, array{label: string, description: string, permissions: array<int, PermissionEnum>}>
     */
    private static function roleDefinitions(): array
    {
        return [
            // Zero permissions, deliberately (protected by Role::PENDING /
            // RoleService). Self-registration is gone (accounts are
            // admin-created), but an account parked here still reaches
            // nothing: every Policy check bottoms out at a permission it
            // doesn't hold.
            'pending' => [
                'label' => 'Pending Approval',
                'description' => 'No permissions. Grants access to nothing until an Administrator assigns a '.
                    'real role.',
                'permissions' => [],
            ],
            'admin' => [
                'label' => 'Administrator',
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
                    // Deliberately no Office::* here. Office is
                    // admin-curated institutional data (95 real division
                    // rows, imported once from a workbook, rarely added/
                    // edited day-to-day) — closer to ReferenceDataManage
                    // (admin-only) than to Equipment's day-to-day CRUD. No
                    // existing encoder/viewer-facing screen displays an
                    // Office picklist today either: the only current
                    // consumers of Office data (UserController's
                    // create/index, StakeholderProfile/
                    // InternetConnectivitySurvey's cross-office index) are
                    // all already gated behind admin-only permissions
                    // (users.manage, stakeholder_profile.view_all,
                    // internet_connectivity.view_all), so extending
                    // OfficeView here would be speculative, not
                    // precedent-based — see Permission's own doc-comment
                    // ("no speculative permissions for unbuilt
                    // functionality").
                ],
            ],
            'viewer' => [
                'label' => 'Viewer',
                'description' => 'Read-only access to Personnel, Equipment, ISP account, Stakeholder Profile, and '.
                    'Internet Connectivity Survey records.',
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
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        // 1. Materialize every enum case into a `permissions` row.
        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        $roles = self::roleDefinitions();

        // 2. Create/update each role and sync its permission set.
        foreach ($roles as $name => $definition) {
            $role = Role::query()->updateOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['label' => $definition['label'], 'description' => $definition['description']],
            );

            $role->syncPermissions(array_map(
                fn (PermissionEnum $permission) => $permission->value,
                $definition['permissions'],
            ));
        }

        $registrar->forgetCachedPermissions();

        $this->command->info(sprintf(
            'Seeded %d permissions and %d roles.',
            count(PermissionEnum::cases()),
            count($roles),
        ));
    }
}
