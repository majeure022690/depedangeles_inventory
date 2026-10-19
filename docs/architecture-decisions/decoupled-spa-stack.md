# ADR: Decoupled SPA stack (Laravel JSON API + React SPA), mirroring rwatch

- **Status:** Accepted (owner decision, 2026-10-19). Execution plan: [`decoupled-spa-stack-plan.md`](decoupled-spa-stack-plan.md).
- **Decided by:** project owner. Recorded by `architect`.
- **Supersedes:** the "server-driven Inertia monolith, no REST API" rule in `CLAUDE.md`, the Inertia/Fortify/Wayfinder/passkeys/Vue stack, the hand-rolled RBAC tables, and the custom `audit_logs` table. The [lookup-normalization ADR](lookup-normalization.md) is **not** affected; its 13 tables, tiers and validation rules carry over unchanged.

## Context

The owner wants this app on the same stack, folder layout, UI/UX, sidebar, components and security model as the sibling project **rwatch** (`C:\xampp\htdocs\rwatch`). That way one small team maintains two systems built the same way, not two unrelated stacks. This is a consistency decision, not a scale decision. Inventory's features and data model stay as they are: Equipment with transactions and QR, Personnel, ISP accounts with speed tests and subscription costs, Offices, Stakeholder Profile, Internet Connectivity Survey, the 13 reference-data tables, Users, Roles, Audit Log, Dashboard, and profile/security settings.

The first step is already done. The Laravel app now lives in `backend/` (commit `ea3b08a`), and the old Vue pages under `backend/resources/js` stay as a read-only porting reference until the new frontend is complete.

## Decision

### 1. Two apps, one repo

| | `backend/` | `frontend/` |
|---|---|---|
| What | Laravel 13 / PHP 8.4, **JSON API only** | React 19 + TypeScript + Vite + React Router 7 + axios + Tailwind 4 + Radix + TanStack Table + lucide-react |
| Auth | Sanctum **SPA cookie** auth (`statefulApi()`, `/sanctum/csrf-cookie`, CORS restricted to `FRONTEND_URL`, `supports_credentials`) | `lib/api.ts` (axios `withCredentials` + `withXSRFToken`), `context/AuthContext`, `router/Guards` |
| Dev | `php artisan serve` (:8000) | `npm run dev` (:5173) |

In production the frontend's web server serves the SPA and reverse-proxies `/api` and `/sanctum` to the backend, so the browser sees a single origin. This is the same as rwatch's `frontend/docker/default.conf.template`.

### 2. Backend layout mirrors rwatch's audience namespacing

- `routes/api.php` only `require`s `routes/api/auth.php` and `routes/api/management.php`. There is **no `public.php`**: inventory has no unauthenticated portal.
- `app/Http/Controllers/Api/{Auth,Management}/`, `app/Http/Requests/{Auth,Management}/`, `app/Http/Resources/Management/`.
- `app/Models`, `app/Policies`, `app/Services`, `app/Enums`, `app/Rules`, `app/Http/Middleware` stay **flat**.

**This does not reverse the flat-vs-`Domain/` decision.** The `Auth`/`Management` split groups code by *API audience*, which is how rwatch is organized, not by business feature. There is still no `app/Domain/<Feature>/`. The revisit trigger from `CLAUDE.md` still applies: more than about 6-8 files backing one feature in one flat directory, or real multi-tenant expansion.

### 3. Frontend layout mirrors `rwatch/frontend/src`

`components/ui`, `components/<feature>`, `components/management`, `context/AuthContext.tsx`, `hooks`, `layouts/ManagementLayout.tsx`, `lib/api.ts`, `router/Guards.tsx`, `services/<feature>Service.ts`, `types/`, `pages/management/<feature>/`. The staff portal lives under `/portal/*`. rwatch's public pages, i18n, Leaflet maps, notifications, tours, gallery, and the branding/site-settings panels are **not** ported; inventory has no use for them.

### 4. Authentication: rwatch's model, ported

