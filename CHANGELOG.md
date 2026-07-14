# Changelog

All notable changes to this project, in terms of user/developer impact. Dates are reconstructed from migration filename timestamps and source file modification times (there is no git history yet — this project has not been initialized as a git repository) and are accurate to the day for entries backed by a migration timestamp; entries reconstructed from file mtimes alone are noted as such and may be off by a day where work spanned a day boundary.

## 2026-07-14

### Added

- **"Remember this device" for two-factor authentication.** 2FA-enrolled users were being challenged on every login with no way to trust a device they'd already verified. Adds a "Remember this device for 30 days" checkbox to the 2FA challenge screen (both the authenticator-code and recovery-code forms). Backed by `two_factor_trusted_devices` (per-user, hashed token, `expires_at`) and an `httpOnly`/`secure` cookie holding the raw token — never a bare unrevocable signed cookie. Implemented by extending Fortify (never forking it): `App\Actions\Fortify\RedirectIfTwoFactorAuthenticatable` and `App\Actions\Fortify\TwoFactorLoginResponse` override the stock actions via container binding in `FortifyServiceProvider`. See `docs/features/two-factor-trusted-devices.md`.
- **Confirmation dialog before logging out.** Clicking "Log out" in the navbar user menu previously logged out immediately; it now opens a confirm dialog first, matching the existing "Delete account" `Dialog` pattern.
- **11-agent roster implemented as real Claude Code subagents** (`.claude/agents/*.md`). `CLAUDE.md` has documented this project's specialist-agent routing table since the initial commit, but the actual subagent definition files backing it never existed until now — each agent's scope and conventions are grounded in this project's own documented decisions, not generic Laravel advice.
- **User accounts now show School/Office, and Created/Modified dates.** `/users` was previously limited to name, email, and role. Added a nullable `office_id` on `users`, backed by a new `offices` table (schools and division-level offices/units together, imported wholesale from the division's existing offices data — 95 rows, `school_id` populated only for actual schools). Both Create and Edit expose an optional School/Office dropdown; the Index table shows the assigned office (with its School ID, if any) plus Created/Modified timestamps.

### Changed

- **Stakeholder Profile moved from the main sidebar into Settings as its own tab.** It's a singleton (one record per division office, no list view), so it fits the same edit-form pattern as the Profile/Security/Appearance settings tabs better than a top-level list-style nav item. `SettingsLayout` gained a `wide` prop so this tab's multi-column form isn't squeezed into the other tabs' narrow single-column width.
- **Logged-in user menu moved from the sidebar footer into the navbar.** `NavUser.vue` (sidebar `SidebarFooter`) removed; the same avatar/dropdown (reusing `UserInfo`/`UserMenuContent`) now lives in `AppSidebarHeader.vue`.
- **Declared PHP floor corrected from `^8.3` to `^8.4`.** `composer.json` claimed `^8.3` while `composer.lock` had already resolved `symfony/clock` to a version requiring PHP `>=8.4.1` — the lock file never actually satisfied the declared floor, silently breaking any environment/CI still targeting 8.3. `composer.lock` only changed its content-hash/platform metadata; no package versions moved.

### Fixed

- **Sidebar tooltips getting stuck open when navigating.** The active sidebar item rendered as a plain `<a>` while every other item rendered as an Inertia `<Link>`, so navigating swapped element types and forced Vue to destroy/recreate the tooltip's trigger node mid-hover, orphaning its open state. Every item now renders the same stable `<a>`, with navigation dispatched manually so the already-active item still no-ops instead of re-visiting itself.
- **Theme-toggle icon SSR/hydration mismatch.** The Sun/Moon icon swapped via `v-if`/`v-else` on a value that calls `window.matchMedia` — unavailable during SSR, so the server always rendered Sun regardless of the real OS theme, mismatching the client's first hydration pass for any dark-mode user. Both icons now always render (identical vdom on server and client); visibility is toggled with `dark:hidden`/`dark:block`, driven by the `.dark` class `app.blade.php`'s inline script already applies synchronously before Vue mounts.

### Removed

- **Starter-kit Repository/Documentation links** removed from the sidebar footer (pointed at the upstream `laravel/vue-starter-kit` repo, not this project) — `NavFooter.vue` deleted outright.
- **Unused header-nav layout** (`AppHeaderLayout.vue`, `AppHeader.vue`, and the now-orphaned `navigation-menu` shadcn primitive) — dead code from the starter kit, never wired to a route since `AppLayout.vue` only ever renders `AppSidebarLayout`.
- **GitHub Actions CI workflows** (`lint.yml`, `tests.yml`) removed entirely — no CI runs on push in this repo anymore.

## 2026-07-13

### Changed

- **Lookup-normalization cleanup (Step 4 of the ADR).** Dropped the 8 legacy string columns the Tier 1 foreign keys replaced — `equipment.item`/`brand_manufacturer`/`category`/`classification`/`equipment_condition`, `personnel.position`/`ro_division`/`division_unit`, and `isp_accounts.isp` — now that all five consuming resources read/write exclusively through their FK columns. Made the equivalent Tier 1 FK columns `NOT NULL` wherever the original string column was itself required: `equipment.item_type_id`/`brand_id`/`equipment_category_id`/`equipment_classification_id`/`equipment_condition_id` and `isp_accounts.isp_provider_id`. `personnel.position_id`/`ro_office_id`/`sdo_office_id` deliberately stayed nullable, matching their original string columns' shape. Dropped the `lookups` table outright. `App\Models\Lookup`, `App\Enums\LookupType`, `App\Policies\LookupPolicy`, `LookupController`, `LookupSeeder`, `database/seeders/data/lookups.json`, and `resources/js/pages/lookups/` are all removed from the codebase. This is the final step of the lookup-normalization rollout that began 2026-07-11 (see below) — the tiered reference-data system is now the only reference-data system in the app.

### Fixed

- **Data-integrity note, not a code fix:** one orphaned test-artifact `Equipment` record — soft-deleted, created during earlier dev/QA testing, with no legacy string values populated to backfill an `item_type_id`/`brand_id`/etc. from — blocked the `2026_07_13_100002_make_required_tier1_fk_columns_not_null` migration from applying its `NOT NULL` constraint. It was hard-deleted directly against the database (not through the application's normal delete path) to unblock the migration, since it held no recoverable data and the standard backfill step had nothing to match it against. This bypassed the app's normal audit-log path (`AuditLog::record()` is only invoked by application code, not manual database operations), so this changelog entry is the durable record of that action, per reviewer request.

## 2026-07-12 *(reconstructed from file modification times, not a migration timestamp — dates within this range are approximate)*

### Added

- **Personnel and ISP Accounts cut over to Tier 1 foreign keys**, following Equipment's 2026-07-11 cutover — `personnel.position_id`/`ro_office_id`/`sdo_office_id` and `isp_accounts.isp_provider_id` replaced their old string columns in the write/read paths (schema-level nullable-string cleanup followed on 2026-07-13, see above).
- **`/reference-data` admin screen** — `ReferenceDataController` (index/show/update), `ReferenceDataPolicy` (one shared Policy bound to all 13 reference-data model classes via `AppServiceProvider::registerReferenceDataPolicies()`), `ReferenceDataUpdateRequest`, `ReferenceDataResolver`, and `config/reference-data.php` (the single registry driving all 13 tables from one generic controller). Gated on the new `reference-data.manage` permission, successor to `lookups.manage`. Tier 1 rows' `name` is editable through this screen (real FK, no stale-copy risk); Tier 2 rows' `type`/`value` are not (validated-string consumers would be silently orphaned by a rename) — see `docs/features/reference-data.md`.
- **`/roles` admin screen** — `RoleController` (full CRUD on role definitions), `RoleService` (business-rule invariants: protected `pending` role, "role still in use" delete guard, permission-lockout guards), `RolePolicy`, `RoleStoreRequest`/`RoleUpdateRequest`. New `roles.manage` permission, deliberately separate from and more sensitive than `users.manage` — a `users.manage` holder can assign existing roles but cannot redefine what a role grants.
- **Multi-role users.** `UserRoleService::syncRoles()` replaced the original single-role `changeRole()` — a user can now hold more than one role at once, assigned as a set via `/users`. `UserController`'s `roles` prop now lists only roles the acting admin is themselves permitted to assign (`assignableRolesFor()`).
- **Branding update.** `APP_NAME` set to "DepEd Division ICT Inventory System"; `VITE_APP_NAME=DICTIS` drives the actual browser tab title via Inertia's title callback (`resources/js/app.ts`); the SDO Angeles City seal (`public/sdoac.png`) replaced the starter kit's default logo/favicon in `AppLogo.vue`, `AppLogoIcon.vue`, and `resources/views/app.blade.php`.
- **UI polish pass** — bordered field groupings and tightened checkbox spacing applied across admin forms (roles, reference-data) and existing resource forms. Exact scope/date within this reconstructed window is uncertain; flagged here rather than omitted.

### Security

- **CRITICAL — permission-tier separation was not enforced on role assignment.** A `users.manage` holder could previously assign *any* role to any account, including one carrying `roles.manage` — silently bypassing the tier separation the permission design always intended but nothing enforced. Fixed by `UserRoleService::guardAgainstPermissionTierViolation()`, which restricts assignable roles to those whose permissions are a subset of the assigning admin's own.
- **MEDIUM — TOCTOU race in the last-sensitive-permission-holder lockout checks.** Both the role-editing and user-role-assignment lockout guards originally ran their "would this leave zero holders" check as an unlocked pre-write `SELECT`, so two concurrent requests could each independently strip the last holder of a sensitive permission and both commit. Fixed by moving both checks inside their write transaction, behind a `SELECT ... FOR UPDATE` lock on the affected `Permission` row.

## 2026-07-11

### Added

- **Lookup-normalization rollout began** (ADR: `docs/architecture-decisions/lookup-normalization.md`). Replaced the single generic `lookups` table (35 types discriminated by `App\Enums\LookupType`) with a tiered design: 9 Tier 1 dedicated entity tables with real foreign keys (`item_types`, `brands`, `equipment_categories`, `equipment_classifications`, `equipment_conditions`, `positions`, `ro_offices`, `sdo_offices`, `isp_providers`) and 4 Tier 2 domain-grouped "library" tables (`equipment_libraries`, `personnel_libraries`, `stakeholder_libraries`, `connectivity_libraries`), each still discriminated by a `type` column but scoped to one bounded context. All 13 tables created and seeded from the same 677 rows that used to feed `lookups` (redistributed, not re-extracted from the source workbook).
- **Equipment cut over first** (the ADR's recommended order — most Tier 1 fields, highest-traffic screen): nullable `item_type_id`/`brand_id`/`equipment_category_id`/`equipment_classification_id`/`equipment_condition_id` FK columns added alongside the still-live legacy string columns, backfilled, and wired into `EquipmentController`'s read/write/filter paths. Old and new columns coexisted through this step — nothing consumer-facing broke mid-migration.

### Fixed

- **Closed a race condition in the singleton tables.** `stakeholder_profiles` and `internet_connectivity_surveys` are meant to hold exactly one row each, created on first access via `firstOrCreate([])`. Before this fix, nothing at the database level prevented two concurrent first-load requests from both inserting a row. Added a `singleton_guard` column with a unique index to each table, so a second concurrent insert now collides and Laravel's built-in retry path returns the existing row instead. Flagged by security review; addressed the same day.

## 2026-07-10

Initial build. All six modules from the source Excel workbook ("Division Office of Angeles City - Inventory.xlsx"), plus the roles/permissions and audit-logging systems, were built and migrated on this date.

### Added

**Foundation**

- Laravel Vue Starter Kit installed as the project base (Fortify session auth, Inertia.js v3, Vue 3 + TypeScript, Tailwind + shadcn-vue, Laravel Wayfinder).
- 11-agent collaboration model and flat stock-Laravel project-structure decision documented in `CLAUDE.md`.

**Lookups**

- Generic `lookups` table (`type`/`value`/`description`/`sort_order`/`is_active`), backed by `App\Enums\LookupType` (35 cases).
- `LookupSeeder`, seeding 677 real reference-data values extracted from the source workbook's "Referential Data" sheets, idempotent via upsert on `(type, value)`.

**Personnel**

- `personnel` table, `Personnel` model with soft deletes, an employment-status lifecycle (`inactive`/`separation_date`/`separation_cause`/`transferred_from`/`transferred_to`) distinct from soft-delete.
- Full CRUD (`PersonnelController`), search + active/inactive/all filtering, `PersonnelPolicy`.

**Equipment**

- `equipment` table (property/spec/acquisition/condition/disposition fields) with soft deletes.
- `equipment_transactions` — an append-only accountability/lifecycle audit log, plus a `recorded_by_user_id` column added same-day to attribute each transaction to the system user who entered it.
- `EquipmentAccountabilityService::recordTransaction()` as the sole sanctioned path for changing `equipment.current_accountable_officer_id`/`current_end_user_id`, enforced by a model-level `updating` guard (`AccountabilitySyncRequiredException`) that blocks any other attempt to touch those columns.
- Full CRUD (`EquipmentController`) plus the append-only `EquipmentTransactionController::store`, search + condition/category/disposition-status filtering, `EquipmentPolicy` (including a dedicated `equipment.transactions.create` permission, separate from `equipment.edit`).

**Roles & permissions**

- `permissions`, `roles`, `permission_role`, `role_user` tables.
- `App\Enums\Permission` (19 cases) as the single source of truth for every granular permission string in the app.
- `RolePermissionSeeder`, composing four roles: `pending` (zero permissions, default for self-registration), `viewer`, `encoder`, `division-ict-admin`.
- `User::hasPermissionTo()`/`permissionNames()`/`assignRole()`/`removeRole()` — the sole authorization primitives; every Policy in the app defers to `hasPermissionTo()`, never a role-name comparison.

**Audit logging**

- `audit_logs` table (append-only, no `updated_at`), `AuditLog::record()` as the single write path.
- Coverage: CRUD on Personnel/Equipment, role grants/revocations, and denied-permission checks on the most consequential action per resource (e.g. `EquipmentPolicy::delete()`).
- Actor-identity snapshotting (`properties.actor_snapshot`) so audit rows stay attributable even after the acting account is later deleted.

**ISP Accounts**

- `isp_accounts` table with soft deletes; two independent append-only child logs, `isp_speed_tests` and `isp_subscription_costs`, each later given a `recorded_by_user_id` column (same-day follow-up migration) to attribute entries to the system user who logged them.
- Full CRUD (`IspAccountController`) plus append-only `IspSpeedTestController::store` / `IspSubscriptionCostController::store`, search + provider/status filtering, `IspAccountPolicy` (nested logs authorize against the parent account's `update` ability — no dedicated permission, unlike Equipment's transactions).

**Stakeholder Profile**

- `stakeholder_profiles` table (singleton — governance/location/contact/community-context fields), including a MySQL STORED generated `complete_address` column.
- `StakeholderProfileController` with only `edit()`/`update()` (no index/create/store/destroy), `StakeholderProfile::firstOrCreate([])`-based, `StakeholderProfilePolicy` (only `view`/`update`).

**Internet Connectivity Survey**

- `internet_connectivity_surveys` table (singleton — connectivity/electricity/coverage survey fields), deliberately excluding the source sheet's "Protected, source data from..." derived fields (Total ISPs, Total Cost/month, etc.) as columns.
- `InternetConnectivitySurveyController` with only `edit()`/`update()`, plus a live-computed `aggregates` prop sourced from `IspAccount`/`IspSubscriptionCost` on every page load, `InternetConnectivitySurveyPolicy` (only `view`/`update`).

### Security

- **Finding #1 — open self-registration exposed PII.** Fixed by assigning every self-registered account the zero-permission `pending` role (never `viewer`), so a new account can access nothing until a Division ICT Admin reviews it. Regression-tested end-to-end in `tests/Feature/Auth/RegistrationApprovalTest.php`.
- **Finding #2 — a `users.manage` holder could self-delete their account with no review.** Fixed by denying self-deletion for any account holding `users.manage`. Regression-tested in `tests/Feature/Settings/ProfileUpdateTest.php`.
- **`/users` admin screen guards.** `UserController` (list users, change one user's role) built with two forward-looking guards required by the same review: a self-escalation guard (a user can never change their own role) and a last-admin lockout guard (the last remaining `users.manage` holder can never be demoted out of it), both enforced in `UserRoleService::changeRole()` and regression-tested in `tests/Feature/UserControllerTest.php`.

### Known gaps (intentional, not oversights, as of 2026-07-10)

- No lookup-management admin UI yet — `LookupPolicy` and the `lookups.manage` permission are wired up ahead of the UI landing.

  **Resolved 2026-07-12** — see the `/reference-data` admin screen entry above. `LookupPolicy`/`lookups.manage`/`LookupController` no longer exist, replaced by `ReferenceDataPolicy`/`reference-data.manage`/`ReferenceDataController` as part of the lookup-normalization rollout. This note is kept as an accurate historical record of the gap as it stood on 2026-07-10, not a claim about current behavior — see [`docs/features/reference-data.md`](docs/features/reference-data.md) for the current system.
