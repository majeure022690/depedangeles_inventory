# Architecture

This document describes how the codebase is organized and the cross-cutting patterns used across every feature. For day-to-day feature behavior see [`docs/features/`](features); for setup see the root [`README.md`](../README.md).

## Stack

Laravel 13 + Inertia.js v3 + Vue 3, built on the official Laravel Vue Starter Kit. This is a **server-driven monolith, not a REST API** — every page is returned via `Inertia::render()` from `routes/web.php`, and there is no `routes/api.php` surface unless a genuine external consumer needs one (none exists today). See [`CLAUDE.md`](../CLAUDE.md) for the full stack list and global coding standards.

## Agent collaboration model

Development on this project is split across 11 specialist agents, each owning a distinct slice of the stack (`architect`, `backend`, `frontend`, `database`, `auth`, `integration`, `qa`, `devops`, `security`, `documentation`, `reviewer`). The roster, routing rules, and each agent's decision rules live in [`CLAUDE.md`](../CLAUDE.md) — this doc doesn't duplicate that table. The short version: work is delegated to the agent owning the dominant domain of a change, multi-domain features move through agents in dependency order (e.g. `database` → `backend` → `auth` → `frontend` → `qa` → `security` → `reviewer`), and nothing is "done" until QA and (where applicable) Security have signed off and `reviewer` gives final approval.

## Project structure: flat, not `app/Domain/<Feature>/`

