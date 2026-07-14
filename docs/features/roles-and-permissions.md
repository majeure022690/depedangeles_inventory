# Roles & Permissions

## Purpose

Access control for the whole application, built by hand (not spatie/laravel-permission) around one hard rule: application code checks a granular permission string, never a role name. Roles themselves are genuine admin-configurable RBAC data — not a fixed, code-defined set — and a user may hold more than one role at once. This doc covers the model, the seeded starting roles, the `/roles` and `/users` admin screens, and the guards that protect both. See [`docs/architecture.md`](../architecture.md#authorization-model-granular-permissions-never-role-names) for the shorter cross-cutting summary; this doc goes deeper.

## Architecture

- `App\Enums\Permission` — backed enum, the single source of truth for what a "permission" is. 20 cases as of this writing (`personnel.*`, `equipment.*`, `equipment.transactions.create`, `isp_accounts.*`, `reference-data.manage`, `roles.manage`, `users.manage`, `stakeholder_profile.*`, `internet_connectivity.*`). `permissions` table rows are materialized from this enum by `RolePermissionSeeder`; an unknown/typo'd permission name fails loudly at seed time via `Permission::from()`, not silently. This catalog stays fixed/code-defined — only the role-to-permission bundling is dynamic.
- `App\Models\Permission` — thin Eloquent model backing the `permissions` table (one row per enum case).
- `App\Models\Role` — a named, **admin-configurable** bundle of permissions (`belongsToMany(Permission::class)`), never a branch in application code. `RolePermissionSeeder` seeds four starting roles, but roles are genuine RBAC data from there on — a `roles.manage` holder can create new roles, edit which permissions any role grants, and delete roles no longer in use, via `RoleController`/`RoleService`.
- `App\Models\User::hasPermissionTo()` — the one true authorization check in the app. Every Policy method and Gate ultimately bottoms out here, resolving against the flat, de-duplicated union of permissions granted by every role the user holds (`User::permissionNames()`, memoized per-request).
- `App\Services\RoleService` — the only sanctioned entry point for creating, editing, or deleting role definitions (the `roles.manage` screen).
- `App\Services\UserRoleService` — the only sanctioned entry point for changing which role(s) a user holds (the `users.manage` screen). `role_user` is a genuine many-to-many pivot — a user commonly needs more than one permission bundle — so `syncRoles()` replaces a user's entire role set in one call, Eloquent-`sync()`-style.
- `database/seeders/RolePermissionSeeder.php` — seeds `permissions` and composes the four starting roles below. Idempotent (`updateOrCreate` + `sync`).

## Two permission-tiers, deliberately separate

Two permissions are treated as a materially more sensitive category than every other permission in the system, because each gates the ability to change *someone's* access:

- **`users.manage`** — assign an *existing, already-vetted* role to a user.
- **`roles.manage`** — create/edit/delete role *definitions themselves*, i.e. decide what permissions a role bundle grants.

These are deliberately separate permissions, not one. Holding `users.manage` alone lets an admin hand out roles that already exist; it does **not** let them redefine what any role means or invent a role carrying permissions they don't hold themselves. Only `admin` is seeded with `roles.manage`.

## Seeded starting roles

| Role | Permissions | Notes |
|---|---|---|
| `pending` | *(none)* | Assigned automatically to every self-registered account — see below. Protected: its name can never be changed and its permission set can never become non-empty (`Role::isProtected()`, enforced in `RoleService`/`RoleUpdateRequest`). Cannot be deleted. |
| `viewer` | `personnel.view`, `equipment.view`, `isp_accounts.view`, `stakeholder_profile.view`, `internet_connectivity.view` | Read-only across every resource. |
| `encoder` | View/create/edit on Personnel, Equipment, ISP Accounts; `equipment.transactions.create`; view/edit on Stakeholder Profile and Internet Connectivity | Day-to-day data entry. No deletes, no reference-data/role/user admin. |
| `admin` | Every `Permission` case | Full access, including reference-data, role, and user administration. |

These four are ordinary, editable/deletable admin-created data once seeded — not hardcoded application behavior — with the single exception of `pending`, which is protected by name because Fortify's `CreateNewUser` action hardcodes a lookup against it (see `Role::PENDING`'s doc-comment).

## The `/roles` admin screen (`roles.manage`)

`App\Http\Controllers\RoleController` is full CRUD over role definitions (`Route::resource('roles', ...)->except('show')`), gated on `Permission::RolesManage`. Authorization ("does this actor hold `roles.manage`") lives in `RolePolicy`; every mutation-specific invariant lives in `RoleService`:

