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

| Role | Permissions |
|---|---|
| `super_administrator` | every enum case. Protected: cannot be renamed or deleted, its permission set must always be the full enum, and its `data_scope` is forced to `all`. |
| `system_administrator` | every enum case except `roles.create`, `roles.edit`, `roles.delete` |
| `encoder` | view/create/edit on Personnel, Equipment, ISP Accounts; `equipment.transactions.create`; view/edit on Stakeholder Profile and Internet Connectivity. No deletes, no admin. |
| `viewer` | `personnel.view`, `equipment.view`, `stakeholder_profile.view`, `internet_connectivity.view`; `data_scope` `own` |

Apart from `super_administrator` these are ordinary data once seeded. `RolePermissionSeeder` is idempotent and resets Spatie's permission cache.

## API endpoints

### Roles and the permission catalog

| Method + path | Route permission | Policy | Request → response |
|---|---|---|---|
| `GET /api/roles` | `roles.view` or `users.edit` | `viewAny` | `RoleResource[]` (not paginated) with `user_count`, `permission_count`, ordered by label |
| `GET /api/roles/{id}` | `roles.view` or `users.edit` | `view` | `RoleResource` with `permissions` |
| `GET /api/permissions` | `roles.view` or `users.edit` | `RolePolicy::viewAny` | `{id, name, label, group}[]` in enum order — feeds `components/management/PermissionPicker.tsx`, so groups come from the server, not a duplicated frontend list |
| `POST /api/roles` | `roles.create` | `create` | `StoreRoleRequest`: `name` (lowercase `[a-z0-9_-]`, unique), `label`, `description`, optional `data_scope`, `permissions[]` (enum values) → `RoleResource`, 201 |
| `PUT /api/roles/{id}` | `roles.edit` | `update` | `UpdateRoleRequest`, same fields → `RoleResource` |
| `DELETE /api/roles/{id}` | `roles.delete` | `delete` | 204, or **409** with the reason (protected `super_administrator`, or still assigned to users) |

**`RoleResource`:** `{id, name, label, description, data_scope, is_protected, user_count?, permission_count?, permissions?: string[], created_at, updated_at}`.

### Users

The group needs any of `users.view`, `users.edit`, `users.reset_two_factor`; each route then narrows it as listed, and `UserPolicy` and `UserRoleService` re-check. Accounts come from PRIME, so there is no create or delete.