The backend uses stock flat Laravel directories — `app/Models/`, `app/Http/Controllers/`, `app/Http/Requests/`, `app/Policies/`, `app/Services/`, `app/Enums/` — rather than per-domain folders. This was an explicit architect decision (2026-07-10), documented in full in [`CLAUDE.md`](../CLAUDE.md#project-structure--flat-stock-laravel-not-appdomainfeature). Summary of the reasoning: at this project's scale (~8 models, ~6 controllers, ~3 policies, ~2 services, one real bounded context) a `Domain/` split would add navigation depth without deduplication or clarity benefit. Organization instead comes from **consistent naming** (`EquipmentController` / `EquipmentPolicy` / `EquipmentStoreRequest` / `EquipmentUpdateRequest` all name the same resource) and from the frontend's already-natural `resources/js/pages/<feature>/` split, which has no backend equivalent forcing function.

Services/Actions are added only when a resource's business logic doesn't fit a controller method — today that's `EquipmentAccountabilityService` and `UserRoleService`, both described below.

## Authorization model: granular permissions, never role names

Every authorization check in this codebase resolves to a single method: `User::hasPermissionTo()` (`app/Models/User.php`), which checks a permission string against the flat, de-duplicated list of permissions granted by every role the user holds (`User::permissionNames()`, memoized per-request). Application code — Policies, Form Requests, Gate checks — never compares `$user->role` to a role name string.

```php
// Correct — every Policy method in this app looks like this:
public function delete(User $user, Equipment $equipment): bool
{
    return $user->hasPermissionTo(Permission::EquipmentDelete);
}

// Never done anywhere in this codebase:
if ($user->role === 'admin') { ... }
```

**Source of truth:** `App\Enums\Permission` — a backed enum, one case per granular permission string (`equipment.delete`, `personnel.view`, `users.manage`, etc.), 20 cases as of this writing. Only permissions for features that actually exist are defined — no speculative permissions for unbuilt functionality.

**Roles** (`App\Models\Role`) are named, **admin-configurable** bundles of permissions — not a fixed, code-defined set. `RolePermissionSeeder` seeds four starting roles as data, never a branch in application code; from there, a `roles.manage` holder can create new roles, edit which permissions any role grants, and delete roles no longer in use, via `/roles` (`RoleController`/`RoleService`). A user may hold more than one role at once (`role_user` is a genuine many-to-many pivot) — `/users` (`UserController`/`UserRoleService`) assigns a user's entire role *set*, not a single role.

| Role | Permissions |
|---|---|
| `pending` | **None.** Deny-by-default holding pen. Every self-registered account gets this role (never `viewer`) — see the RBAC section below. Protected: cannot be renamed, granted permissions, or deleted. |
| `viewer` | `*.view` across Personnel, Equipment, ISP Accounts, Stakeholder Profile, Internet Connectivity. Read-only. |
| `encoder` | View/create/edit on Personnel, Equipment, ISP Accounts, plus `equipment.transactions.create` and view/edit on Stakeholder Profile / Internet Connectivity. No deletes, no reference-data/role/user admin. |
| `admin` | Every permission that exists (`PermissionEnum::cases()`), including `reference-data.manage`, `roles.manage`, and `users.manage`. |

A user's flat permission list is shared to every Inertia page via `HandleInertiaRequests::share()` as `auth.permissions` (`app/Http/Middleware/HandleInertiaRequests.php`). The frontend never re-derives authorization: `resources/js/composables/usePermissions.ts` just reads that shared list and exposes `can()`/`canAny()`/`canAll()` for gating UI (hiding an Add/Edit/Delete button). The server is still the sole source of truth — every mutating request is re-checked against the same Policies regardless of what the client renders.

### Registration is deny-by-default, not opt-in

A security review (2026-07) flagged that open self-registration exposed real DepEd personnel PII to any verified email address with no admin vetting. The fix: every self-registration is assigned the zero-permission `pending` role (never `viewer`), so a new account is authenticated-and-verified but can access nothing until an Administrator reviews it and assigns a real role via `/users`. Regression-tested end-to-end in `tests/Feature/Auth/RegistrationApprovalTest.php`.

### Admin-configurable RBAC: `/roles` and `/users`, and the two-tier guard system

Roles started as four hardcoded, seeded bundles and have since become genuine admin-editable data, with multi-role users. Two separate admin screens and two separate permissions divide the resulting risk:

- **`/roles` (`roles.manage`, `RoleController`/`RoleService`)** — full CRUD over role *definitions*: create a role, change which permissions it grants, delete a role no longer in use. This is the more sensitive of the two, because editing a role's permission set effectively redefines access for every user who holds it.
- **`/users` (`users.manage`, `UserController`/`UserRoleService`)** — list users and sync one user's role *set* (`syncRoles()`, replacing the entire set in one call, since a user may hold more than one role). Assigning an already-vetted role is deliberately a less sensitive act than being able to invent or redefine one, so this is a separate, lower-tier permission.

Full detail — including the protected `pending` role, the "role still in use" delete guard, and the exact self-escalation / permission-tier-separation / last-holder-lockout guards enforced by each Service — lives in [`docs/features/roles-and-permissions.md`](features/roles-and-permissions.md). The short version: both Services enforce that a user can never change their own role(s) (self-escalation), that the last remaining holder of a sensitive permission (`roles.manage`/`users.manage`) can never be edited/reassigned out of holding it (lockout, generalized to "no role/user anywhere still grants it"), and — the RBAC overhaul's one genuinely new risk — that a `users.manage` holder can only assign roles whose permissions are already a subset of their own (permission-tier separation, closing a gap where `users.manage` alone could previously be used to grant `roles.manage` to any account). The lockout checks run inside their write transaction behind a row lock on the sensitive `Permission` row, closing a TOCTOU race a 2026-07 follow-up security review flagged in the original unlocked pre-write count.

Every role-definition change is audited via `AuditLog::record('role.created'/'role.updated'/'role.deleted', ...)`; every role-set change via `AuditLog::record('user.roles_changed', ...)`.

## Audit logging

`App\Models\AuditLog` is a minimal, append-only audit trail — proportionate to this project's size, not a general activity-log framework. `AuditLog::record()` is the single write path; nothing else inserts into `audit_logs` directly, and no row is ever updated or deleted (`$timestamps = false`, no `updated_at` column).

It covers: CRUD on every resource (created/updated/deleted actions per controller), role grants/revocations (`User::assignRole()`/`removeRole()`), and authorization denials on the most consequential action per resource (e.g. `EquipmentPolicy::delete()` logs `authorization.denied` when a non-permitted user is blocked, rather than 403ing silently).

**Actor-identity survival:** `actor_id` is a nullable, `nullOnDelete` foreign key — so the audit trail isn't blocked by a user being removed. But that same `nullOnDelete` means a self-deleted account would otherwise erase its own attribution from every row it produced. To prevent that, `AuditLog::record()` snapshots the actor's name/email into `properties.actor_snapshot` at write time (while the FK is still guaranteed valid), so the row stays meaningful even after `actor_id` later nulls out.

## Three data-access patterns

This app uses three distinct shapes for how a feature's data is created, changed, and read back. Recognizing which one a feature uses is the fastest way to understand its controller/model/migration design.

### 1. Accountability-transfer (Equipment)

Used where a physical asset needs both a **current state** (who holds it right now, for fast lookups/listing) and a **full history** of every time that state changed (for audit purposes). Two tables cooperate:

- `equipment` holds `current_accountable_officer_id` / `current_end_user_id` — cheap to query, always reflects "right now."
- `equipment_transactions` is an append-only log — one row per accountability-changing event, never updated or deleted.

The two must never drift apart. `Equipment` has a model-level guard (an `updating` hook in `app/Models/Equipment.php`) that throws `AccountabilitySyncRequiredException` if either `current_*` column is dirty outside a sanctioned path. The only sanctioned path is `App\Services\EquipmentAccountabilityService::recordTransaction()`, which writes the `equipment_transactions` row and updates `equipment.current_*` atomically inside one DB transaction (`Equipment::withAccountabilitySync()` flips a static flag that lets the update through). A controller cannot update the pointers directly — even by mistake — without the exception firing.

Sync policy is intentionally simple: the current holder is always "whoever the most recent transaction says it is," including being cleared to `null` when a transaction doesn't name one. No implicit "leave it unless told otherwise" branching per transaction type.

Recording a transaction is authorized separately from editing Equipment's own fields (`Permission::EquipmentTransactionsCreate` vs. `Permission::EquipmentEdit`) because reassigning accountability for a physical asset is judged more sensitive than editing its descriptive fields.

### 2. Append-only child log (ISP Accounts)

Used where a parent record needs a growing history of measurements or cost periods, but — unlike Equipment — there's no "current state" pointer on the parent that needs to stay in sync. `IspAccount` has two independent child logs: `isp_speed_tests` (bandwidth measurements over time) and `isp_subscription_costs` (contract/budget periods over time). Both are pure `store`-only routes with no update/destroy, and both authorize against the **parent** (`IspAccountPolicy::update()`), not a permission of their own — logging a reading is judged a normal part of "editing the ISP account," unlike Equipment's accountability transfer, which is a materially more sensitive act.

This is architecturally simpler than the accountability-transfer pattern precisely because there's nothing to keep in sync — no guard hook, no dedicated Service, just `$ispAccount->speedTests()->create(...)` / `$ispAccount->subscriptionCosts()->create(...)` directly in the controller.

### 3. Singleton (Internet Connectivity Survey)

Used for records where exactly one row should exist for the whole application — the division office's own connectivity survey, not a list of records per anything. `InternetConnectivitySurveyController` deliberately implements **only** `edit()`/`update()`: no `index`, no `create`/`store` (which would risk producing a second row), no `destroy()`. Routes are hand-declared (`routes/web.php`), not `Route::resource()`, and take no `{id}` parameter — there's only one record, always reached via `Model::firstOrCreate([])`.

A DB-level `singleton_guard` column (added retroactively by a 2026-07-11 migration) closes a race condition a security review flagged: without a unique constraint, two concurrent first-load requests could each pass the `SELECT` half of `firstOrCreate()` and both `INSERT`, producing a duplicate row. `singleton_guard` is an `unsignedTinyInteger` defaulting to `1` with a `unique()` index — every insert collides on it except the first, which engages Laravel's built-in `createOrFirst()` retry-and-return-existing-row path instead of throwing.

The Policy authorizes a transient (unsaved) model instance on `edit()` (`$this->authorize('view', new InternetConnectivitySurvey)`), checked *before* touching the database — so a `pending`-role user's `GET` doesn't create the singleton row before being 403'd.

### 3b. One row per tenant (Stakeholder Profile)

Stakeholder Profile started as a true global singleton (see the pattern above) but was converted (2026-07-14) to **one row per `Office`** — every school and division-level office/unit has its own profile, filled in by that office's own users, once the app grew to track multiple schools under the division (`offices` table) rather than a single division office. `StakeholderProfileController` still has no `create()`/`store()`/`destroy()` (a profile is always `StakeholderProfile::firstOrCreate(['office_id' => ...])`'d lazily, never explicitly created or deleted), but it gains an `index()` — an admin-only cross-office list — alongside `edit()`/`update()`, both always scoped to one specific `{office}` route parameter.