- **Protected `pending` role** — name and (empty) permission set can never change.
- **"In use" delete guard** — a role currently assigned to any user cannot be deleted; the target must be reassigned first.
- **System-wide lockout guard** — a role's permission set can never be edited to remove `roles.manage`/`users.manage` if doing so would leave **no role in the system** granting it to any user.
- **Self-lockout guard** — a user can never edit the permissions of a role *they themselves hold* in a way that strips `roles.manage`/`users.manage` from their own effective permission set, unless another role they hold still grants it.

Both lockout guards run inside the same DB transaction as the write, behind a row lock taken on the sensitive `Permission` row itself (`SELECT ... FOR UPDATE`) — a 2026-07 follow-up security review (MEDIUM) found the original unlocked pre-write count was subject to a TOCTOU race where two concurrent edits could each observe "another holder still exists" and both commit, collectively zeroing out a sensitive permission system-wide.

## The `/users` admin screen (`users.manage`)

`App\Http\Controllers\UserController` is a narrow admin screen — list users + sync one user's role **set** — not a general user-profile-editing resource, gated on `Permission::UsersManage`. Role assignment goes exclusively through `UserRoleService::syncRoles()`, which enforces:

1. **Self-escalation guard** — a user can never change their own role set. Enforced both at the HTTP layer (`UserRoleUpdateRequest::withValidator()`, so the error surfaces in the Inertia form) and again in the Service, so the invariant holds for any caller.
2. **Permission-tier separation guard** (CRITICAL, 2026-07 follow-up review) — a `users.manage` holder may only assign roles whose **entire** permission set is already a subset of their own effective permissions, checked against the full resulting role set (not just newly-added roles). Without this, holding `users.manage` alone was sufficient to grant *any* role — including one carrying `roles.manage` — to any account, silently bypassing the permission-tier separation described above. `UserRoleService::assignableRolesFor()` is the read-side mirror: `UserController::index()` uses it to build the `roles` prop so the UI never even offers an option the write side would reject.
3. **Last-`users.manage`-holder lockout guard** — the last remaining holder of `users.manage` can never be synced into a role set that doesn't grant it (a union check across all of that user's resulting roles, since permissions across roles are additive). Runs inside the write transaction behind the same chokepoint row lock pattern as `RoleService`'s lockout guard, for the same TOCTOU reason.

## Flow: how a user gets a role