| Method + path | Route permission | Request → response |
|---|---|---|
| `GET /api/users?search&role&is_active&office_id&sort&direction&per_page` | `users.view` | paginated `UserResource`. `search` matches name/email; `role` is a role **name**. Sort keys: `name` (default), `email`, `is_active`, `created_at`. |
| `GET /api/users/options` | `users.view` or `users.edit` | `{data: {roles: {id, name, label}[], offices: {value, label}[]}}` — `roles` lists only roles the acting user may assign (`assignableRolesFor()`), so the UI never offers one the write side would reject |
| `POST /api/users` | group only | always 403 (`User creation is disabled. Users are managed through PRIME.`) |
| `PUT /api/users/{id}` | `users.edit` | `UpdateUserRequest`: `office_id`, `is_active`, `role_id` (required, replaces the user's role) → `UserResource`. Name and email come from PRIME and aren't editable here; there is no password field. Editing your own row is allowed, but `is_active: false` or dropping your own `users.edit`/`roles.edit` is a 422. |
| `DELETE /api/users/{id}` | group only | always 403 (deletion is disabled; deactivate instead) |
| `POST /api/users/{id}/2fa/reset` | `users.reset_two_factor` | `current_password` (the admin's) → `{message}`. Clears the user's 2FA so they enroll again at next login; also revokes their trusted devices. Logged to activity (`user.two_factor_reset`) and the `security` channel. Refuses self. |

**`UserResource`:** `{id, name, email, avatar_url, office_id, office: {id, office_name, school_id}|null, is_active, two_factor_enabled, roles: string[], role_ids: int[], role: {id, name, label}|null, role_details: {id, name, label}[], permissions?: string[], is_self, can_edit?, can_delete?, can_reset_two_factor?, created_at, updated_at}`. `permissions` is only present on the auth endpoints (`/api/user`, login responses), where it feeds `AuthContext.can()`; list endpoints skip it to avoid resolving every user's permission set. `can_edit` (`users.edit` and target within tier, true on your own row), `can_delete` (always false) and `can_reset_two_factor` (`users.reset_two_factor`, within tier, not self, target has 2FA enabled) are only on the `/api/users` list and update responses.

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

`UserRoleService`:

1. **Self-edit without self-lockout** — you can edit your own row (`PUT /api/users/{self}`), but not deactivate yourself or drop your own `users.edit`/`roles.edit`. Resetting your own 2FA is refused.
2. **Permission-tier separation** (CRITICAL finding, 2026-07) — every assigned role's permissions must be a subset of the actor's own, and the target must be within the actor's tier. Without it, `users.edit` alone could grant `roles.edit` to anyone.
3. **Last `users.edit` holder** — can't be synced into roles that don't grant it.

Both lockout checks run **inside the write transaction behind a `SELECT ... FOR UPDATE` on the sensitive row in Spatie's `permissions` table**. A 2026-07 review (MEDIUM) found the original unlocked pre-write count let two concurrent edits each see "another holder exists" and together zero out a sensitive permission. A lock outside the transaction would be released immediately and defeat the point.

Audit: `role.created|updated|deleted` (RoleService), `user.roles_changed` with before/after role lists (UserRoleService), User model CRUD via `LogsActivity` (name, email, avatar, office, is_active only).

## Frontend

| Route | Page |
|---|---|
| `/roles` | `pages/management/roles/RoleList.tsx` — list, create/edit dialog with `PermissionPicker`, protected badge (route needs `roles.view`; create/edit/delete need their own `roles.*`) |
| `/users` | `pages/management/users/UserList.tsx` — list, edit dialog (office picker, role select, active switch), "Reset 2FA" (route needs `users.view`; edit needs `users.edit`, reset `users.reset_two_factor`) |

API: `services/roleService.ts`, `services/userService.ts`; types: `types/role.ts`, `types/user.ts`, `types/auth.ts`.

## Key files

- `backend/app/Enums/Permission.php`, `backend/app/Models/Role.php`, `User.php`
- `backend/app/Http/Controllers/Api/Management/RoleController.php`, `PermissionController.php`, `UserController.php`
- `backend/app/Http/Requests/Management/StoreRoleRequest.php`, `UpdateRoleRequest.php`, `StoreUserRequest.php`, `UpdateUserRequest.php`, `ResetTwoFactorRequest.php`
- `backend/app/Http/Resources/Management/RoleResource.php`, `PermissionResource.php`, `UserResource.php`
- `backend/app/Services/RoleService.php`, `UserRoleService.php`
- `backend/app/Policies/RolePolicy.php`, `UserPolicy.php`
- `backend/database/seeders/RolePermissionSeeder.php`, `backend/database/migrations/2026_10_19_000002_convert_rbac_tables_to_spatie_permission.php`

## Non-obvious design decisions

- **Inventory's permission names were kept, not renamed to rwatch's `manage_users` style.** Renaming would have rewritten every Policy, test, seeder and stored grant for no behavioral gain, and broken the "permission name = enum value" invariant the guards rely on.
- **No `role:` middleware, no `hasRole()` in authorization** — even though rwatch uses them. Role names are data an admin can rename; permissions are the contract.
- **`pending` is gone.** Migration `2026_10_20_000002` dropped it along with the old `admin` role; an account with no permission-granting role is now refused at sign-in instead (see above).
- **Spatie caches permissions.** After editing grants in the database by hand, run `php artisan permission:cache-reset`.

## Future considerations

None open.
