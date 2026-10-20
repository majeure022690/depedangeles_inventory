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

    // Reference-data admin (item types, brands, equipment categories/
    // classifications/conditions, positions, RO/SDO offices, ISP
    // providers, and the four domain-grouped library tables — all 13
    // tables from the lookup-normalization ADR). Deliberately ONE
    // umbrella permission covering all 13 tables, not 13 fragmented
    // permissions: per the ADR (Question 1, "why not 35 dedicated
    // tables") and CLAUDE.md's granular-permission rule — which targets
    // role-name checks vs. capability checks, not fragmenting one
    // coherent capability into many — these 13 tables remain one kind of
    // low-stakes reference-data admin capability. Supersedes the old
    // lookups.manage permission (removed in the ADR's Step 4 cleanup,
    // alongside LookupController/lookups/Index.vue) as the permission
    // Backend's reference-data admin screen authorizes against.
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

    // Stakeholder Profile (one row PER OFFICE — see
    // App\Models\StakeholderProfile — not a global singleton). No
    // .create/.delete permissions: there is no "create a new one" or
    // "delete it" action, only view/edit of an office's always-existing
    // record (Backend uses StakeholderProfile::firstOrCreate(['office_id'
    // => ...])). Unlike LookupsManage (a single permission for an
    // admin-only screen), this still gets a separate .view permission
    // alongside .edit: 'viewer' is an established read-only role spanning
    // every other resource in this app (Personnel, Equipment, ISP
    // accounts), and this record holds division contact/address
    // information a non-editing stakeholder (e.g. a higher office or an
    // auditor) may legitimately need to read without being able to change
    // it — the "Viewer role should be able to read without editing" case.
    // .view/.edit are scoped to the ACTING USER'S OWN office_id
    // (StakeholderProfilePolicy) — holding .view lets someone read only
    // their own school's profile, never another school's.
    case StakeholderProfileView = 'stakeholder_profile.view';
    case StakeholderProfileEdit = 'stakeholder_profile.edit';

    // Cross-office visibility: view AND edit every office's stakeholder
    // profile, plus the admin list of all of them
    // (stakeholder-profiles.index). Deliberately separate from
    // .view/.edit above (which are always scoped to the acting user's own
    // office_id, even for an actor who happens to also hold this
    // permission) — this is the "division-level oversight" capability,
    // analogous to reference-data.manage. Only 'admin' is seeded with it.
    case StakeholderProfileViewAll = 'stakeholder_profile.view_all';

    // Internet Connectivity Survey (one row per office). .view/.edit are
    // scoped to the acting user's own office_id (InternetConnectivitySurveyPolicy).
    case InternetConnectivityView = 'internet_connectivity.view';
    case InternetConnectivityEdit = 'internet_connectivity.edit';

    // Cross-office oversight: bypasses the office scoping above and gates
    // the admin list. Only 'admin' is seeded with it.
    case InternetConnectivityViewAll = 'internet_connectivity.view_all';

    // Office (schools/division offices — the FK backbone for users,
    // stakeholder_profiles, and internet_connectivity_surveys). Deliberately
    // its own permission bundle, not folded into ReferenceDataManage: unlike
    // the 13 low-stakes lookup tables under that umbrella, Office is a
    // structural resource other records depend on (restrictOnDelete FKs),
    // so it's authorized the same granular way as Equipment/Personnel.
    case OfficeView = 'office.view';
    case OfficeCreate = 'office.create';
    case OfficeEdit = 'office.edit';
    case OfficeDelete = 'office.delete';

    // Audit log (read-only, append-only trail of role/permission/record
    // changes across the whole app, incl. authorization.denied entries).
    // Admin-only, same shape as ReferenceDataManage: one flat permission,
    // no Policy class (no single model instance to authorize against —
    // AuditLogController::index() checks this permission string directly).
    // Deliberately NOT paired with per-resource .view permissions: a role
    // holding this alone would see other resources' PII inside `properties`
    // diffs without holding e.g. personnel.view. Safe today since only
    // 'admin' (which already holds every .view permission) can grant it —
    // keep it that way if a custom-role feature ever changes who can seed
    // roles/permissions.
    case AuditLogView = 'audit_log.view';

    // Rate limits, trusted-device days and idle sign-out (SecuritySettings,
    // GET/PUT /api/settings/security). Admin-only.
    case SecuritySettingsManage = 'security_settings.manage';

    // Outbound email log (view/clear email_messages). Separate from
    // SecuritySettingsManage because entries hold recipient addresses.
    // Admin-only.
    case EmailLogManage = 'email_log.manage';

    /**
     * Human-readable label for the permission picker.
     */
    public function label(): string
    {
        // .view_all also grants cross-office edit (the office-scoped Policies).
        return match ($this) {
            self::StakeholderProfileViewAll => 'Stakeholder Profile — view & edit all offices',
            self::InternetConnectivityViewAll => 'Internet Connectivity — view & edit all offices',
            default => $this->defaultLabel(),
        };
    }

    private function defaultLabel(): string
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
            self::StakeholderProfileView, self::StakeholderProfileEdit, self::StakeholderProfileViewAll => 'Stakeholder Profile',
            self::InternetConnectivityView, self::InternetConnectivityEdit,
            self::InternetConnectivityViewAll => 'Internet Connectivity',
            self::ReferenceDataManage, self::UsersView, self::UsersEdit, 
            self::UsersResetTwoFactor, self::RolesView, self::RolesCreate, 
            self::RolesEdit, self::RolesDelete, self::AuditLogView,
            self::SecuritySettingsManage, self::EmailLogManage => 'Administration',
        };
    }
}