1. **Self-registration** (Fortify's `CreateNewUser` action) assigns `pending` — zero permissions — never `viewer`. The account is authenticated-and-email-verified but can access nothing.
2. An **Administrator** (or any `users.manage` holder) reviews the account at `/users` and assigns real role(s) via `PATCH /users/{user}`, which calls `UserRoleService::syncRoles()`.
3. `syncRoles()` replaces the target's entire role set with the submitted `role_ids` array — a user may end up holding more than one role.
4. Every role-set change is audited (`user.roles_changed`, with before/after role id and name lists). Every role-definition change is audited separately (`role.created`/`role.updated`/`role.deleted`).

## Inertia routes / prop contracts

### `/roles` — `RoleController`

| Route | Page component | Key props |
|---|---|---|
| `GET /roles` (`roles.index`) | `roles/Index` | `roles`: `{id, name, label, description, user_count, permission_count, is_protected}[]` |
| `GET /roles/create` (`roles.create`) | `roles/Create` | `permissions`: `{value, label}[]` (the full `Permission` catalog) |
| `POST /roles` (`roles.store`) | — (redirects to `roles.edit`) | payload: `name`, `label`, `description`, `permissions[]` |
| `GET /roles/{role}/edit` (`roles.edit`) | `roles/Edit` | `role`: `{id, name, label, description, user_count, is_protected, permissions: string[]}`, `permissions`: `{value, label}[]` |
| `PATCH /roles/{role}` (`roles.update`) | — (redirects to `roles.edit`) | payload: `name`, `label`, `description`, `permissions[]` |
| `DELETE /roles/{role}` (`roles.destroy`) | — (redirects to `roles.index`) | — |

### `/users` — `UserController`

| Route | Page component | Key props |
|---|---|---|
| `GET /users` (`users.index`) | `users/Index` | `users` (paginated): `{id, name, email, roles: {id, name, label}[], role_ids: number[], is_self}`; `roles`: every role **assignable by the acting user** (`{id, name, label}[]`, via `assignableRolesFor()` — not necessarily every role in the system); `filters`: `{search, role}` |
| `PATCH /users/{user}` (`users.update`) | — (redirects to `users.index`) | payload: `role_ids[]` (array of role primary keys — `present`, not `required`, so submitting an empty array to strip every role is a valid, deliberate choice) |

This is deliberately a narrow admin screen — list users + sync their role set — not a general user-profile-editing resource. No create/store/edit-as-a-page/destroy routes exist for Users.

## Non-obvious design decisions

- **Granular permissions, never role names, everywhere.** `$user->can('equipment.delete')`, never `if ($user->role === 'admin')`. This is a hard project-wide rule (see `CLAUDE.md`), enforced in every Policy in this codebase.
- **`pending` is zero permissions, not "least privilege."** It's a deliberate holding pen, not a real role — see the security-review context below.
- **`users.manage` and `roles.manage` are separate permissions, not one**, because assigning an already-vetted role to a user is materially less sensitive than being able to redefine what any role means or grant a new one — see "Two permission-tiers, deliberately separate" above.
- **Role-lockout guards run behind a `SELECT ... FOR UPDATE` chokepoint lock**, taken on the sensitive `Permission` row, and specifically *inside* the same transaction as the write — a lock taken outside a transaction is released the instant its own `SELECT` completes and defeats the point. `RoleService::guardAgainstLockout()` and `UserRoleService::syncRoles()` both lock the same row for the same permission, so two concurrent transactions racing to strip the last holder of one sensitive permission from two different roles/users always serialize correctly.
- **`permissionNames()` is memoized per-request** on the `User` model instance, so repeated `can()`/`hasPermissionTo()` calls in the same request don't re-run the roles→permissions join. `assignRole()`/`removeRole()`/`forgetCachedPermissions()` invalidate the cache; `RoleService::update()` proactively clears it for every user holding an edited role.
- **Frontend never re-derives authorization.** `auth.permissions` (flat string array) is shared to every Inertia page via `HandleInertiaRequests`; `usePermissions()` just reads it. The server re-checks every mutating request via the same Policies regardless of what the client renders — the composable is a UX convenience only.

## Security context: why `pending` exists

A 2026-07 security review (Finding #1) found that open self-registration exposed real DepEd Personnel PII to anyone who could click their own emailed verification link — no admin vetting. The fix was `CreateNewUser` assigning `pending` (never `viewer`) to every self-registration, closing the gap. Regression-tested end-to-end (not just "a zero-permission role exists," but that it actually 403s on real routes) in `tests/Feature/Auth/RegistrationApprovalTest.php`.

The same review (Finding #2) found that an account holding `users.manage` could self-delete via the ordinary account-deletion page with no admin review — exactly the account whose disappearance could leave the system unadministered. `ProfileDeleteRequest` (owned by the auth/settings layer, not this admin screen) denies self-deletion for any account holding `users.manage`; non-privileged self-deletion is unaffected. Regression-tested in `tests/Feature/Settings/ProfileUpdateTest.php`.

A **follow-up security review**, conducted once roles became admin-editable and users could hold more than one role, found two further issues, both fixed and regression-tested:

- **CRITICAL — permission-tier separation was not enforced on role assignment.** A `users.manage` holder could assign *any* role, including one carrying `roles.manage`, to any account — silently bypassing the tier separation `Permission::RolesManage`'s doc-comment always described but nothing previously enforced. Fixed by `UserRoleService::guardAgainstPermissionTierViolation()`.
- **MEDIUM — TOCTOU race in the last-holder lockout checks.** Both `RoleService`'s and `UserRoleService`'s "would this leave zero holders of a sensitive permission" checks ran as unlocked pre-write `SELECT`s, so two concurrent transactions each demoting a *different* holder of the same permission could each observe "another holder still exists" and both commit, collectively zeroing out that permission system-wide. Fixed by moving both checks inside their write transaction, behind a `SELECT ... FOR UPDATE` lock on the same `Permission` row.

## Key files

- `app/Enums/Permission.php`
- `app/Models/Permission.php`, `Role.php`, `User.php`
- `app/Http/Controllers/RoleController.php`, `UserController.php`
- `app/Services/RoleService.php`, `UserRoleService.php`
- `app/Http/Requests/RoleStoreRequest.php`, `RoleUpdateRequest.php`, `UserRoleUpdateRequest.php`
- `app/Policies/RolePolicy.php` (and every other Policy in the app, which defers to `hasPermissionTo()`)
- `app/Http/Middleware/HandleInertiaRequests.php` (shares `auth.permissions`)
- `resources/js/composables/usePermissions.ts`
- `resources/js/pages/roles/Index.vue`, `Create.vue`, `Edit.vue`; `resources/js/pages/users/Index.vue`
- `database/seeders/RolePermissionSeeder.php`
- `database/migrations/2026_07_10_031944_create_permissions_table.php`, `create_roles_table.php`, `2026_07_10_031945_create_permission_role_table.php`, `create_role_user_table.php` (the `role_user` pivot was many-to-many from day one; multi-role assignment became reachable through the UI once `UserRoleService::syncRoles()` replaced the original single-role `changeRole()`)
- `tests/Feature/Auth/RegistrationApprovalTest.php`, `tests/Feature/UserControllerTest.php`, `tests/Feature/Settings/ProfileUpdateTest.php`

## Future considerations

- None open today specific to this feature — reference-data administration (formerly `lookups.manage`) is documented separately in [`docs/features/reference-data.md`](reference-data.md).