The login flow is a custom `AuthController::login`, then **mandatory TOTP 2FA** for every account (pragmarx/google2fa, setup/verify tokens held in cache), trusted devices, hashed recovery codes, forgot/reset password, idle-timeout logout, and reCAPTCHA v3 on login. Alongside it come a `security` log channel, the `SecurityHeaders` middleware, named rate limiters (`login`, `password-reset`, `two-factor-verify`, `authenticated-api`, `password-confirm`, `export`) backed by `SecuritySettings`/`system_settings`, and JSON-only exception rendering.

What this drops, and why:
- **Self-registration** (Fortify `registration`): rwatch has none, because admins create accounts. This removes the open-registration PII exposure path outright instead of mitigating it. The `pending` role is kept as an ordinary zero-permission, protected role.
- **Email verification.** Accounts are admin-created.
- **Self-service account deletion.** Admins deactivate accounts with `users.is_active`, which keeps audit attribution intact.
- **Passkeys** (`laravel/passkeys`).
- **The `RequirePassword` confirm-password page.** Sensitive self-service actions require `current_password` in the request body instead, as in rwatch's `UpdateProfileRequest`/`RegenerateRecoveryCodesRequest`, throttled by `password-confirm`.

### 5. Authorization: Spatie laravel-permission, inventory's permission names

