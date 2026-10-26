<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Backed enum for every granular permission string checked in this
 * application — the single source of truth for what a "permission" is.
 * Application code NEVER checks a role name; it always checks one of
 * these strings via `$user->can(Permission::EquipmentDelete->value)` (or
 * the equivalent Policy method, which itself defers to a permission
 * check).
 *
 * `permissions.name` rows are materialized from this enum by
 * RolePermissionSeeder — an unknown/typo'd permission name blows up at
 * seed time via Permission::from(), not silently.
 *
 * Only permissions for features that actually exist in the app today are
 * defined here — no speculative permissions for unbuilt functionality.
 */
enum Permission: string
{
    // Personnel
    case PersonnelView = 'personnel.view';
    case PersonnelCreate = 'personnel.create';
    case PersonnelEdit = 'personnel.edit';
    case PersonnelDelete = 'personnel.delete';

    // Equipment
    case EquipmentView = 'equipment.view';
    case EquipmentCreate = 'equipment.create';
    case EquipmentEdit = 'equipment.edit';
    case EquipmentDelete = 'equipment.delete';

    // Recording an equipment_transactions row is what actually reassigns
    // accountability for a physical asset — a distinct, more sensitive
    // action than editing Equipment's own descriptive fields, so it gets
    // its own permission rather than riding along with EquipmentEdit.
    case EquipmentTransactionsCreate = 'equipment.transactions.create';

    // ISP accounts (government budget/procurement records for internet
    // service subscriptions).
    case IspAccountsView = 'isp_accounts.view';
    case IspAccountsCreate = 'isp_accounts.create';
    case IspAccountsEdit = 'isp_accounts.edit';
    case IspAccountsDelete = 'isp_accounts.delete';

    // Deliberately no separate permission for logging an IspSpeedTest or
    // IspSubscriptionCost row. Unlike EquipmentTransactionsCreate — which
    // reassigns accountability for a physical asset to a specific person,
    // a materially more sensitive act than editing the asset's own
    // descriptive fields — a speed test/subscription-cost entry is just an
    // append-only measurement/cost log scoped to its parent IspAccount. It
    // carries no accountability-transfer semantics, so it rides along with
    // IspAccountsEdit: whoever may edit the ISP account may also log
    // readings against it. Backend authorizes
    // IspSpeedTest/IspSubscriptionCost store actions against the parent
    // IspAccount via IspAccountPolicy::update(), the same way equipment
    // transactions authorize against the parent Equipment.

    // One umbrella permission for all 13 low-stakes lookup tables (see the lookup-normalization ADR).
    case ReferenceDataManage = 'reference-data.manage';

    // User management - users are synced from PRIME, cannot be created or deleted locally.
    // Only role assignment, office assignment, and account status can be managed.
    case UsersView = 'users.view';
    case UsersEdit = 'users.edit';
    case UsersResetTwoFactor = 'users.reset_two_factor';

    // Role management (creating/editing/deleting role definitions).
    case RolesView = 'roles.view';
    case RolesCreate = 'roles.create';
    case RolesEdit = 'roles.edit';
    case RolesDelete = 'roles.delete';

    // One profile per office, always existing, so no .create/.delete. Reach follows the role's data scope.
    case StakeholderProfileView = 'stakeholder_profile.view';
    case StakeholderProfileEdit = 'stakeholder_profile.edit';

    // Internet Connectivity Survey (one row per office). Reach follows the role's data scope.
    case InternetConnectivityView = 'internet_connectivity.view';
    case InternetConnectivityEdit = 'internet_connectivity.edit';

    // Separate from ReferenceDataManage: other records depend on offices (restrictOnDelete FKs).
    case OfficeView = 'office.view';
    case OfficeCreate = 'office.create';
    case OfficeEdit = 'office.edit';
    case OfficeDelete = 'office.delete';

    // Exposes every resource's PII through audit diffs regardless of per-resource .view permissions.
    case AuditLogView = 'audit_log.view';

    // Rate limits, trusted-device days and idle sign-out (SecuritySettings,
    // GET/PUT /api/settings/security). Admin-only.
    case SecuritySettingsManage = 'security_settings.manage';

    /**
     * Human-readable label for the permission picker.
     */
    public function label(): string
    {
        return Str::of($this->value)->replace('.', ' ')->replace('_', ' ')->title()->toString();
    }

    /**
     * Section heading the role editor's permission picker groups this
     * permission under (GET /api/permissions), so the grouping comes from
     * the server instead of a duplicated frontend list.
     */
    public function group(): string
    {
        return match ($this) {
            self::PersonnelView, self::PersonnelCreate, self::PersonnelEdit, self::PersonnelDelete => 'Personnel',
            self::EquipmentView, self::EquipmentCreate, self::EquipmentEdit, self::EquipmentDelete,
            self::EquipmentTransactionsCreate => 'Equipment',
            self::IspAccountsView, self::IspAccountsCreate, self::IspAccountsEdit, self::IspAccountsDelete => 'ISP Accounts',
            self::OfficeView, self::OfficeCreate, self::OfficeEdit, self::OfficeDelete => 'Offices',
            self::StakeholderProfileView, self::StakeholderProfileEdit => 'Stakeholder Profile',
            self::InternetConnectivityView, self::InternetConnectivityEdit => 'Internet Connectivity',
            self::ReferenceDataManage, self::UsersView, self::UsersEdit,
            self::UsersResetTwoFactor, self::RolesView, self::RolesCreate,
            self::RolesEdit, self::RolesDelete, self::AuditLogView,
            self::SecuritySettingsManage => 'Administration',
        };
    }
}
