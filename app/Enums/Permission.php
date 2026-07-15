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

    // Assigning an EXISTING role to a user (the users.manage admin
    // screen — UserController/UserRoleService).
    case UsersManage = 'users.manage';

    // Creating/editing/deleting ROLES themselves — i.e. deciding which
    // permissions a role bundle grants (RoleController/RoleService).
    // Deliberately a SEPARATE permission from UsersManage, not folded
    // into it: assigning a user an existing, already-vetted role is a
    // materially less sensitive act than being able to grant that role
    // (or any role) NEW permissions, which is effectively a privilege-
    // escalation capability — a UsersManage holder should not
    // automatically be able to redefine what every role means. Only
    // 'admin' is seeded with this permission (see
    // RolePermissionSeeder); it rides along automatically via
    // PermissionEnum::cases() the same way every other permission does
    // for that role.
    case RolesManage = 'roles.manage';

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

    /**
     * Human-readable label for a future permission-management UI.
     */
    public function label(): string
    {
        return Str::of($this->value)->replace('.', ' ')->replace('_', ' ')->title()->toString();
    }
}