- **Inventory's existing permission strings become the Spatie permission names unchanged** (`equipment.edit`, `equipment.transactions.create`, `reference-data.manage`, `stakeholder_profile.view_all`, ...). `App\Enums\Permission` stays the single source of truth; the seeder materializes it with `Permission::findOrCreate($case->value, 'web')`.
  - *This is sound.* Spatie treats permission names as opaque strings. Dots only mean something when `permission.enable_wildcard_permission` is `true`, and that **must stay `false`** (rwatch's default). With wildcards on, `equipment.transactions.create` would be parsed as wildcard segments. The `|` used by `permission:a|b` route middleware never appears in our names. Spatie's `hasPermissionTo()` accepts a `BackedEnum`, so every existing `$user->hasPermissionTo(Permission::X)` call site keeps compiling.
  - *Rejected: adopting rwatch's `manage_users`-style names.* That would rewrite every Policy, test, seeder and stored role grant for zero behavioral gain, and it would break the "permission name = enum value" invariant the RBAC guards rely on.
- **One new permission:** `security_settings.manage` (enum case `SecuritySettingsManage`), seeded to `admin` only. It gates the Security Settings screen, which rwatch gates with `manage_security_settings`.
- Authorization works in two layers, as in rwatch. Route-level `permission:` middleware is a fast fail. **Policies remain the authoritative gate** and are re-checked in every controller action. That matters most for the office-scoped Stakeholder Profile and Internet Connectivity policies, where the same permission means different things depending on `office_id`.
- **The no-role-name-checks rule survives this conversion and overrides rwatch where they differ.** Two rwatch constructs are *not* ported:
  - the `role:` middleware ("Administrator only" route classification);
  - `config('rabieswatch.two_factor_always_required_roles')`.

  Their inventory equivalents use permissions. `TrustedDeviceService::days()` returns 0, meaning the authenticator code is always required, for any user holding `roles.manage` or `users.manage`.
- The existing RBAC guards in `RoleService`/`UserRoleService` are kept as-is on top of Spatie's tables: no self-escalation, last-holder lockout under a row lock on the sensitive `permissions` row, and `users.manage` permission-tier separation. The protected `pending` role is also kept. `App\Models\Role extends Spatie\Permission\Models\Role` and keeps inventory's `label`/`description` columns. `App\Models\Permission` is deleted; Spatie's model is used directly. The custom `Gate::before` hook and `User::permissionNames()`/`assignRole()`/`removeRole()`/`hasPermissionTo()` are deleted in favor of Spatie's `HasRoles` trait.

### 6. Audit: spatie/laravel-activitylog replaces `audit_logs`

- Model CRUD uses the `LogsActivity` trait (`logOnly([...])->logOnlyDirty()->dontLogEmptyChanges()`), as in rwatch. Each model's `logOnly` list is an explicit allowlist; secrets and 2FA columns are never logged.
- Non-CRUD events use `activity()->event('<dotted.name>')`, keeping the existing names: `equipment.transaction.recorded`, `isp_account.speed_test.recorded`, `isp_account.subscription_cost.recorded`, `role.assigned`, `role.revoked`, `user.roles_changed`, `authorization.denied`, `reference_data.*`.
- **Actor attribution survives account deletion.** `activity_log.causer_id` has no FK, so the id itself survives. Every activity row additionally carries `properties.actor_snapshot` (`name`, `email`), which keeps the `AuditLog::record()` guarantee. It is written in one place: an `Activity::creating` hook registered in `AppServiceProvider`.
- **Retention deliberately differs from rwatch:** `activitylog:clean` is **not** scheduled. Inventory's audit trail has always been permanent and append-only; deleting old rows would quietly reverse that.
- Existing `audit_logs` rows are copied into `activity_log` (mapping in the plan, Phase 1), verified, and only then is `audit_logs` dropped (Phase 7).

### 7. Data migration strategy: convert in place, keep ids

`roles` and `permissions` are **altered in place**: add `guard_name`, and change the unique index to `(name, guard_name)`. They are not recreated. Spatie's pivots (`model_has_roles`, `role_has_permissions`, `model_has_permissions`) are created and filled from `role_user`/`permission_role`, which are then dropped. Keeping the primary keys avoids an id-remapping step and keeps any id that has already appeared in audit `properties` meaningful. Spatie's stock `create_permission_tables` migration is **not** published. The 2FA secrets that Fortify encrypted are re-encrypted into the `encrypted`/`encrypted:array` cast format that rwatch's code reads. Details are in the plan.

### 8. Equipment QR codes stay stable

Printed QR labels are long-lived physical artifacts. They encode `equipment.qr_code`, today `APP_URL/equipment/{id}/edit`. Two things keep those labels working:
- the backend keeps exactly one web route, `GET /equipment/{equipment}/edit`, which redirects (302) to `FRONTEND_URL/portal/equipment/{id}/edit`;
- the SPA has an alias route `/equipment/:id/edit` → `/portal/equipment/:id/edit`, for deployments where the frontend host is the one that receives the scan.

Stored `qr_code` values are not rewritten.

### 9. "No REST API" is replaced, not abandoned

The first-party SPA is now the API's only consumer. `integration`'s rule still applies to **third-party** consumers: no token issuance, no public or unauthenticated endpoints, and no versioned external API without a named external consumer. The `personal_access_tokens` table is still created (as in rwatch) for one reason. Without it, a request carrying a stray `Authorization: Bearer …` header makes Sanctum's guard query a missing table and return a 500 instead of a 401.

## Consequences

- **Positive:** one stack and one set of conventions across both systems; rwatch's security hardening comes over as-is (mandatory 2FA, rate limiters, security headers, security log, reCAPTCHA); the self-registration attack surface disappears; Spatie gives permission caching and standard tooling.
- **Negative / costs:**
  - Two dev processes, plus CORS and cookie configuration. Dev must use `localhost` consistently, not `127.0.0.1`, or the session cookie won't be sent.
  - Every Inertia prop contract becomes an endpoint, including the "options" payloads forms used to receive as props.
  - The entire Feature test suite is rewritten for JSON.
  - Existing users must enroll TOTP on their next login if they hadn't already, and all previously trusted devices are forgotten once.
  - Spatie caches permissions, so tests and seeders must reset that cache.
- **Kept from before:** the lookup-normalization tiers; the three data-access patterns (accountability-transfer, append-only child log, one-row-per-office); `EquipmentAccountabilityService`; the reference-data registry (`config/reference-data.php`) and its single generic controller; Form Requests for validation; Policies for authorization; deny-by-default.

## Revisit triggers

- A real third-party consumer appears. That is `integration`'s call, needing a token-based surface and a separate ADR.
- rwatch changes a shared security primitive (2FA, trusted devices, rate limiters). Port the change deliberately; don't let the two drift silently.
