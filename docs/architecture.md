# Architecture

How the codebase is organized and the cross-cutting patterns every feature shares. Per-module API contracts are in [`docs/features/`](features); setup is in the root [`README.md`](../README.md); the stack decision and its reasoning are in the [decoupled-SPA ADR](architecture-decisions/decoupled-spa-stack.md).

## Stack

Two apps in one repo, deliberately mirroring the sibling project **rwatch** so one small team maintains two systems built the same way (a consistency decision, not a scale one — see the ADR):

| | `backend/` | `frontend/` |
|---|---|---|
| What | Laravel 13 / PHP 8.4, **JSON API only** under `/api` | React 19 + TypeScript SPA (Vite, React Router 7, axios, Tailwind 4, Radix, TanStack Table, lucide-react), staff portal under `/*` |
| Auth | Sanctum SPA **cookie** sessions, mandatory TOTP 2FA | `context/AuthContext.tsx`, `router/Guards.tsx` |
| Authorization | spatie/laravel-permission + Policies | `AuthContext.can()` for UI gating only |
| Audit | spatie/laravel-activitylog (`activity_log`) | `pages/management/AuditLog.tsx` |
| Dev server | `php artisan serve` (:8000) | `npm run dev` (:5173) |

The only API consumer is this SPA. There are no public/unauthenticated data endpoints and no token issuance; a third-party consumer would need its own ADR (`integration`'s call). The project-structure rules (audience-namespaced `Api/{Auth,Management}`, everything else flat, no `app/Domain/`) and the agent roster live in [`CLAUDE.md`](../CLAUDE.md) and are not repeated here.

### Backend layout

- `routes/api.php` only requires `routes/api/auth.php` (login, 2FA, profile, trusted devices) and `routes/api/management.php` (every inventory resource). `routes/web.php` holds exactly one route — the equipment QR redirect (see below).
- `app/Http/Controllers/Api/{Auth,Management}/`, `app/Http/Requests/{Auth,Management}/`, `app/Http/Resources/Management/`. Every response goes through an explicit-allowlist `JsonResource`, never a raw model.
- Shared controller helpers: `Api/Management/Concerns/ResolvesListParameters` (paging/sorting) and `HasLookupOptions` (dropdown options from the tiered reference tables).

### API conventions

Adopted from rwatch as-is:

- Envelope `{ data, message }`. Lists use Laravel's paginator shape (`data`, `meta`, `links`).
- `per_page` defaults to 20, clamped to 1..100. `sort` + `direction=asc|desc` work only for each endpoint's whitelisted sort keys; anything else falls back to the endpoint's default order.
- Status codes: `201` create, `204` delete, `401` unauthenticated, `403` forbidden (policy/permission 403s carry no `code` field; the one 403 that does is `code: "no_access"`, see [No-access refusal](#no-access-refusal)), `409` conflict (e.g. deleting an office, role or reference-data row still in use), `422` validation (`errors` keyed by field), `429` rate-limited.
- Form dropdowns come from a sibling `GET /api/<resource>/options` endpoint (registered before `/<resource>/{id}`), not embedded in the resource. Options are `{value, label}`; Tier 1 reference values are ids, Tier 2 values are strings, PSGC/multi-select values are ids (see [reference data](features/reference-data.md)).
- Exceptions always render as JSON (`shouldRenderJsonWhen(fn () => true)`). In production, any status outside 401/403/404/405/409/422/429 becomes a generic `"An unexpected error occurred."` so stack traces and SQL never leak.

### Frontend layout

`frontend/src/` mirrors `rwatch/frontend/src`:

- `App.tsx` — the whole route table, every page lazy-loaded. `/` redirects to `/dashboard`; `/equipment/:id/edit` is the QR alias.
- `layouts/ManagementLayout.tsx` — sidebar (`NAV_GROUPS`), each item gated by a permission. The two office-scoped items (Stakeholder Profile, Internet Connectivity) compute their link from the user: the cross-office list for `.view_all` holders, otherwise the user's own `/:officeId` page, otherwise hidden.
- `router/Guards.tsx` — `GuestOnly`, `RequireAuth`, `RequirePermission`.
- `lib/api.ts` — the axios instance (`withCredentials`, `withXSRFToken`, base `VITE_API_URL/api`); a 401 from any call drops the client back to login, and a `no_access` 403 does the same with a notice ([No-access refusal](#no-access-refusal)).
- `services/<feature>Service.ts` (API calls), `types/<feature>.ts`, `pages/management/<feature>/`, `components/<feature>/<Feature>Fields.tsx` (form body shared by create and edit), `components/ui/*` (rwatch's primitives), `components/forms/*` (shared form helpers).
- `lib/errors.ts` maps a 422 `errors` object onto form fields; lists use `DataGrid` + `hooks/useServerTable.ts`.

## Request flow

```
Browser (localhost:5173/...)
  -> React Router page -> services/*Service.ts -> lib/api.ts (axios, cookies + X-XSRF-TOKEN)
  -> backend /api/... : statefulApi (session + CSRF) -> SecurityHeaders
     -> auth:sanctum -> throttle:authenticated-api -> permission:<a|b> (fast fail)
     -> Controller: Form Request (validation, sometimes authorize) -> $this->authorize(Policy)
     -> Model (LogsActivity writes the audit row) -> JsonResource { data, message }
```

In development the SPA calls `http://localhost:8000` directly (cross-origin; CORS is restricted to `FRONTEND_URL` with `supports_credentials`). In production the frontend web server serves `dist/` and reverse-proxies `/api`, `/sanctum` and `/equipment/*/edit` to the backend, so the browser sees one origin. **Browse `localhost`, never `127.0.0.1`**: the session/XSRF cookies are scoped to `SESSION_DOMAIN=localhost` and are silently not sent otherwise, which looks like "every request is 401".

## Authentication flow

There is no self-registration, email verification, passkey, or self-service account deletion: accounts are created by a `users.manage` holder, and deactivated with `users.is_active` rather than deleted so audit attribution stays intact. (Self-registration was the PII-exposure path a 2026-07 security review flagged; removing it outright replaced the old `pending`-role mitigation.)

1. The SPA calls `GET /sanctum/csrf-cookie`, then `POST /api/login` with `email`, `password`, `recaptcha_token` (reCAPTCHA v3; Google's test secret is refused when `APP_ENV=production`). Wrong password and inactive account give the same `"Invalid credentials."` 422 and a `security` log entry.
2. Credentials are checked, then `is_active`, then whether the user holds any permission at all (none: the `no_access` 403 below, and nothing further happens). Then the session is **logged straight back out** — a password alone never yields a session. The response is one of:
   - `{requires_2fa_setup: true, setup_token}` — user has no confirmed 2FA (every new or reset account). Token lives in cache 30 min.
   - `{requires_2fa: true, two_fa_token, trust_device_days}` — token lives in cache 5 min.
   - the `UserResource` directly, if the browser presents a valid `dictis_trusted_device` cookie for that user.
3. Setup: `POST /api/2fa/setup` returns `{qr_code, secret}`; `POST /api/2fa/setup/confirm` with a TOTP `code` stores the secret, logs the user in and returns the user plus one-time `recovery_codes`.
4. Verify: `POST /api/2fa/verify` with `code` *or* `recovery_code` (single-use, hashed; a warning is returned when two or fewer remain), plus optional `remember_device`. Success calls `LoginSession::complete()`: `Auth::login`, session regenerate, `security` log, `UserResource` with `permissions`.
5. `GET /api/user` restores the session on reload; `POST /api/logout` invalidates it. `IdleLogout` signs the user out after `idle_timeout_seconds` (from `GET /api/session-settings`) of inactivity across all tabs.

Trusted devices, recovery codes, admin 2FA reset and the permission-based "always require the code" rule are described in [`features/two-factor-trusted-devices.md`](features/two-factor-trusted-devices.md). Password reset: `POST /api/forgot-password` emails a link to `FRONTEND_URL/reset-password?token&email`; `POST /api/reset-password` sets the password and deletes all of that user's sessions. Password policy everywhere: min 12, mixed case, numbers, symbols.

### No-access refusal

An account holding **zero permissions** (none direct, none through any role: no role yet, or only roles that grant none) cannot sign in. `User::hasAppAccess()` is the check; `LoginSession::refuseWithoutAccess()` builds the response:

```
403 {"code": "no_access", "message": "Your account doesn't have access to DICTIS yet. Please contact the Division ICT Unit."}
```

**Why:** PRIME is the division-wide directory, so any division employee can pass the password check, and a first-time PRIME login creates/syncs the local user record before anyone has given it a role. Without this check that user would be walked through 2FA enrollment into an empty app. The local record is still created/synced, so it shows up in Users for an admin to act on.

Where it is returned, and the ordering that matters:

- **`POST /api/login`** — only after a valid password and after the `is_active` check. A wrong password or an inactive account still gets the generic `"Invalid credentials."` 422, so the 403 never tells someone without the password anything about the account. It comes before any session, 2FA setup token or challenge, trusted-device bypass, or recovery codes: a no-access user never enrolls an authenticator or receives recovery codes.
- **`POST /api/2fa/setup`, `/api/2fa/setup/confirm`, `/api/2fa/verify`** — re-checked because roles can be removed between login and the 2FA step. These also discard the pending setup/2FA token (and any cached setup secret), so the user must start again at login.
- **Every authenticated route**, via `EnsureUserIsActive`, which ends the session. The inactive check still runs first there (deactivated: 401). This catches a signed-in user whose last role is removed.

**Audit:** every refusal writes a `security` log warning (`user_id`, `email`, `ip`, `stage`). An `auth.no_access` activity entry (properties `stage`, `ip`; `stage` is one of `login`, `two_factor_setup`, `two_factor_setup_confirm`, `two_factor_verify`, `session`) is written at most once per user and stage per hour, so retries or an open tab polling the API don't flood the permanent audit trail.

**Granting access:** an admin assigns a role in Users; the person then signs in again and enrolls in 2FA as usual.

**Frontend:** the message is shown as an info notice on `/login`. On the login and 2FA pages the 403 lands the user on `/login` with the notice; from anywhere else `lib/api.ts`/`AuthContext` hard-redirects to `/login?reason=no_access` (message carried in `sessionStorage`, with a built-in fallback text).

### Rate limiters and security settings

Named limiters (`AppServiceProvider::configureRateLimiting()`):

| Limiter | Applies to | Limit |
|---|---|---|
| `login` | `POST /api/login` | `login_rate_limit_per_minute` per email+IP, and 4x that (min 10) per IP |
| `password-reset` | forgot/reset password | `password_reset_rate_limit_per_hour` per email+IP, 4x (min 10) per IP |
| `two-factor-verify` | `/api/2fa/*` | `two_factor_verify_rate_limit_per_minute` per pending user (resolved from the setup/verify token), else per IP |
| `password-confirm` | `POST /api/profile`, recovery-code regeneration | 6/min per user |
| `authenticated-api` | every authenticated route | 600/min per user |

The `*_per_minute`/`*_per_hour` values, `trusted_device_days` and `idle_timeout_seconds` are rows in `system_settings`, read through `App\Services\SecuritySettings` (which holds the defaults and min/max bounds) and editable at `/settings` by `security_settings.manage` holders. rwatch's `export` limiter and its setting were dropped: inventory has no export feature (add it back with the feature, not before).

`App\Http\Middleware\SecurityHeaders` is appended globally: `nosniff`, `Referrer-Policy`, `X-Frame-Options: DENY`, a `default-src 'self'` CSP with `frame-ancestors 'none'`, `Permissions-Policy` denying geolocation/camera/microphone (no QR scanner exists, so `camera=()`), `Cache-Control: no-store, private` on `api/*`, and HSTS in production. Auth events (failed logins, 2FA failures/enrolment, device trust, admin 2FA resets, no-access refusals) go to the dedicated `security` log channel.

## Authorization model: granular permissions, never role names

**Hard rule:** application code checks a permission, never a role name — `$user->hasPermissionTo(Permission::EquipmentDelete)`, never `$user->hasRole('admin')`. This rule overrides rwatch where they differ: rwatch's `role:` route middleware and its role-keyed "2FA always required" config were deliberately not ported, and only Spatie's `permission` middleware alias is registered.

- **Source of truth:** `App\Enums\Permission` (backed enum, one case per permission; each case also has a `label()` and a `group()` used by the role editor). Inventory's existing names (`equipment.edit`, `equipment.transactions.create`, `stakeholder_profile.view_all`, ...) are the Spatie permission names unchanged. `permission.enable_wildcard_permission` **must stay `false`**, or Spatie would parse the dots as wildcard segments.
- **Roles** are admin-editable Spatie roles (`App\Models\Role extends Spatie\Permission\Models\Role`, plus inventory's `label`/`description`). A user may hold several roles. `RolePermissionSeeder` seeds `pending` (zero permissions, protected), `viewer`, `encoder`, `admin` (every permission, including `security_settings.manage`) as data, never as code branches.
- **Two layers, as in rwatch:**
  1. Route-level `permission:a|b` middleware in `routes/api/management.php` is a cheap fast fail (any listed permission passes).
  2. **Policies are the authoritative gate**, re-checked in every controller action (or Form Request). This matters most for Stakeholder Profile and Internet Connectivity Survey, where the same permission means "your own office" or "any office" depending on `office_id` — only the Policy knows that. Index-only admin screens without a per-record ability (audit log, reference-data overview) authorize the permission string directly.
- `TrustedDeviceService::days()` returns 0 (code always required) for anyone holding `roles.manage` or `users.manage` — the permission-based replacement for rwatch's role list.
- The RBAC guards in `RoleService`/`UserRoleService` (no self-escalation, permission-tier separation, last-holder lockout under a row lock on Spatie's `permissions` row, protected `pending`) survived the move onto Spatie's tables unchanged. Detail: [`features/roles-and-permissions.md`](features/roles-and-permissions.md).
- Spatie caches permissions. After editing grants directly in the database run `php artisan permission:cache-reset`; tests and seeders call `PermissionRegistrar::forgetCachedPermissions()`.

### Frontend permission gating

`GET /api/user` (and every login response) returns `UserResource.permissions` — the flat, de-duplicated permission list across the user's roles. `AuthContext.can()` reads it to hide buttons, sidebar items and routes (`RequirePermission`). This is UX only; the server re-checks every request, so a hand-crafted request without the permission still gets a 403.

## Audit logging

`spatie/laravel-activitylog` replaced the custom `audit_logs` table. Existing `audit_logs` rows were copied into `activity_log` by migration `2026_10_19_000004` (tagged `properties.migrated_from_audit_log_id`) and the old table is dropped once that copy has been verified (`2026_10_19_000012`).

- **Model CRUD** comes from the `LogsActivity` trait on Personnel, Equipment, IspAccount, Office, StakeholderProfile and InternetConnectivitySurvey (fillable attributes, dirty-only, empty changes skipped), User (`name`, `email`, `avatar_path`, `office_id`, `is_active` only — never passwords or 2FA columns), and the 13 reference-data models (`App\Concerns\LogsReferenceDataActivity`, which also records the registry `table` key). `event` is `created`/`updated`/`deleted`; `description` is `<resource>.<event>` (e.g. `equipment.updated`). Equipment excludes its `current_*` pointers, which are audited as transactions instead. The two one-row-per-office models log `updated` only.
- **Non-CRUD events** use `activity()->event('<dotted.name>')`: `equipment.transaction.recorded`, `isp_account.speed_test.recorded`, `isp_account.subscription_cost.recorded`, `role.created|updated|deleted`, `user.roles_changed`, `user.two_factor_reset`, `authorization.denied`, `auth.no_access` (deduplicated per user and stage per hour, see [No-access refusal](#no-access-refusal)).
- **Denials:** `authorization.denied` is logged by the Policies for the most consequential actions (delete on Personnel/Equipment/ISP Accounts/Offices, Equipment `createTransaction`, every `RolePolicy` ability), and by `bootstrap/app.php` when the route-level `permission:` fast fail rejects a *write*, since that request never reaches the Policy. Route-level read denials are not logged.
- **Attribution survives account deletion.** `activity_log.causer_id` has no FK, and an `Activity::creating` hook in `AppServiceProvider` (the single place this is written) stamps `properties.actor_snapshot` = `{name, email}` on every row with a user causer.
- **Retention:** `activitylog:clean` is deliberately **not** scheduled and `clean_after_days` is `null`. Inventory's audit trail has always been permanent; pruning (rwatch's default) would quietly reverse that.

Admin UI: [`features/audit-log.md`](features/audit-log.md).

## Timezone: Asia/Manila

`config/app.timezone` is `Asia/Manila` (it was UTC until 2026-10-19), so every timestamp the system writes is Manila wall-clock time and `created_at` values in the UI match the office's clock. Two consequences worth knowing:

- Migration `2026_10_19_000011_shift_system_timestamps_from_utc_to_manila` moved every pre-existing system-written timestamp forward 8 hours (Manila has no DST, so a fixed offset is exact). Its column list is hard-coded from the schema as of that date, so a later column is never shifted by accident. It deliberately leaves alone user-entered wall-clock values (`isp_speed_tests.tested_at`, `stakeholder_profiles.submitted_at`) and all `DATE` columns.
- The SPA sends datetimes as UTC ISO-8601 (`...Z`). Form Requests for user-entered datetimes use `Concerns\NormalizesDateTimesToAppTimezone` to convert them to Manila before Eloquent stores them; without it the `Z` offset would be dropped and the value stored 8 hours off. Use it for any new user-entered datetime field.

## Equipment QR codes stay stable

Printed QR labels encode `equipment.qr_code` (`APP_URL/equipment/{id}/edit`, set by `EquipmentObserver`) and are long-lived physical objects, so that URL must keep working forever. The backend's single web route `GET /equipment/{equipment}/edit` 302-redirects to `FRONTEND_URL/equipment/{id}/edit` (only ever to the configured frontend origin, so it is not an open redirect), and the SPA has an alias route for deployments where the frontend host receives the scan. Stored `qr_code` values are never rewritten.

## Three data-access patterns

Each feature uses one of three shapes for how its data is created, changed and read back. Recognizing which one is the fastest way to understand its controller, model and migration design.

### 1. Accountability-transfer (Equipment)

A physical asset needs both a **current state** (who holds it now, for fast listing) and a **full history** of every change. Two tables cooperate:

- `equipment` holds `current_accountable_officer_id` / `current_end_user_id`.
- `equipment_transactions` is an append-only log, one row per accountability event, never updated or deleted.

They must never drift apart. `Equipment` has an `updating` model hook that throws `AccountabilitySyncRequiredException` if either `current_*` column is dirty outside a sanctioned path. The only sanctioned path is `EquipmentAccountabilityService::recordTransaction()`, which writes the transaction row and updates the pointers atomically inside one DB transaction (`Equipment::withAccountabilitySync()`). A controller cannot move the pointers directly, even by mistake.

Sync policy is deliberately simple: the current holder is whoever the latest transaction names, including `null` when it names no one. Recording a transaction needs its own permission (`equipment.transactions.create`, not `equipment.edit`) because reassigning accountability for an asset is more sensitive than editing its description.

### 2. Append-only child log (ISP Accounts)

A parent record collects a growing history, but has no "current state" pointer to keep in sync. `IspAccount` has two independent logs, `isp_speed_tests` and `isp_subscription_costs`. Both are store-only (no update/delete endpoint) and authorize against the **parent's** `IspAccountPolicy::update()` — logging a reading is part of editing the account, unlike Equipment's accountability transfer. No guard hook or Service is needed; the controller creates the child row directly.

### 3. One row per office (Stakeholder Profile, Internet Connectivity Survey)

Records that describe one office (a school or division unit): each `Office` gets at most one row, filled in by that office's own users. Both started as global singletons and were converted to one row per office in 2026-07 (Stakeholder Profile on the 14th, the survey on the 15th).

- No create or delete endpoint. `GET`/`PUT /api/<resource>/{office}` lazily `firstOrCreate(['office_id' => ...])` the row; an admin-only index lists every office with whether it has been answered.
- A unique index on `office_id` (`NOT NULL`) is the whole "at most one per office" guarantee, replacing the old `singleton_guard` race guard.
- **Scoped access:** the Policy grants `view`/`update` for the user's own `office_id` (with the resource's `.view`/`.edit`) or for any office to a `.view_all` holder (seeded to `admin` only).
- **Authorize before creating.** The controller authorizes a transient, unsaved model with `office_id` already set *before* `firstOrCreate`, so a wrong-office (or zero-permission) request is 403'd without creating that office's row as a side effect.
- Because `firstOrCreate` persists an empty row the first time anyone opens the page, row existence does not mean "answered"; the index uses each model's `hasAnswers()` (any non-default field filled in) for `has_profile`/`has_survey`.

The survey's old division-wide "aggregates" panel (ISP totals) was removed on 2026-07-15 — `isp_accounts` has no `office_id`, so every office showed the same totals. See [`features/internet-connectivity-survey.md`](features/internet-connectivity-survey.md).

## Reference data: tiered tables, not one generic `lookups` table

Every dropdown is backed by one of 13 reference tables in two tiers (full ADR: [`lookup-normalization.md`](architecture-decisions/lookup-normalization.md); admin screen: [`features/reference-data.md`](features/reference-data.md)). The SPA conversion did not change any of this.

- **Tier 1 — 9 dedicated tables with real FKs** (`item_types`, `brands`, `equipment_categories`, `equipment_classifications`, `equipment_conditions`, `positions`, `ro_offices`, `sdo_offices`, `isp_providers`). Consuming columns are `_id` foreign keys; options ship `{value: id, label: name}`.
- **Tier 2 — 4 domain-grouped library tables** (`equipment_libraries`, `personnel_libraries`, `stakeholder_libraries`, `connectivity_libraries`), discriminated by `type`. Consuming columns stay validated strings (`Rule::in()`); options ship `{value: string, label: string}`. JSON-array multi-select columns store library (or `isp_providers`) row **ids**, validated per element, with no pivot tables.

Two related lookups are deliberately separate shapes:

- **PSGC locations** (`psgc_provinces` → `psgc_municipalities` → `psgc_barangays`, Region III only, real FKs, fixed government `code`, not admin-editable). The whole tree (~3,100 barangays) ships once in `/api/stakeholder-profiles/options` with `parent_id`s and is filtered client-side into cascading selects.
- **`Office`** is a full CRUD resource, not a 14th reference table: its shape fits neither tier and it is the FK backbone `users`, `stakeholder_profiles` and `internet_connectivity_surveys` point at (`restrictOnDelete`). See [`features/offices.md`](features/offices.md).