Access is scoped, not just the row: `StakeholderProfilePolicy` grants `view`/`update` either to the acting user's own office (`$user->office_id === $stakeholderProfile->office_id`, holding `.view`/`.edit`) or unconditionally to a `.view_all` holder (division-level oversight, seeded only to `admin`) — the same permission name (`stakeholder_profile.view`) means something different depending on *whose* office_id it's checked against, which is why the Policy — not just a permission-string check — is what actually enforces the boundary here.

What replaced the old race guard: `singleton_guard` no longer applies (there's no longer "exactly one row globally" to protect) — it's replaced by a plain `unique` index on `office_id` itself. MySQL/MariaDB unique indexes allow unlimited `NULL`s, but that's moot here since `office_id` is `NOT NULL` on this table; every row this app creates always belongs to a specific office, so the unique index alone is the complete guarantee ("at most one profile per office").

**Internet Connectivity Survey's live-computed aggregates:** the survey's summary panel (Total ISPs, Total Cost/month, Total Amount Spent, Total Projected Expenditure, Rooms Covered Admin/Classroom, Total Access Points) mirrors fields the source Excel sheet marked "Protected, source data from..." — i.e., derived values, not user input. None of these are columns on `internet_connectivity_surveys`; there's no FK to `isp_accounts` / `isp_subscription_costs` either. `InternetConnectivitySurveyController::computeAggregates()` queries `IspAccount`/`IspSubscriptionCost` fresh on every `edit()` load and passes the result as a separate, read-only `aggregates` Inertia prop — never part of the writable Form Request payload, never cached or stored. The one exception on that table is `rooms_other_use`, which genuinely is user-entered in the source and so is a real column.

## Reference data: tiered tables, not one generic `lookups` table

Every dropdown/reference list in the app is backed by one of 13 physical reference tables, split into two tiers rather than one generic melting-pot table:

- **Tier 1 — 9 dedicated entity tables** (`item_types`, `brands`, `equipment_categories`, `equipment_classifications`, `equipment_conditions`, `positions`, `ro_offices`, `sdo_offices`, `isp_providers`) for reference concepts that are large, reused across resources, or actively filtered/reported on. Consuming columns are real `_id` foreign keys with `belongsTo` relationships (e.g. `equipment.item_type_id → item_types.id`).
- **Tier 2 — 4 domain-grouped "library" tables** (`equipment_libraries`, `personnel_libraries`, `stakeholder_libraries`, `connectivity_libraries`), each discriminated by a `type` column but scoped to one bounded context. Consuming columns stay validated strings (`Rule::in()`), for small closed vocabularies with no independent identity worth an FK.

This superseded an earlier single generic `lookups` table (one table, 35 types, discriminated by a since-deleted `App\Enums\LookupType`) — that design, and every file backing it, has been fully removed from the codebase. See [`docs/architecture-decisions/lookup-normalization.md`](architecture-decisions/lookup-normalization.md) for the full ADR (why a uniform 35-tables-or-1-table design was rejected in favor of the tiered split) and [`docs/features/reference-data.md`](features/reference-data.md) for the resulting admin screen (`/reference-data`, gated on `reference-data.manage`), registry (`config/reference-data.php`), and per-tier editability rules.

**PSGC location data (2026-07-14) is a third, deliberately separate shape** — `psgc_provinces` → `psgc_municipalities` → `psgc_barangays`, each with a real FK to its parent (Stakeholder Profile's `province_id`/`municipality_id`/`barangay_id`), scoped to Region III only (this division's actual coverage area; no `psgc_regions` table — region is a constant here, not a dimension). It departs from the Tier 1 shape in two ways: rows carry a real government `code` alongside `name` (Tier 1 tables use `name` alone), and there's no `is_active`/`sort_order` (this is fixed reference data, not admin-editable). The frontend ships the full hierarchy (~3,100 barangays) once per edit-page load and filters it client-side into cascading province → municipality → barangay `<Select>`s — see [`docs/features/stakeholder-profile.md`](features/stakeholder-profile.md).

## Frontend permission gating

`resources/js/composables/usePermissions.ts` exposes `can()`/`canAny()`/`canAll()` against the `auth.permissions` prop shared by `HandleInertiaRequests`. Pages/components use this to hide Add/Edit/Delete affordances a user's role doesn't grant — this is a UX convenience, not the authorization boundary; every mutating route re-checks the same Policy server-side regardless of what the client rendered.
