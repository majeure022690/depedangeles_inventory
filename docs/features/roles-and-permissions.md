# Roles, Permissions & Users

## Purpose

Access control for the whole application, on spatie/laravel-permission, around one hard rule: application code checks a granular permission, never a role name. Roles are admin-editable data, a user may hold several, and accounts are created by administrators (there is no self-registration). This doc covers the model, the seeded roles, the `/roles` and `/users` screens and their API, and the guards that protect them. The cross-cutting summary (route fast-fail vs Policy, frontend gating) is in [`docs/architecture.md`](../architecture.md#authorization-model-granular-permissions-never-role-names).

## Architecture

- `App\Enums\Permission` — the single source of truth, one case per permission (`personnel.*`, `equipment.*`, `equipment.transactions.create`, `isp_accounts.*`, `office.*`, `stakeholder_profile.*`, `internet_connectivity.*`, `reference-data.manage`, `roles.manage`, `users.manage`, `audit_log.view`, `security_settings.manage`). Each case has `label()` and `group()` for the role editor. Only features that exist get permissions.
- Spatie's `permissions` table is materialized from the enum by `RolePermissionSeeder` (`Permission::findOrCreate($case->value, 'web')`). The enum value **is** the Spatie name; keep `permission.enable_wildcard_permission` false or the dots become wildcard segments.
- `App\Models\Role extends Spatie\Permission\Models\Role`, adding `label`/`description`, `Role::PENDING`/`isProtected()`, and assigned-user counts. Registered with `RolePolicy` explicitly in `AppServiceProvider`. There is no custom Permission model; Spatie's is used directly.
- `User` uses Spatie's `HasRoles`; `getAllPermissions()` is the flat union across roles.
- `App\Services\RoleService` — the only entry point for creating, editing, deleting role definitions.
- `App\Services\UserRoleService` — the only entry point for creating, editing, deleting user accounts and changing a user's role set (`syncRoles()` replaces the whole set).
- Policies: `RolePolicy` (abilities audited on denial), `UserPolicy` (`viewAny`/`create`/`update`/`delete`/`resetTwoFactor`, all `users.manage`; `update` includes self, `delete` and `resetTwoFactor` refuse self).

The ids of roles and permissions from before the conversion were kept: migration `2026_10_19_000002` altered the old tables in place (added `guard_name`, unique `(name, guard_name)`) and copied `role_user`/`permission_role` into Spatie's pivots rather than recreating tables.

## Two sensitive permissions, deliberately separate

- **`users.manage`** — create/edit/deactivate accounts and assign *existing* roles.
- **`roles.manage`** — define what a role grants.

Holding `users.manage` alone does not let anyone redefine a role or hand out permissions they lack themselves. Only `admin` is seeded with `roles.manage`. Holders of either always need their authenticator code: the "trust this device" skip is disabled for them (`TrustedDeviceService::days()`).

## Seeded starting roles

| Role | Permissions |
|---|---|
| `pending` | none. Protected: name and empty permission set can never change, cannot be deleted. Kept as an ordinary zero-permission role for accounts that should exist but see nothing yet. |
| `viewer` | `personnel.view`, `equipment.view`, `isp_accounts.view`, `stakeholder_profile.view`, `internet_connectivity.view` |
| `encoder` | view/create/edit on Personnel, Equipment, ISP Accounts; `equipment.transactions.create`; view/edit on Stakeholder Profile and Internet Connectivity. No deletes, no admin. |
| `admin` | every enum case, including `security_settings.manage` |

Apart from `pending` these are ordinary data once seeded. `RolePermissionSeeder` is idempotent and resets Spatie's permission cache.

## API endpoints

### Roles and the permission catalog

| Method + path | Route permission | Policy | Request → response |
|---|---|---|---|
| `GET /api/roles` | `roles.manage` or `users.manage` | `viewAny` | `RoleResource[]` (not paginated) with `user_count`, `permission_count`, ordered by label |
| `GET /api/roles/{id}` | `roles.manage` or `users.manage` | `view` | `RoleResource` with `permissions` |
| `GET /api/permissions` | `roles.manage` or `users.manage` | `RolePolicy::viewAny` | `{id, name, label, group}[]` in enum order — feeds `components/management/PermissionPicker.tsx`, so groups come from the server, not a duplicated frontend list |
| `POST /api/roles` | `roles.manage` | `create` | `StoreRoleRequest`: `name` (lowercase `[a-z0-9_-]`, unique), `label`, `description`, `permissions[]` (enum values) → `RoleResource`, 201 |
| `PUT /api/roles/{id}` | `roles.manage` | `update` | `UpdateRoleRequest`, same fields → `RoleResource` |
| `DELETE /api/roles/{id}` | `roles.manage` | `delete` | 204, or **409** with the reason (protected `pending`, or still assigned to users) |

**`RoleResource`:** `{id, name, label, description, is_protected, user_count?, permission_count?, permissions?: string[], created_at, updated_at}`.

### Users

All under `permission:users.manage`, re-checked by `UserPolicy` and `UserRoleService`.

| Method + path | Request → response |
|---|---|
| `GET /api/users?search&role&is_active&office_id&sort&direction&per_page` | paginated `UserResource`. `search` matches name/email; `role` is a role **name**. Sort keys: `name` (default), `email`, `is_active`, `created_at`. |
| `GET /api/users/options` | `{data: {roles: {id, name, label}[], offices: {value, label}[]}}` — `roles` lists only roles the acting user may assign (`assignableRolesFor()`), so the UI never offers one the write side would reject |
| `POST /api/users` | `StoreUserRequest`: `name`, `email` (unique), `password` + `password_confirmation` (min 12, mixed case, number, symbol), `office_id`, `is_active` (default true), `role_ids[]` (`present`; may be empty) → `UserResource`, 201 |
| `PUT /api/users/{id}` | `UpdateUserRequest`: `name`, `email`, `office_id`, `is_active`, `role_ids[]` → `UserResource`. No password field: users change their own password from their profile. Editing your own row is allowed (same `users.manage` gate), but an email change then needs `current_password` (throttled by the `user-update` limiter, 6/min), and `is_active: false` or dropping your own `roles.manage`/`users.manage` is a 422. |
| `DELETE /api/users/{id}` | 204 |
| `POST /api/users/{id}/2fa/reset` | `current_password` (the admin's) → `{message}`. Clears the user's 2FA so they enroll again at next login; also revokes their trusted devices. Logged to activity (`user.two_factor_reset`) and the `security` channel. Refuses self. |

**`UserResource`:** `{id, name, email, avatar_url, office_id, office: {id, office_name, school_id}|null, is_active, two_factor_enabled, roles: string[], role_ids: int[], role_details: {id, name, label}[], permissions?: string[], is_self, can_edit?, can_manage?, created_at, updated_at}`. `permissions` is only present on the auth endpoints (`/api/user`, login responses), where it feeds `AuthContext.can()`; list endpoints skip it to avoid resolving every user's permission set. `can_edit` (`UserPolicy::update`, true on your own row) and `can_manage` (delete/deactivate/reset 2FA, always false on your own row) are only on the `/api/users` list, create and update responses.

Prefer **deactivating** (`is_active = false`) over deleting: a deactivated user cannot log in (same "Invalid credentials" as a wrong password), loses their trusted devices, and keeps clean attribution in the audit trail.

**A user needs at least one permission to sign in.** Someone with no role (typically a first-time PRIME login, which creates the local record automatically) or only roles that grant no permissions gets a `no_access` 403 at login, and a signed-in user whose last permission-granting role is removed is signed out on their next request. To grant access, assign a role here; the person then signs in again and enrolls in 2FA. Detail: [No-access refusal](../architecture.md#no-access-refusal).

## Guards

`RoleService`:

- **Protected `pending`** — name and (empty) permission set never change; never deleted.
- **In-use delete guard** — a role assigned to anyone cannot be deleted (409).
- **System-wide lockout** — a role edit can't remove `roles.manage`/`users.manage` if no role would then grant it to anyone.
- **Self-lockout** — you can't strip `roles.manage`/`users.manage` from a role you hold unless another of your roles still grants it.
- **Unknown permission names fail loudly** — a permission in the enum but not yet seeded is a validation error naming it, not a silent drop (this once made newly added permissions "not save").

`UserRoleService`:

1. **Self-edit without self-lockout** — you can edit your own row (`PUT /api/users/{self}`), but not deactivate yourself, drop your own `roles.manage`/`users.manage`, or change your email without `current_password`. Deleting yourself and resetting your own 2FA are refused.
2. **Permission-tier separation** (CRITICAL finding, 2026-07) — on create and update, every assigned role's permissions must be a subset of the actor's own. Without it, `users.manage` alone could grant `roles.manage` to anyone.
3. **Last `users.manage` holder** — can't be synced into roles that don't grant it, and can't be deleted.

Both lockout checks run **inside the write transaction behind a `SELECT ... FOR UPDATE` on the sensitive row in Spatie's `permissions` table**. A 2026-07 review (MEDIUM) found the original unlocked pre-write count let two concurrent edits each see "another holder exists" and together zero out a sensitive permission. A lock outside the transaction would be released immediately and defeat the point.

Audit: `role.created|updated|deleted` (RoleService), `user.roles_changed` with before/after role lists (UserRoleService), User model CRUD via `LogsActivity` (name, email, avatar, office, is_active only).

## Frontend

| Route | Page |
|---|---|
| `/roles` | `pages/management/roles/RoleList.tsx` — list, create/edit dialog with `PermissionPicker`, protected badge (`roles.manage`) |
| `/users` | `pages/management/users/UserList.tsx` — list, create/edit dialogs (office picker, role checkboxes, active switch), "Reset 2FA" (`users.manage`) |

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
- **`pending` survived the removal of self-registration** as a plain protected zero-permission role; nothing assigns it automatically anymore.
- **Spatie caches permissions.** After editing grants in the database by hand, run `php artisan permission:cache-reset`.

## Future considerations

None open.
