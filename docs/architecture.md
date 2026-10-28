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
- Status codes: `201` create, `204` delete, `401` unauthenticated, `403` forbidden (policy/permission 403s carry no `code` field; the one 403 that does is `code: "no_access"`, see [No-access refusal](#no-access-refusal)), `404` not found (also returned for a record outside the user's data scope), `409` conflict (e.g. deleting an office, role or reference-data row still in use), `422` validation (`errors` keyed by field), `429` rate-limited.
- Form dropdowns come from a sibling `GET /api/<resource>/options` endpoint (registered before `/<resource>/{id}`), not embedded in the resource. Options are `{value, label}`; Tier 1 reference values are ids, Tier 2 values are strings, PSGC/multi-select values are ids (see [reference data](features/reference-data.md)).
- Exceptions always render as JSON (`shouldRenderJsonWhen(fn () => true)`). In production, any status outside 401/403/404/405/409/422/429 becomes a generic `"An unexpected error occurred."` so stack traces and SQL never leak.

### Frontend layout

`frontend/src/` mirrors `rwatch/frontend/src`:

- `App.tsx` — the whole route table, every page lazy-loaded. `/` redirects to `/dashboard`; `/equipment/:id/edit` is the QR alias. `GoogleReCaptchaProvider` wraps only the `/login` route, so Google's reCAPTCHA script loads on the login page and nowhere else.
- `layouts/ManagementLayout.tsx` — sidebar (`NAV_GROUPS`), each item gated by a permission. The scoped items take their title and link from the user's `data_scope` (`lib/dataScope.ts`, which page titles use too):

  | Item | `own` | `school` | `all` |
  |---|---|---|---|
  | Personnel | "My Record" → the linked record's edit page (hidden if not linked) | "Office Personnel" | "Personnel" |
  | Equipment | "My Equipment" | "Office Equipment" | "Equipment" |
  | Stakeholder Profile | "Our Office Profile" → own `/:officeId` (hidden with no office) | same as `own` | "Stakeholder Profiles" → cross-office list |
  | Internet Connectivity | "Our Office Connectivity" → own `/:officeId` (hidden with no office) | same as `own` | "Internet Connectivity" → cross-office list |
- `router/Guards.tsx` — `GuestOnly`, `RequireAuth`, `RequirePermission`, `RequireDataScope` (UX only: redirects to `/dashboard` from routes outside the user's scope, i.e. the two cross-office lists for non-`all` users and `personnel/new` for `own`).
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

In development the SPA calls `http://localhost:8000` directly (cross-origin; CORS is restricted to `FRONTEND_URL` with `supports_credentials`). In production (Plesk: nginx in front of Apache) `deploy/plesk.sh` copies `dist/` into `backend/public` and moves `index.html` to `backend/storage/app/spa/index.html` (`config('app.spa_index')`). Apache serves real files (`/assets/*`, images) directly and sends everything else to Laravel. There, `SpaController` answers every non-`api`/`sanctum`/`up` GET with that `index.html`, so the browser sees one origin (README, "Production runs on Plesk"). **Browse `localhost`, never `127.0.0.1`**: the session/XSRF cookies are scoped to `SESSION_DOMAIN=localhost` and are silently not sent otherwise, which looks like "every request is 401".

## Authentication flow

There is no self-registration, email verification, passkey, or self-service account deletion: accounts are created/synced from PRIME on first sign-in (`POST`/`DELETE /api/users` always answer 403), given access by a `users.edit` holder, and deactivated with `users.is_active` rather than deleted so audit attribution stays intact. (Self-registration was the PII-exposure path a 2026-07 security review flagged; removing it outright replaced the old `pending`-role mitigation.)

1. The SPA calls `GET /sanctum/csrf-cookie`, then `POST /api/login` with `email`, `password`, `recaptcha_token` (reCAPTCHA v3; Google's test secret is refused when `APP_ENV=production`). An email with non-ASCII characters is rejected with a 422 before PRIME is queried. The password is checked against PRIME (see [Password authority](#password-authority-prime)). Unknown email, wrong password, inactive account and a refused break-glass attempt all give the same `"Invalid credentials."` 422 and a `security` log entry, and the credential check is timeboxed to at least 400 ms (`Illuminate\Support\Timebox`) so these cases can't be told apart by response time.
2. Credentials are checked, then `is_active`, then whether the user holds any permission at all (none: the `no_access` 403 below, and nothing further happens). Then the session is **logged straight back out** — a password alone never yields a session. The response is one of:
   - `{requires_2fa_setup: true, setup_token}` — user has no confirmed 2FA (every new or reset account). Token lives in cache 30 min.
   - `{requires_2fa: true, two_fa_token, trust_device_days}` — token lives in cache 5 min.
   - the `UserResource` directly, if the browser presents a valid `dictis_trusted_device` cookie for that user.
3. Setup: `POST /api/2fa/setup` returns `{qr_code, secret}`; `POST /api/2fa/setup/confirm` with a TOTP `code` stores the secret, logs the user in and returns the user plus one-time `recovery_codes`.
4. Verify: `POST /api/2fa/verify` with `code` *or* `recovery_code` (single-use, hashed; a warning is returned when two or fewer remain), plus optional `remember_device`. Success calls `LoginSession::complete()`: `Auth::login`, session regenerate, `security` log, `UserResource` with `permissions`.
5. `GET /api/user` restores the session on reload and refreshes the account from PRIME (`PrimeUserSync::syncUser()`: name, deactivation, employee number, office); `POST /api/logout` invalidates it. The SPA's boot probe (`AuthContext`) treats a 429 or 5xx as temporary, not as signed out: it retries up to 2 times, waiting for `Retry-After` (clamped to 1-10 s, default 3 s), and `RequireAuth` shows a "server is busy" notice with a "Try again" button instead of redirecting to login. Only a 401 signs the user out, so a rate-limit spike can't log everyone off. Auth pages (login, 2FA setup/verify) show the shared `TOO_MANY_ATTEMPTS_MESSAGE` from `lib/errors.ts` on a 429. `IdleLogout` signs the user out after `idle_timeout_seconds` (from `GET /api/session-settings`) of inactivity across all tabs.

Trusted devices, recovery codes, admin 2FA reset and the permission-based "always require the code" rule are described in [`features/two-factor-trusted-devices.md`](features/two-factor-trusted-devices.md). There is no forgot/reset password in IRIS: people change their password in PRIME.

### Password authority: PRIME

PRIME (the `external_users` connection, its `users` table) is the only password authority. IRIS never copies PRIME's password hash: `users.password` is nullable (`2026_10_27_000003`) and is empty for every account except developer break-glass accounts. Before 2026-10-27 login and the full sync copied PRIME's hash into `users.password` and login fell back to it whenever PRIME was down, so anyone whose PRIME password had been changed or revoked could still sign in with the old one during an outage. `2026_10_27_000004` cleared those copies.

`App\Services\ExternalUserAuthService` does the check:

- **PRIME knows the email, active, password matches:** signed in. A first login creates the local user (name and email from PRIME); later logins update the name.
- **PRIME refuses** (wrong password, status not `Active`, or more than one PRIME row with that email): `InvalidPrimeCredentials`, and login **never** falls back to a local password. Duplicate-email rows (compared case-insensitively) are refused because there is no safe way to pick whose password applies; they are logged as `Ambiguous PRIME account` on the `security` channel. Every sync path (`users:sync-external`, the Users list import and refresh, `GET /api/user`) skips such emails and logs them the same way, so a duplicate never creates or updates an IRIS account either.
- **PRIME unreachable or PRIME doesn't know the email:** the local password is checked, but only for an email listed in `LOCAL_LOGIN_EMAILS` (config `auth.local_login_emails`, comma-separated, case-insensitive). Everyone else gets the generic 422. "Unreachable" means a **connection-level** failure only (`ExternalUserAuthService::isUnreachable()`): a lost or failed connection (SQLSTATE class `08`, MySQL errors 2002/2003/2006/2013) or the breaker's own `PrimeUnreachable` (below).
- **Any other PRIME error** (a collation mismatch, bad data, a missing column) is a refusal, not an outage: no break-glass fallback, the breaker isn't opened, and it is logged on the `security` channel as `PRIME authentication failed unexpectedly; refusing`. The sync paths treat it like an outage for display purposes and show the local copy. The reasoning: such an error can be provoked by input or by a PRIME schema change, and must not become a way to reach the local password.

**Break-glass accounts** exist so a developer can still get in when PRIME is down, or on a dev machine with no PRIME at all. Every use writes `Local break-glass login` (`email`, `ip`, `reason`: `prime_unreachable` or `not_in_prime`) to the `security` channel, and the 2FA steps below apply unchanged. Production keeps `LOCAL_LOGIN_EMAILS` empty, so production has no local accounts. A break-glass password is set with `php artisan users:set-password <email>`, which refuses any email not on the list. Profile, user editing and user creation never write a password.

**Circuit breaker.** Every PRIME read goes through `ExternalUserAuthService::guard()`. A connection-level PRIME failure (as defined above; not other query errors) sets the cache flag `prime.unreachable` for 30 s. While it is set, login, `CurrentPassword`, `GET /api/user` (`syncUser()`) and the Users list (`importNew()`/`syncVisible()`) don't contact PRIME at all: they throw `PrimeUnreachable` internally and behave exactly as "PRIME unreachable" (the local copy is shown; only allowlisted break-glass accounts can sign in or re-confirm). Without it, every request during an outage would wait out the connect timeout on its own. `users:sync-external` ignores the flag and always tries PRIME, so an operator gets a real error, and sets the flag if it fails to connect. The `external_users` connection also has `PDO::ATTR_TIMEOUT => 5`, which bounds only the **connect**; a query that stalls after connecting isn't cut short by it, so the breaker is what keeps an outage from slowing every request.

**Other sessions.** A password change made in PRIME doesn't sign out the person's other IRIS sessions or revoke their trusted devices, because IRIS never sees it (Sanctum's `AuthenticateSession` and `User::booted()` both react to the local `users.password`). To cut someone off, deactivate them (in PRIME, picked up on the next sync, or in `/users`) or reset their 2FA.

**Re-confirmation uses the same rule.** `App\Rules\CurrentPassword` replaces Laravel's `current_password` rule on every endpoint that asks for the password again (recovery-code regeneration, clearing a system log, resetting another user's 2FA, changing a security setting): PRIME first, the local password only for an allowlisted account when PRIME is unreachable or doesn't know the email, and a PRIME refusal is final. When PRIME is down and the user isn't allowlisted the 422 says "Your password can't be checked right now because PRIME is unavailable. Please try again later." The SPA labels these fields "Your PRIME password".

**Profile is avatar-only.** `POST /api/profile` accepts `avatar` and `remove_avatar`. Name and email come from PRIME and are shown read-only; a `password` field is rejected with 422 "Passwords are managed in PRIME." (rather than ignored, so an old client can't appear to have changed it).

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
| `two-factor-verify` | `/api/2fa/*` | `two_factor_verify_rate_limit_per_minute` per pending user (resolved from the setup/verify token), else per IP |
| `profile-update` | `POST /api/profile` | 10/min per user (per IP if there is no user) |
| `password-confirm` | recovery-code regeneration, `POST /api/users/{id}/2fa/reset`, `PUT /api/settings/security`, `DELETE /api/settings/system-logs/errors`, `DELETE /api/settings/system-logs/slow-queries` | 6/min per user, one bucket shared by all of these |
| `authenticated-api` | every authenticated route | 600/min per user |

The `*_per_minute`/`*_per_hour` values, `trusted_device_days` and `idle_timeout_seconds` are rows in `system_settings`, read through `App\Services\SecuritySettings` (which holds the defaults and min/max bounds) and editable at `/settings` by `security_settings.manage` holders.

**Admin actions that weaken controls or destroy evidence need the password again.** Changing a security setting (`UpdateSecuritySettingRequest`), clearing the error or slow-query log (`ClearSystemLogRequest`, both `security_settings.manage`) and resetting another user's 2FA (`ResetTwoFactorRequest`, `users.reset_two_factor`) require `current_password`, checked against PRIME by `App\Rules\CurrentPassword` (see [Password authority](#password-authority-prime)). Each request authorizes its own permission, so a user without it gets a 403 before the password is checked. A missing or wrong password is a 422 under `errors.current_password`, and nothing changes and nothing is audited; the shared `Concerns\ConfirmsCurrentPassword` trait writes `Password re-confirmation failed` (`user_id`, `ip`) to the `security` channel, so repeated guessing on a hijacked session is visible. This means a hijacked or unattended session can't quietly loosen rate limits or extend device trust, or wipe the logs that would show it happened. The `password-confirm` throttle (above) caps password guessing through these endpoints; on the log clears it replaced the older `throttle:10,1`, and the 2FA reset got it on 2026-10-27 (it had no limiter before). A security setting change that actually changes the value writes a `security` log entry (`user_id`, `ip`, `key`, `old`, `new`) as well as the `security_setting.updated` activity row.

rwatch's `export` limiter and its setting were dropped: inventory has no export feature (add it back with the feature, not before).

In production, Cloudflare rate-limiting rules limit each client IP before requests reach the origin, as a backstop for traffic these limiters never see (unauthenticated 401s, `permission:` denials, `/sanctum/csrf-cookie`). See the README's "Web server rate limiting" section. Plesk's nginx doesn't rate-limit per IP: behind Cloudflare its `$remote_addr` is a Cloudflare edge address, which is why `deploy/nginx/` was removed.

`App\Http\Middleware\SecurityHeaders` is appended globally: `nosniff`, `Referrer-Policy`, `X-Frame-Options: DENY`, a `default-src 'self'` CSP with `frame-ancestors 'none'`, `Permissions-Policy` denying geolocation/camera/microphone (no QR scanner exists, so `camera=()`), `Cache-Control: no-store, private` on `api/*`, and HSTS in production.

The SPA document (`App\Http\Controllers\SpaController`, the web routes `/`, `equipment.edit` and the fallback) sets its own CSP, which `SecurityHeaders` leaves alone. Its `script-src` adds `'nonce-<base64 of random_bytes(16)>'`, freshly generated for every response, to `'self'`, reCAPTCHA and `static.cloudflareinsights.com`. Its other directives are `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, `frame-ancestors 'none'`, Google Fonts, OpenStreetMap tiles, reCAPTCHA frames and `upgrade-insecure-requests`. The nonce exists only for Cloudflare's Bot "JavaScript detections" inline script: Cloudflare reads the nonce from the CSP header and stamps it on the script it injects ([Cloudflare docs](https://developers.cloudflare.com/bots/additional-configurations/javascript-detections/)). The nonce appears only in that header, never in the HTML, a cookie, a log or an API response, and our own scripts load through `'self'`. There is no `'unsafe-inline'`, `'unsafe-eval'` or `'strict-dynamic'`. The body is the built `index.html` byte for byte, with no request data in it. It is sent with `Cache-Control: no-store` and without `ETag` or `Last-Modified`, so no cached copy or 304 replays an old nonce. The route skips the `web` middleware group, so it starts no session and sets no cookies. Without Cloudflare (local), the nonce simply goes unused. API responses keep the stricter `SecurityHeaders` CSP. `tests/Feature/Security/SpaDocumentTest.php` covers all of this. Auth events (failed logins, break-glass logins, ambiguous PRIME accounts, 2FA failures/enrolment, device trust, admin 2FA resets, no-access refusals, security setting changes, failed password re-confirmations) go to the dedicated `security` log channel.

### Trusted proxy headers

`TRUSTED_PROXIES` (`config('app.trusted_proxies')`) lists the proxies whose `X-Forwarded-*` headers Laravel believes; empty means none are trusted and every forwarded header is ignored. `TRUSTED_PROXY_HEADERS` (`config('app.trusted_proxy_headers')`, default `for`) is a comma list of `for`, `host`, `port`, `proto`, `prefix` choosing which of those headers are honoured once a proxy is trusted. Both are applied in `AppServiceProvider::configureTrustedProxies()` rather than `bootstrap/app.php`'s `trustProxies()`, because that callback runs before config and `.env` are loaded.

- **Why only `X-Forwarded-For` by default:** Laravel's default trusts every `X-Forwarded-*` header from a trusted proxy. A proxy that passes a client's `X-Forwarded-Host`/`Proto` through unchanged would then let the client choose the host and scheme of generated URLs, which is how a forged host could end up in a printed QR label (see [Equipment QR codes stay stable](#equipment-qr-codes-stay-stable)). Add a header only if the proxy always overwrites it.
- Unknown names are dropped, and an empty or all-unknown list falls back to `for`, never to "all headers" (a zero bitmask would make `TrustProxies` trust everything).
- **Production is behind Cloudflare.** `TRUSTED_PROXIES` is set to Cloudflare's published IPv4 and IPv6 ranges so Laravel sees the real client IP instead of Cloudflare's edge addresses. This keeps login lockouts, rate limiting and audit logs per-user instead of per-Cloudflare-server. See `backend/.env.production.example` for the ranges and the README's required client-IP check.
- Tests: `backend/tests/Feature/Security/TrustedProxyHeadersTest.php`.

## Authorization model: granular permissions, never role names

**Hard rule:** application code checks a permission, never a role name — `$user->hasPermissionTo(Permission::EquipmentDelete)`, never `$user->hasRole('admin')`. This rule overrides rwatch where they differ: rwatch's `role:` route middleware and its role-keyed "2FA always required" config were deliberately not ported, and only Spatie's `permission` middleware alias is registered.

- **Source of truth:** `App\Enums\Permission` (backed enum, one case per permission; each case also has a `label()` and a `group()` used by the role editor). Inventory's existing names (`equipment.edit`, `equipment.transactions.create`, `stakeholder_profile.view`, ...) are the Spatie permission names unchanged. `permission.enable_wildcard_permission` **must stay `false`**, or Spatie would parse the dots as wildcard segments.
- **Roles** are admin-editable Spatie roles (`App\Models\Role extends Spatie\Permission\Models\Role`, plus inventory's `label`/`description`/`data_scope`). Each user holds one role: migration `2026_10_20_000002` reduced every user to a single role, and `PUT /api/users/{id}` takes one `role_id`. `RolePermissionSeeder` seeds `super_administrator` (every permission, protected), `system_administrator` (everything except `roles.create`/`edit`/`delete`), `encoder` ("Division Encoder"), `office_encoder` and `viewer` as data, never as code branches.
- **Data scope** decides which records a role's permissions reach; permissions decide which actions are allowed, and scope never grants one. `roles.data_scope` (`own`/`school`/`all`; `school` is labelled "Own office") applies to Personnel, Equipment, ISP Accounts, Stakeholder Profile and the Internet Connectivity Survey. Lists apply each model's `visibleTo($user)` query scope; single records go through the Policy (below). Decision: [`architecture-decisions/unified-data-scope.md`](architecture-decisions/unified-data-scope.md); reach per resource and edge cases: [`features/roles-and-permissions.md`](features/roles-and-permissions.md#data-scope).
- **Two layers, as in rwatch:**
  1. Route-level `permission:a|b` middleware in `routes/api/management.php` is a cheap fast fail (any listed permission passes).
  2. **Policies are the authoritative gate**, re-checked in every controller action (or Form Request). Only the Policy knows the data scope: holding the permission but asking for an out-of-reach record gets `Response::denyAsNotFound()` (**404**, so a scoped user can't tell whether an id exists; outside `local`/`testing` the exception handler gives every 404 the same generic body); lacking the permission gets **403**. Form Requests authorize with `Gate::inspect()` so the 404 isn't flattened to a 403. Index-only admin screens without a per-record ability (audit log, reference-data overview) authorize the permission string directly.
- `TrustedDeviceService::days()` returns 0 (code always required) for anyone holding a permission in `TrustedDeviceService::ALWAYS_REQUIRE_CODE` — the permission-based replacement for rwatch's role list. The list covers permissions that change anyone's access (`roles.create`/`edit`/`delete`, `users.edit`, `users.reset_two_factor`), weaken sign-in controls (`security_settings.manage`), or expose or erase the record of what happened (`audit_log.view`, `email_log.manage`). Detail: [`features/two-factor-trusted-devices.md`](features/two-factor-trusted-devices.md#design-decisions).
- The RBAC guards in `RoleService`/`UserRoleService` (no self-escalation, permission-tier separation, last-holder lockout under a row lock on Spatie's `permissions` row, protected `super_administrator`) survived the move onto Spatie's tables unchanged. Detail: [`features/roles-and-permissions.md`](features/roles-and-permissions.md).
- Spatie caches permissions. After editing grants directly in the database run `php artisan permission:cache-reset`; tests and seeders call `PermissionRegistrar::forgetCachedPermissions()`.

### Frontend permission gating

`GET /api/user` (and every login response) returns `UserResource.permissions` — the flat, de-duplicated permission list across the user's roles. `AuthContext.can()` reads it to hide buttons, sidebar items and routes (`RequirePermission`). It also carries `data_scope` and `personnel_id`/`personnel` (the user's linked Personnel record), which drive the scope-aware titles and links, `RequireDataScope`, and the dashboard notices for an `own` user with no linked record or a `school` user with no office. All of this is UX only; the server re-checks every request, so a hand-crafted request without the permission still gets a 403 (or a 404 for an out-of-reach record).

## Audit logging

`spatie/laravel-activitylog` replaced the custom `audit_logs` table. Existing `audit_logs` rows were copied into `activity_log` by migration `2026_10_19_000004` (tagged `properties.migrated_from_audit_log_id`) and the old table is dropped once that copy has been verified (`2026_10_19_000012`).

- **Model CRUD** comes from the `LogsActivity` trait on Personnel, Equipment, IspAccount, Office, StakeholderProfile and InternetConnectivitySurvey (fillable attributes, dirty-only, empty changes skipped), User (`name`, `email`, `avatar_path`, `office_id`, `is_active` only — never passwords or 2FA columns), and the 13 reference-data models (`App\Concerns\LogsReferenceDataActivity`, which also records the registry `table` key). `event` is `created`/`updated`/`deleted`; `description` is `<resource>.<event>` (e.g. `equipment.updated`). Equipment excludes its `current_*` pointers, which are audited as transactions instead. The two one-row-per-office models log `updated` only.
- **Non-CRUD events** use `activity()->event('<dotted.name>')`: `equipment.transaction.recorded`, `isp_account.speed_test.recorded`, `isp_account.subscription_cost.recorded`, `role.created|updated|deleted`, `user.roles_changed`, `user.two_factor_reset`, `authorization.denied`, `auth.no_access` (deduplicated per user and stage per hour, see [No-access refusal](#no-access-refusal)).
- **Denials:** `authorization.denied` is logged by the Policies for the most consequential actions (delete on Personnel/Equipment/ISP Accounts/Offices, Equipment `createTransaction`, every `RolePolicy` ability), and by `bootstrap/app.php` when the route-level `permission:` fast fail rejects a *write*, since that request never reaches the Policy. Route-level read denials are not logged. The route-level row stores `permission`, `route` and `parameters` (the raw route parameters, kept even when the record doesn't exist, so a probe for a non-existent id is still traceable). The `permission:` check runs before `SubstituteBindings`, so the exception handler performs route binding itself to set the audit subject when the record exists.
- **Middleware order:** `EnsureUserIsActive` → `EnforceIdleTimeout` → `permission:` all run before any `throttle:*` (`prependToPriorityList` in `bootstrap/app.php`). Denied, inactive and idle-expired requests therefore don't spend the user's `password-confirm` or `authenticated-api` budget, so a refused request never uses up the allowance for actions the user is actually permitted to take, and a dead session is ended before any permission check. The trade-off is that denied requests are not rate limited at all; the only per-user cap is the 10/min limiter on the denial audit rows themselves. Security assessed this as acceptable, with infrastructure rate limiting as the backstop.
- **Attribution survives account deletion.** `activity_log.causer_id` has no FK, and an `Activity::creating` hook in `AppServiceProvider` (the single place this is written) stamps `properties.actor_snapshot` = `{name, email}` on every row with a user causer.
- **Retention:** `activitylog:clean` is deliberately **not** scheduled and `clean_after_days` is `null`. Inventory's audit trail has always been permanent; pruning (rwatch's default) would quietly reverse that.

Admin UI: [`features/audit-log.md`](features/audit-log.md).

## Timezone: Asia/Manila

`config/app.timezone` is `Asia/Manila` (it was UTC until 2026-10-19), so every timestamp the system writes is Manila wall-clock time and `created_at` values in the UI match the office's clock. Two consequences worth knowing:

- Migration `2026_10_19_000011_shift_system_timestamps_from_utc_to_manila` moved every pre-existing system-written timestamp forward 8 hours (Manila has no DST, so a fixed offset is exact). Its column list is hard-coded from the schema as of that date, so a later column is never shifted by accident. It deliberately leaves alone user-entered wall-clock values (`isp_speed_tests.tested_at`, `stakeholder_profiles.submitted_at`) and all `DATE` columns.
- The SPA sends datetimes as UTC ISO-8601 (`...Z`). Form Requests for user-entered datetimes use `Concerns\NormalizesDateTimesToAppTimezone` to convert them to Manila before Eloquent stores them; without it the `Z` offset would be dropped and the value stored 8 hours off. Use it for any new user-entered datetime field.

## Equipment QR codes stay stable

Printed QR labels encode `equipment.qr_code` (`APP_URL/equipment/{id}/edit`, set by `EquipmentObserver` via `Equipment::qrCodeUrl()`, which always uses `APP_URL` and never the request host) and are long-lived physical objects, so that URL must keep working forever. The web route `GET /equipment/{id}/edit` (`equipment.edit`, `backend/routes/web.php`, `SpaController::equipmentLabel`) serves the SPA document when a build is present at `config('app.spa_index')` (Plesk, single-origin), and the SPA route `equipment/:id/edit` handles it. Without a build (local dev), it 302-redirects to `FRONTEND_URL/equipment/{id}/edit`. That target is only ever the configured frontend origin, so it is not an open redirect. The SPA has an alias route for deployments where the frontend host receives the scan. That is also why `deploy/plesk.sh` fails if `index.html` is missing: same-origin, the redirect would point back at itself and loop. Stored `qr_code` values are only rewritten by the audited `equipment:regenerate-qr-codes` command, run by hand after review ([`features/equipment.md`](features/equipment.md#non-obvious-design-decisions)).

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
- **Scoped access:** the resource's `.view`/`.edit` plus the role's data scope (`User::reachesOffice()`). `own` and `school` reach only the user's own `office_id`; `all` reaches every office and is the only scope allowed the cross-office list (`viewAny`). The former `.view_all` permissions were removed by migration `2026_10_26_000005`, which gave their holders `.view` + `.edit`.
- **Authorize before creating.** The controller authorizes a transient, unsaved model with `office_id` already set *before* `firstOrCreate`, so a wrong-office (404) or zero-permission (403) request is refused without creating that office's row as a side effect.
- Because `firstOrCreate` persists an empty row the first time anyone opens the page, row existence does not mean "answered"; the index uses each model's `hasAnswers()` (any non-default field filled in) for `has_profile`/`has_survey`.

The survey's old division-wide "aggregates" panel (ISP totals) was removed on 2026-07-15 because `isp_accounts` had no `office_id`, so every office showed the same totals. `isp_accounts.office_id` was added on 2026-10-26 for data scope; the panel hasn't been brought back. See [`features/internet-connectivity-survey.md`](features/internet-connectivity-survey.md).

## Reference data: tiered tables, not one generic `lookups` table

Every dropdown is backed by one of 13 reference tables in two tiers (full ADR: [`lookup-normalization.md`](architecture-decisions/lookup-normalization.md); admin screen: [`features/reference-data.md`](features/reference-data.md)). The SPA conversion did not change any of this.

- **Tier 1 — 9 dedicated tables with real FKs** (`item_types`, `brands`, `equipment_categories`, `equipment_classifications`, `equipment_conditions`, `positions`, `ro_offices`, `sdo_offices`, `isp_providers`). Consuming columns are `_id` foreign keys; options ship `{value: id, label: name}`.
- **Tier 2 — 4 domain-grouped library tables** (`equipment_libraries`, `personnel_libraries`, `stakeholder_libraries`, `connectivity_libraries`), discriminated by `type`. Consuming columns stay validated strings (`Rule::in()`); options ship `{value: string, label: string}`. JSON-array multi-select columns store library (or `isp_providers`) row **ids**, validated per element, with no pivot tables.

Two related lookups are deliberately separate shapes:

- **PSGC locations** (`psgc_provinces` → `psgc_municipalities` → `psgc_barangays`, Region III only, real FKs, fixed government `code`, not admin-editable). The whole tree (~3,100 barangays) ships once in `/api/stakeholder-profiles/options` with `parent_id`s and is filtered client-side into cascading selects.
- **`Office`** is a full CRUD resource, not a 14th reference table: its shape fits neither tier and it is the FK backbone `users`, `stakeholder_profiles` and `internet_connectivity_surveys` point at (`restrictOnDelete`). See [`features/offices.md`](features/offices.md).
