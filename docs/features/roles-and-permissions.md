# Roles, Permissions & Users

## Purpose

Access control for the whole application, on spatie/laravel-permission, around one hard rule: application code checks a granular permission, never a role name. Roles are admin-editable data, each user holds one, and accounts come from PRIME (created/synced on first sign-in; there is no self-registration and no local create or delete). This doc covers the model, the seeded roles, the `/roles` and `/users` screens and their API, and the guards that protect them. The cross-cutting summary (route fast-fail vs Policy, frontend gating) is in [`docs/architecture.md`](../architecture.md#authorization-model-granular-permissions-never-role-names).

## Architecture

- `App\Enums\Permission` — the single source of truth, one case per permission (`personnel.*`, `equipment.*`, `equipment.transactions.create`, `isp_accounts.*`, `office.*`, `stakeholder_profile.*`, `internet_connectivity.*`, `reference-data.manage`, `users.view`/`users.edit`/`users.reset_two_factor`, `roles.view`/`roles.create`/`roles.edit`/`roles.delete`, `audit_log.view`, `security_settings.manage`, `email_log.manage`). Each case has `label()` and `group()` for the role editor. Only features that exist get permissions.
- Spatie's `permissions` table is materialized from the enum by `RolePermissionSeeder` (`Permission::findOrCreate($case->value, 'web')`). The enum value **is** the Spatie name; keep `permission.enable_wildcard_permission` false or the dots become wildcard segments.
- `App\Models\Role extends Spatie\Permission\Models\Role`, adding `label`/`description`/`data_scope`, `Role::SUPER_ADMINISTRATOR`/`isProtected()`, and assigned-user counts. Registered with `RolePolicy` explicitly in `AppServiceProvider`. There is no custom Permission model; Spatie's is used directly.
- `User` uses Spatie's `HasRoles`; `getAllPermissions()` is the flat union across roles.
- `App\Services\RoleService` — the only entry point for creating, editing, deleting role definitions.
- `App\Services\UserRoleService` — the only entry point for editing user accounts and changing a user's role set (`syncRoles()` replaces the whole set). Its `createUser()`/`deleteUser()` are not reachable over HTTP: accounts are synced from PRIME, so `POST`/`DELETE /api/users` answer 403.
- Policies: `RolePolicy` — `viewAny`/`view` need `roles.view` or `users.edit` (the user editor lists roles to assign), `create`/`update`/`delete` need `roles.create`/`roles.edit`/`roles.delete`; every denial is audited. `UserPolicy` — `viewAny` is `users.view`; `update` is `users.edit` and includes self; `resetTwoFactor` is `users.reset_two_factor` and refuses self; `create`/`delete` always return false. `update` and `resetTwoFactor` also require the target to be within the actor's permission tier (`UserRoleService::canManage()`).

The ids of roles and permissions from before the conversion were kept: migration `2026_10_19_000002` altered the old tables in place (added `guard_name`, unique `(name, guard_name)`) and copied `role_user`/`permission_role` into Spatie's pivots rather than recreating tables.

## Two sensitive permissions, deliberately separate

- **`users.edit`** — edit/deactivate accounts and assign *existing* roles.
- **`roles.edit`** — change what a role grants (`roles.create`/`roles.delete` cover defining and removing roles).

These two are `RoleService::SENSITIVE_PERMISSIONS`, the ones the lockout guards below protect, because they are what lets anyone change access, including their own. Holding `users.edit` alone does not let anyone redefine a role or hand out permissions they lack themselves. As seeded, only `super_administrator` holds `roles.create`/`roles.edit`/`roles.delete` (`system_administrator` gets every other permission). Holders of the role- and user-changing permissions always need their authenticator code: the "trust this device" skip is disabled for anyone holding a permission in `TrustedDeviceService::ALWAYS_REQUIRE_CODE` (see [two-factor-trusted-devices.md](two-factor-trusted-devices.md#design-decisions)).

## Seeded starting roles

| Role (label) | Scope | Permissions |
|---|---|---|
| `super_administrator` (Super Administrator) | `all` | every enum case. Protected: cannot be renamed or deleted, its permission set must always be the full enum, and its `data_scope` is forced to `all`. |
| `system_administrator` (System Administrator) | `all` | every enum case except `roles.create`, `roles.edit`, `roles.delete` |
| `encoder` (Division Encoder) | `all` | view/create/edit on Personnel, Equipment, ISP Accounts; `equipment.transactions.create`; view/edit on Stakeholder Profile and Internet Connectivity, which at scope `all` means every office's record and both lists (see [Defaults](#defaults-and-validation)). No deletes, no admin. |
| `office_encoder` (Office Encoder) | `school` | same permissions as `encoder`, limited to the user's own office |
| `viewer` (Viewer) | `own` | `personnel.view`, `equipment.view`, `stakeholder_profile.view`, `internet_connectivity.view` |

Apart from `super_administrator` these are ordinary data once seeded. `RolePermissionSeeder` is idempotent and resets Spatie's permission cache. It always resyncs `super_administrator` (label, every permission, scope `all`), but only **creates** the other roles when they don't exist yet; it never overwrites a role an admin has edited. (Before 2026-10-26 it reset every seeded role on each run, silently undoing UI edits.)

**Role and permission changes on existing databases ship as migrations, not seeder edits**, because the deploy script runs migrations only. `2026_10_20_000002` and `2026_10_26_000005` are the examples. A new enum permission therefore needs a migration that creates it and grants it to each role that should have it, `system_administrator` included; the seeder only does this for `super_administrator`, and only when someone runs it.

## Data scope

Each role has a `data_scope` (`App\Enums\DataScope`, column `roles.data_scope`). Permissions decide what a user may do; scope decides which **records** those permissions reach, across Personnel, Equipment, ISP Accounts, Stakeholder Profile and the Internet Connectivity Survey. Scope never grants an action: a Viewer with `all` can read everything but still edit nothing. Decision and rejected alternatives: [`architecture-decisions/unified-data-scope.md`](../architecture-decisions/unified-data-scope.md). Until 2026-10-26 scope covered Equipment only and found "own" by matching `personnel.deped_email`; Stakeholder Profile and the survey had a separate `.view_all` rule, now removed.

| Value | Editor label | Personnel | Equipment | ISP Accounts | Stakeholder Profile, Connectivity Survey |
|---|---|---|---|---|---|
| `own` | Own records only | the user's linked record (`personnel.employee_id` = `users.employee_no`) | issued (accountable officer or end user) to reachable personnel, or unissued and created by the user | `office_id` = user's office | the user's own office's row |
| `school` | **Own office** | personnel with `office_id` = user's office, plus the linked record | same as `own`, plus unissued items created by any user at the same office | same as `own` | same as `own` |
| `all` | All records | everything | everything | everything | every office, plus the cross-office list |

The stored value stays `school`, but `offices` holds division units (OSDS-*, CID-*, SGOD-*) as well as schools, so the UI says "Own office".

**How it's resolved.** `User::dataScope()` takes the widest scope across the user's roles (`all` > `school` > `own`), and `own` when the user has no role. `User::scopedPersonnelIds()` returns `null` for `all`, otherwise the reachable Personnel ids, computed once per request. `User::reachesOffice($officeId)` answers the office-based resources. Each scoped model has a `visibleTo($user)` query scope for lists and an `isVisibleTo($user)` check that its Policy uses for single records. Scope is never cached between requests, so a change takes effect on the user's next request.

**Where the links come from:**

- `users.employee_no` (nullable, unique) links an account to its Personnel record: `User::personnel()` is the Personnel row whose `employee_id` equals it. It comes from PRIME (`PrimeDirectory`) and isn't editable in IRIS. It replaced `personnel.user_id` in `2026_10_27_000001`, which copied each unambiguous existing link across. `deped_email` doesn't affect access, because anyone who can edit Personnel could change it.
- `users.office_id`, also from PRIME: the account's PRIME department, mapped to the IRIS office with that `offices.prime_department_id` (`2026_10_27_000000`). It isn't editable in `/users`. A department with no linked office leaves the account with no office. (Before 2026-10-27 an admin set it in the `/users` editor and PRIME sync never touched it.)
- Both are refreshed on `GET /api/user` (`PrimeUserSync::syncUser()`, every SPA load) and by `php artisan users:sync-external`, not by the Users list (see [Users](#users)). `php artisan prime:sync-preview` shows what a sync would change without writing anything.
- `personnel.office_id` and `isp_accounts.office_id`, both nullable FKs to `offices` (the table Stakeholder Profile and the survey use, not `ro_offices`/`sdo_offices`).
- `equipment.created_by_user_id`, set from the session on `POST /api/equipment` and backfilled from `activity_log` by `2026_10_26_000003`. The creator's office is read from `users.office_id` at request time, not stored on the item.

### What it filters

| Area | How |
|---|---|
| Lists: `GET /api/personnel`, `/equipment`, `/isp-accounts` | `visibleTo($user)` |
| Single records: show/update/delete on Personnel, Equipment (plus QR and transactions), ISP Accounts (plus speed tests and subscription costs), Stakeholder Profile and survey `{office}` | Policy: missing permission is **403**, permission held but record out of reach is **404** (`Response::denyAsNotFound()`). Outside `local`/`testing`, every 404 body is the same generic `{"message": "Not Found."}` (`bootstrap/app.php`), so a scoped user can't tell whether an id exists. Form Requests use `Gate::inspect()` to keep the 404. |
| Cross-office lists `GET /api/stakeholder-profiles`, `/internet-connectivity-surveys` | `all` only (403 otherwise) |
| `GET /api/dashboard/summary` | equipment figures, `recent_transactions` (items visible now), personnel counts and ISP figures |
| `GET /api/equipment/options` `personnel`, transaction targets | limited to reachable personnel. An out-of-reach or non-existent `accountable_officer_id`/`end_user_id`/`received_by_id` gets the same 422 ("Choose someone within your access."), so the error doesn't reveal which ids exist |

**Users list:** a scoped actor sees only users at their own office (only themself if they have no office). A scoped actor can manage (edit, change roles, reset 2FA, link personnel) only accounts at their own office, on top of the permission and scope tiers; a scoped actor with no office manages nobody.

**Audit Log:** a scoped actor sees the rows they caused, plus rows about subjects in reach: Personnel, Equipment and its transactions, ISP accounts with their speed tests and subscription costs, their office's Stakeholder Profile and Connectivity Survey, and users in reach (including their own account). Other people's `authorization.denied` and `auth.*` rows, and rows about roles, permissions, offices, reference data, settings and system jobs, are hidden. The `/audit-log/options` lists are built from the same visible rows.

**Not filtered:** the full transaction history on a visible item (it can name people outside the scope), Offices, Reference Data, Roles and the other settings screens.

**Grant those admin permissions only to all-scope roles.** `reference-data.manage`, `office.*`, `roles.view` and the settings permissions see division-wide data whatever the role's scope. (`users.edit`, `roles.*` and the personnel link are additionally capped by the scope-tier guard; see [Guards](#guards).)

### Writes stay inside reach

Enforced in the Form Requests (`Concerns\RestrictsScopedAssignments`); a rejected value is a 422 ("Only users with access to all records can change this."):

- Only `all` users may set or change `office_id` on Personnel or ISP Accounts.
- A scoped user creating Personnel or an ISP account gets `office_id` forced to their own office, and a 422 on `office_id` if their account has none.
- `own` users can't create Personnel at all (`PersonnelPolicy::create`, 403): the new record would be out of their reach as soon as it was saved.
- Every `office_id` field (Personnel, ISP Accounts) must reference an **active** office; on update the record's current office is also accepted, so an unchanged value still saves after the office is deactivated.

### Defaults and validation

- Seeded: see [Seeded starting roles](#seeded-starting-roles). Migration `2026_10_20_170000` set the roles existing at the time to `all`, except `viewer` (`own`). `2026_10_26_000005` relabelled `encoder` to "Division Encoder" and added `office_encoder` (`school`) with the same permissions. It also replaced `stakeholder_profile.view_all`/`internet_connectivity.view_all` with `.view` + `.edit` for every role or user that held them, leaving their scope unchanged. Each change is recorded in a `system.data_scope_migration` activity row, which lists the roles and direct holders affected.
- **Division Encoder widened.** It stays at `all`, so its Personnel, Equipment and ISP reach is unchanged, but its existing `.view`/`.edit` on Stakeholder Profile and the Connectivity Survey used to reach only the user's own office and now reach every office, plus both cross-office lists. The owner accepted this because no production users held roles yet. Before assigning it, check that the role should reach every office; otherwise use Office Encoder.
- **`000005`'s `down()` is not an exact reverse.** It restores the two `.view_all` permissions to `super_administrator` and `system_administrator` only (not to whoever actually held them), keeps the `.view`/`.edit` it added, and removes `office_encoder` only if nobody holds it. The `system.data_scope_migration` row has the real former holders if they need restoring by hand.
- The column default is `own` (`2026_10_26_000001`, previously `all`), so a role written outside the API fails closed.
- `StoreRoleRequest`/`UpdateRoleRequest`: `data_scope` is `sometimes` and must be a `DataScope` value. Omitted, `POST /api/roles` uses `own` (also the editor default) and `PUT` keeps the current value.
- On the protected `super_administrator` role, `RoleService::update()` silently forces the scope to `all`. The editor disables the select and shows "This role always reaches all records."
- Scope changes are part of the `role.created`/`role.updated` audit rows. The lockout guards ignore scope; the scope-tier guard (see [Guards](#guards)) doesn't.

### Edge cases

- **Missing links fail closed.** A `school` user with no office reaches only their linked record, equipment issued to it and unissued items they created; no ISP accounts and no office rows. An `own` user with no linked record reaches no personnel and only the unissued equipment they created. Records with a null office are visible only to `all`. The dashboard shows a notice in both situations ("Your account isn't linked to a personnel record yet" / "isn't assigned to an office yet").
- **Production needs the office backfill.** Scoped users see little until `personnel.office_id` and `isp_accounts.office_id` are filled in and accounts are linked to their Personnel records. All-scope admins are unaffected. `php artisan personnel:link-preview` is read-only and reports three things: the user/personnel pairs the email backfill links (or linked), ambiguous emails it skips, and all-scope roles whose Stakeholder Profile/Connectivity access widens to every office. The last section only means something before `000005` has run.
- Separated (`inactive`) personnel still count. Soft-deleted personnel don't, and deleting a Personnel record clears its `user_id`.
- **Scope follows the current `current_accountable_officer_id`/`current_end_user_id`, not history.** A transfer to someone else in reach keeps the item visible; a transfer that names nobody returns it to the creator's reach, so an office encoder who didn't create it stops seeing it (intended: returned items go back to division custody).
- **New resources aren't scoped automatically.** A new record type that should follow data scope needs its own `visibleTo`/`isVisibleTo` and the Policy check.

## API endpoints

### Roles and the permission catalog

| Method + path | Route permission | Policy | Request → response |
|---|---|---|---|
| `GET /api/roles` | `roles.view` or `users.edit` | `viewAny` | `RoleResource[]` (not paginated) with `user_count`, `permission_count`, ordered by label |
| `GET /api/roles/{id}` | `roles.view` or `users.edit` | `view` | `RoleResource` with `permissions` |
| `GET /api/permissions` | `roles.view` or `users.edit` | `RolePolicy::viewAny` | `{id, name, label, group}[]` in enum order — feeds `components/management/PermissionPicker.tsx`, so groups come from the server, not a duplicated frontend list |
| `POST /api/roles` | `roles.create` | `create` | `StoreRoleRequest`: `name` (lowercase `[a-z0-9_-]`, unique), `label`, `description`, optional `data_scope` (`own`/`school`/`all`, default `own`), `permissions[]` (enum values) → `RoleResource`, 201 |
| `PUT /api/roles/{id}` | `roles.edit` | `update` | `UpdateRoleRequest`, same fields (omitted `data_scope` keeps the current value) → `RoleResource` |
| `DELETE /api/roles/{id}` | `roles.delete` | `delete` | 204, or **409** with the reason (protected `super_administrator`, or still assigned to users) |

**`RoleResource`:** `{id, name, label, description, data_scope, is_protected, user_count?, permission_count?, permissions?: string[], created_at, updated_at}`.

### Users

The group needs any of `users.view`, `users.edit`, `users.reset_two_factor`; each route then narrows it as listed, and `UserPolicy` and `UserRoleService` re-check. Accounts come from PRIME, so there is no create or delete.

| Method + path | Route permission | Request → response |
|---|---|---|
| `GET /api/users?search&role&is_active&office_id&sort&direction&per_page` | `users.view` | paginated `UserResource`. `search` matches name/email; `role` is a role **name**. Sort keys: `name` (default), `email`, `is_active`, `created_at`. |
| `GET /api/users/options` | `users.view` or `users.edit` | `{data: {roles: {id, name, label}[], offices: {value, label}[]}}` — `roles` lists only roles the acting user may assign (`assignableRolesFor()`), so the UI never offers one the write side would reject. `offices` (active offices) feeds the list's office filter. (The `personnel` list for the old "Linked personnel" picker went away with `personnel.user_id` on 2026-10-27.) |
| `POST /api/users` | group only | always 403 (`User creation is disabled. Users are managed through PRIME.`) |
| `PUT /api/users/{id}` | `users.edit` | `UpdateUserRequest`: `is_active`, `role_id` (required, replaces the user's role) → `UserResource`. Name, email, office and employee number come from PRIME and aren't editable here; there is no password field. Editing your own row is allowed, but `is_active: false` or dropping your own `users.edit`/`roles.edit` is a 422. |
| `DELETE /api/users/{id}` | group only | always 403 (deletion is disabled; deactivate instead) |
| `POST /api/users/{id}/2fa/reset` | `users.reset_two_factor` (throttle `password-confirm`) | `current_password` (the admin's PRIME password, checked by `App\Rules\CurrentPassword`) → `{message}`. Clears the user's 2FA so they enroll again at next login; also revokes their trusted devices. Logged to activity (`user.two_factor_reset`) and the `security` channel. Refuses self. |

**The list is read live from PRIME, cheaply.** `UserController::index` calls `PrimeUserSync::importNew()` before the query and `syncVisible()` on the page it returns:

- **`syncVisible()`** refreshes the name and deactivation of exactly the rows on the page, in one PRIME query by email. It never reactivates: an account that is active in PRIME but inactive in IRIS stays inactive. Changes are audited as `Synced from PRIME`, the same as the full sync. Office and employee number are not refreshed here; that happens on `GET /api/user` and `users:sync-external` (see [Data scope](#data-scope)), because computing them needs PRIME's departments and employee directory, too heavy for every list load.
- **`importNew()`** creates local accounts for new **active** PRIME accounts, so they appear without waiting for a login. It reads PRIME `users.user_id` above a high-water mark kept in the cache under `prime_users.max_user_id`, at most 100 per load (the rest follow on later loads), behind a cache lock so concurrent loads can't create the same account twice. The first run only sets the mark to PRIME's current highest id and imports nothing (the full directory import is `users:sync-external`'s job). If PRIME's highest id drops below the mark (PRIME restored or re-seeded), the mark resets to 0 and the import rescans. `users:sync-external` sets the mark to the highest id it read. PRIME rows without an email are skipped, and a bad row is logged and skipped without blocking the rest.
- **The import skips inactive accounts but still moves the mark past them.** An account created inactive in PRIME and activated later is therefore never picked up by the list; it arrives on the person's first sign-in or the next `users:sync-external`.
- **Duplicate emails** (two PRIME rows sharing an email, case-insensitive, checked across all of PRIME, not just the batch) are skipped by both steps and logged as `Ambiguous PRIME account` on the `security` channel, matching login's refusal.
- **PRIME down:** both steps log a warning and the list shows the local copy. While the `prime.unreachable` breaker is open (30 s after a failure, see [Password authority](../architecture.md#password-authority-prime)) they don't contact PRIME at all.
- **`php artisan cache:clear` resets the mark.** The next list load re-initialises it at PRIME's current top, so accounts created between the last full sync and the clear aren't imported by the list; they arrive when the person signs in or on the next `users:sync-external`.
- These queries filter PRIME `users` by `email_address` and `user_id`. Those columns should be indexed on PRIME's side; that's for PRIME's owners to add.

This replaced a full PRIME sync on list load (throttled to once per 30 seconds), which re-read every PRIME account on a page view.

**`users:sync-external` runs every 15 minutes** (`routes/console.php`, `withoutOverlapping`). It is the only path that catches late-activated accounts and refreshes office and employee number for people who haven't loaded the SPA. The schedule only fires if the server runs `php artisan schedule:run` every minute (on Plesk: a Scheduled Task, see the CHANGELOG entry of 2026-10-29). A user whose number still isn't set after a run has a PRIME data problem (e.g. the same `deped_id_no` on two employee rows); `php artisan prime:sync-preview --section=users` lists each one with the reason.

**`UserResource`:** `{id, name, email, avatar_url, employee_no, office_id, office: {id, office_name, school_id}|null, is_active, two_factor_enabled, roles: string[], role_ids: int[], role: {id, name, label}|null, role_details: {id, name, label}[], data_scope: "own"|"school"|"all", personnel_id: int|null, personnel: {id, full_name}|null, permissions?: string[], is_self, can_edit?, can_delete?, can_reset_two_factor?, created_at, updated_at}`. `permissions` is only present on the auth endpoints (`/api/user`, login responses), where it feeds `AuthContext.can()`; list endpoints skip it to avoid resolving every user's permission set. `data_scope` (the resolved `User::dataScope()`) and `personnel_id` (the linked Personnel record's id) are on the auth endpoints and the `/api/users` list and update responses; the SPA uses them for scope-aware navigation. `personnel` (`{id, full_name}`) is included only when the requester holds `personnel.view`. `can_edit` (`users.edit` and target within tier, true on your own row), `can_delete` (always false) and `can_reset_two_factor` (`users.reset_two_factor`, within tier, not self, target has 2FA enabled) are only on the `/api/users` list and update responses.

Use **deactivation** (`is_active = false`) to remove access: a deactivated user cannot log in (same "Invalid credentials" as a wrong password), loses their trusted devices, and keeps clean attribution in the audit trail.

**A user needs at least one permission to sign in.** Someone with no role (typically a first-time PRIME login, which creates the local record automatically) or only roles that grant no permissions gets a `no_access` 403 at login, and a signed-in user whose last permission-granting role is removed is signed out on their next request. To grant access, assign a role here; the person then signs in again and enrolls in 2FA. Detail: [No-access refusal](../architecture.md#no-access-refusal).

## Guards

`RoleService`:

- **Protected `super_administrator`** — name never changes, permission set is always the full enum, never deleted.
- **In-use delete guard** — a role assigned to anyone cannot be deleted (409).
- **No granting what you don't hold** — a role create/edit can't add a permission the actor lacks (permissions the role already has may be kept or removed).
- **System-wide lockout** — a role edit can't remove `roles.edit`/`users.edit` if no role would then grant it to anyone.
- **Self-lockout** — you can't strip `roles.edit`/`users.edit` from a role you hold unless another of your roles still grants it.
- **Unknown permission names fail loudly** — a permission in the enum but not yet seeded is a validation error naming it, not a silent drop (this once made newly added permissions "not save").
- **Scope tier** — a role create/edit can't set a `data_scope` wider than the actor's own, and an actor can't edit (422 on `data_scope`) or delete (409) a role that is already wider than their scope. Without it, a `school` admin holding `roles.edit` could widen their own role to `all`.

`UserRoleService`:

1. **Self-edit without self-lockout** — you can edit your own row (`PUT /api/users/{self}`), but not deactivate yourself or drop your own `users.edit`/`roles.edit`. Resetting your own 2FA is refused.
2. **Permission-tier separation** (CRITICAL finding, 2026-07) — every assigned role's permissions must be a subset of the actor's own, and the target must be within the actor's tier. Without it, `users.edit` alone could grant `roles.edit` to anyone. The tier includes **data scope**: an actor can't assign a role scoped wider than their own (422) or manage an account holding one (403, and the row's `can_edit` is false), and `/users/options` `roles` leaves such roles out. Account creation is disabled anyway (PRIME), but `StoreUserRequest` applies the same office restriction.
3. **Last `users.edit` holder** — can't be synced into roles that don't grant it.

Both lockout checks run **inside the write transaction behind a `SELECT ... FOR UPDATE` on the sensitive row in Spatie's `permissions` table**. A 2026-07 review (MEDIUM) found the original unlocked pre-write count let two concurrent edits each see "another holder exists" and together zero out a sensitive permission. A lock outside the transaction would be released immediately and defeat the point.

Audit: `role.created|updated|deleted` (RoleService), `user.roles_changed` with before/after role lists (UserRoleService), `user.personnel_linked`/`user.personnel_unlinked` (UserController), User model CRUD via `LogsActivity` (name, email, avatar, office, is_active only).

## Frontend

| Route | Page |
|---|---|
| `/roles` | `pages/management/roles/RoleList.tsx` — list with a "Data scope" column, create/edit dialog with a "Data scope" select ("Own records only" / "Own office" / "All records", offering only scopes up to the actor's own plus the role's current one) and `PermissionPicker`; a role wider than the actor's scope opens read-only; protected badge (route needs `roles.view`; create/edit/delete need their own `roles.*`) |
| `/users` | `pages/management/users/UserList.tsx` — list with a "Linked personnel" column, edit dialog (office picker limited to the actor's own and the current office for scoped actors, "Linked personnel" picker for all-scope actors with `personnel.view`, role select, active switch), "Reset 2FA" (route needs `users.view`; edit needs `users.edit`, reset `users.reset_two_factor`) |

Scope-aware navigation, titles and the `RequireDataScope` guard live in `lib/dataScope.ts`, `layouts/ManagementLayout.tsx` and `router/Guards.tsx` ([architecture.md](../architecture.md#frontend-layout)).

API: `services/roleService.ts`, `services/userService.ts`; types: `types/role.ts`, `types/user.ts`, `types/auth.ts`.

## Key files

- `backend/app/Enums/Permission.php`, `DataScope.php`, `backend/app/Models/Role.php`, `User.php` (`dataScope()`, `scopedPersonnelIds()`, `reachesOffice()`, `personnel()`), and `scopeVisibleTo()`/`isVisibleTo()` on `Personnel.php`, `Equipment.php`, `IspAccount.php`
- `backend/app/Http/Requests/Management/Concerns/RestrictsScopedAssignments.php`
- `backend/tests/Feature/DataScope/`, `EquipmentDataScopeTest.php`, `RolePermissionSeederTest.php`, `Migrations/UnifiedDataScopeMigrationsTest.php`
- `backend/app/Http/Controllers/Api/Management/RoleController.php`, `PermissionController.php`, `UserController.php`
- `backend/app/Http/Requests/Management/StoreRoleRequest.php`, `UpdateRoleRequest.php`, `StoreUserRequest.php`, `UpdateUserRequest.php`, `ResetTwoFactorRequest.php`
- `backend/app/Http/Resources/Management/RoleResource.php`, `PermissionResource.php`, `UserResource.php`
- `backend/app/Services/RoleService.php`, `UserRoleService.php`
- `backend/app/Policies/RolePolicy.php`, `UserPolicy.php`
- `backend/database/seeders/RolePermissionSeeder.php`, `backend/database/migrations/2026_10_19_000002_convert_rbac_tables_to_spatie_permission.php`, `2026_10_20_170000_add_data_scope_to_roles_and_office_to_personnel.php`, `2026_10_26_000001`-`000005` (unified data scope)

## Non-obvious design decisions

- **Inventory's permission names were kept, not renamed to rwatch's `manage_users` style.** Renaming would have rewritten every Policy, test, seeder and stored grant for no behavioral gain, and broken the "permission name = enum value" invariant the guards rely on.
- **No `role:` middleware, no `hasRole()` in authorization** — even though rwatch uses them. Role names are data an admin can rename; permissions are the contract.
- **`pending` is gone.** Migration `2026_10_20_000002` dropped it along with the old `admin` role; an account with no permission-granting role is now refused at sign-in instead (see above).
- **Spatie caches permissions.** After editing grants in the database by hand, run `php artisan permission:cache-reset`.

## Future considerations

None open.
