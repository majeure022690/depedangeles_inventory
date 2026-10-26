# Changelog

All notable changes to this project, in terms of user/developer impact. Entries up to 2026-07-15 were reconstructed from migration filename timestamps and source file modification times, before the project had git history; they are accurate to the day where backed by a migration timestamp, and entries reconstructed from file mtimes alone are noted as such. Later entries cite their commits.

## 2026-10-26 — Unified data scope

On `iris-staging`. Commits `80f37ce` (backend), `113f79f` (frontend), `047f83a` (docs) and follow-ups such as `9a17273`. Migrations `2026_10_26_000001`-`000005`. Decision: [`docs/architecture-decisions/unified-data-scope.md`](docs/architecture-decisions/unified-data-scope.md). Covered by `backend/tests/Feature/DataScope/`, `EquipmentDataScopeTest.php`, `RolePermissionSeederTest.php` and `Migrations/UnifiedDataScopeMigrationsTest.php`.

### Changed

- **A role's data scope now covers Personnel, Equipment, ISP Accounts, Stakeholder Profile and the Internet Connectivity Survey**, not just Equipment. `own` reaches the user's linked Personnel record, `school` (now labelled **"Own office"**; the stored value is still `school`) reaches the user's office plus their linked record, and `all` reaches everything. Lists, single records, `/equipment/options` personnel and the dashboard figures are all scoped. An out-of-reach record now returns **404** (it was 403 for Equipment); a missing permission is still 403. Outside `local`/`testing`, every 404 has the same generic body. See [`docs/features/roles-and-permissions.md`](docs/features/roles-and-permissions.md#data-scope).
- **"Own" is an explicit link, not an email match.** New `personnel.user_id` (unique, nullable), backfilled from unambiguous `deped_email` = account email matches (`000002`). After that, it is set only with "Linked personnel" in the `/users` editor (`personnel_id` on `PUT /api/users/{id}`; needs `users.edit`, `personnel.view`, scope `all` and the target within tier; audited as `user.personnel_linked`/`user.personnel_unlinked`). Personnel requests don't accept `user_id`, and the Personnel form shows the link read-only. `deped_email` no longer affects access.
- **Unissued equipment stays visible to whoever created it.** New `equipment.created_by_user_id`, set from the session and backfilled from the audit log (`000003`). Under `school`, unissued items created by anyone at the same office are visible too. Previously an unassigned item was visible only to `all`, so a scoped encoder lost sight of every item they created.
- **ISP accounts belong to an office.** New nullable `isp_accounts.office_id` (`000004`), in the form, `IspAccountResource` (`office_id`, `office`) and `/isp-accounts/options` (`form.office`). An account with no office is visible only to `all`.
- **`stakeholder_profile.view_all` and `internet_connectivity.view_all` are removed** (`000005`). Their holders got `.view` + `.edit` and kept their scope; cross-office access and the two lists now need scope `all`.
- **Writes stay inside the actor's reach.** Only all-scope users can change `office_id` on Personnel or ISP accounts. A scoped user's new Personnel record or ISP account is put in their own office. `own` users can't create Personnel. Scoped user managers can only assign their own office. Transfer targets must be reachable personnel; a missing and an out-of-reach id get the same 422. `office_id` fields must name an active office (the current value is still accepted).
- **Scope tier.** Nobody can create or edit a role scoped wider than their own, edit or delete a role that is already wider (the dialog opens it read-only and only offers scopes up to the actor's own), assign such a role, or manage an account holding one. Admin permissions (audit log, users list, reference data, offices, settings) aren't limited by data scope, so grant them only to all-scope roles.
- **Roles:** `encoder` is relabelled "Division Encoder" and a new `office_encoder` "Office Encoder" (scope `school`, same permissions) is added (`000005`). Division Encoder stays at `all`, so its Personnel, Equipment and ISP reach is unchanged. But it **gains** view and edit of every office's Stakeholder Profile and Connectivity Survey, plus both lists, where it used to reach only the user's own office. The owner accepted this because no production users held roles yet. `roles.data_scope` now defaults to `own` (`000001`). `RolePermissionSeeder` no longer overwrites edited roles: it always resyncs `super_administrator` and only creates the others if missing. Role changes for existing databases ship as migrations, since deploy runs migrations only.
- **API contract:** `UserResource` adds `data_scope`, `personnel_id` and `personnel` (the last only for `personnel.view` holders); `PersonnelResource` adds `user_id` and `user: {id, name}` (only for `users.view` holders, never the email); `/users/options` adds a `personnel` list (`personnel.view` and scope `all` only); `PUT /api/users/{id}` accepts `personnel_id`, and omitting `office_id` now leaves it unchanged instead of clearing it.
- **UI:** sidebar and page titles follow the scope (My Record / Office Personnel / Personnel; My Equipment / Office Equipment / Equipment; Our Office Profile / Stakeholder Profiles; Our Office Connectivity / Internet Connectivity). The dashboard warns users whose account isn't linked to a personnel record or assigned to an office. The Edit Role dialog scrolls as one region.

### Deploy notes

- Scoped users see little until production data is backfilled: `personnel.office_id`, `isp_accounts.office_id`, `users.office_id`, and the user-to-personnel links the email backfill couldn't settle. All-scope admins are unaffected. `php artisan personnel:link-preview` is read-only and lists the email-link pairs, ambiguous emails, and all-scope roles whose office-record access widens. The last section is only meaningful before `000005` runs, so run it before deploying. See the README's Plesk notes.
- `000002`-`000004` are DDL, which MySQL doesn't run in a transaction. If one fails partway, inspect the table and drop the partial column/index/FK before re-running `php artisan migrate --force`.
- `000005`'s `down()` is not an exact reverse. It restores `.view_all` only to `super_administrator` and `system_administrator`, and keeps the `.view`/`.edit` it granted. The actual former holders are in its `system.data_scope_migration` activity row.

## 2026-10-23

On `iris-staging`; no migrations. Covered by `backend/tests/Feature/{EquipmentQrCodeHostTest,RegenerateEquipmentQrCodesTest}.php` and `Security/TrustedProxyHeadersTest.php`.

### Security

- **QR labels always encode `APP_URL`.** `Equipment::qrCodeUrl()` replaced `route('equipment.edit')`, which took its host from the request, so a spoofed `Host`/`X-Forwarded-Host` could get a foreign URL printed on an asset label. New one-off command `php artisan equipment:regenerate-qr-codes`: a dry run by default (lists mismatched rows, soft-deleted included), `--force` rewrites them and audits each as `equipment.qr_code.regenerated` with the old value. Run it once after deploy (README Plesk steps); reprint any label that points at a foreign host. See [`docs/features/equipment.md`](docs/features/equipment.md#non-obvious-design-decisions).
- **Trusted proxies honour only `X-Forwarded-For` by default.** New `TRUSTED_PROXY_HEADERS` (comma list of `for`, `host`, `port`, `proto`, `prefix`; default `for`) opts others in, so a client can't spoof Host or Proto through a trusted proxy. See [`docs/architecture.md`](docs/architecture.md#trusted-proxy-headers).
- **Production is behind Cloudflare.** `TRUSTED_PROXIES` is set to Cloudflare's published IPv4 and IPv6 ranges (from `https://www.cloudflare.com/ips/`) with `TRUSTED_PROXY_HEADERS=for`, so Laravel sees the real client IP instead of Cloudflare's edge addresses. This keeps login lockouts, rate limiting and audit logs per-user instead of per-Cloudflare-server. The `.env.production.example` template documents the ranges and the verification step (check `ip` in `storage/logs/security-YYYY-MM-DD.log` after sign-in). Web-server rate limiting moved to Cloudflare's own rate-limiting rules, since Cloudflare already sees every real visitor IP. The earlier `deploy/nginx/` config was removed because behind Cloudflare, nginx's `$remote_addr` is a Cloudflare address, and its per-IP zones would bucket many unrelated users together. See README "Web server rate limiting" and "Cloudflare and the CSP".
- **`/index.php/...` path info now returns 404** (`backend/public/.htaccess`), since it would otherwise reach Laravel while skipping nginx's per-path limits. The rule matches the decoded `REQUEST_URI` on the original request (`REDIRECT_STATUS` empty), so percent-encoded variants like `/index.ph%70/...` and `/%69ndex.php/...` are caught too. An earlier `THE_REQUEST`-based rule missed them. Verified on Apache 2.4.58.

### Changed

- **A 429 or 5xx no longer signs the SPA user out on load.** The boot session probe retries up to 2 times (honouring `Retry-After`), then shows a "server is busy" notice with a retry button. Auth pages show the shared "Too many attempts" message on a 429. See [`docs/architecture.md`](docs/architecture.md#authentication-flow).
- **Plesk is the only production setup.** The Docker deploy path (`frontend/Dockerfile`, `frontend/docker/default.conf.template`, `frontend/.dockerignore`) was removed; the README, `.env.production.example` files and the stack ADR now describe Plesk (nginx in front of Apache, SPA copied into `backend/public` by `deploy/plesk.sh`).
- **`deploy/plesk.sh` fails the deploy if `backend/public/index.html` is missing** after copying the SPA build. Without it, SPA paths fall through to Laravel's same-origin QR redirect route and loop.
- **CORS exposes `Retry-After`** (`backend/config/cors.php`), so the SPA can read it in cross-origin dev. Production is single-origin and doesn't need it.

## 2026-10-22

On `iris-staging`; no migrations. Covered by `backend/tests/Feature/Auth/NoAccessGateTest.php`, `Auth/TrustedDeviceTest.php`, `Management/{SecuritySettingTest,SystemLogTest,EmailLogTest,PasswordConfirmedSettingsTest}.php`, and `Security/PermissionBoundaryTest.php`.

### Security

- **Accounts with no permissions can no longer sign in.** PRIME is the division-wide directory, so a first-time PRIME login used to create the local user and then walk them through 2FA enrollment into an app where they could see nothing. Now, after a valid password and the `is_active` check, a user holding zero permissions (no role, or only roles that grant none) gets `403 {code: "no_access"}` and the SPA shows "Your account doesn't have access to DICTIS yet. Please contact the Division ICT Unit." on `/login`. No session, 2FA enrollment, trusted-device bypass or recovery codes happen first. The local record is still created/synced so an admin can assign a role; the person then signs in again and enrolls in 2FA. Wrong passwords and inactive accounts still get the generic 422. The same 403 comes from the three pre-session 2FA endpoints (which also drop the pending token) and from every authenticated route, which signs out a user who has lost their last permission. Refusals are logged to the `security` channel and audited as `auth.no_access` (at most once per user and stage per hour). See [`docs/architecture.md`](docs/architecture.md#no-access-refusal).
- **More accounts must always enter their authenticator code.** Before this change, "Trust this device" was disabled only for the role- and user-changing permissions. Now it is also disabled for `security_settings.manage`, `audit_log.view` and `email_log.manage`. The full list is `TrustedDeviceService::ALWAYS_REQUIRE_CODE` (`roles.create`/`edit`/`delete`, `users.edit`, `users.reset_two_factor` plus those three). Any of these lets someone loosen sign-in controls, or read or erase the record of what happened, so a stolen trusted-device cookie should not be enough. Devices already trusted by holders of these permissions stop skipping the code at their next sign-in. See [`docs/features/two-factor-trusted-devices.md`](docs/features/two-factor-trusted-devices.md#design-decisions).
- **Lowering `trusted_device_days` now shortens trust that has already been granted.** Previously the setting only affected devices trusted afterwards, because each device kept the `expires_at` it was given. Now a device is honoured only while it is younger than the current setting and before its own `expires_at`. Raising the setting does not extend existing devices. `GET /api/trusted-devices` now also lists devices that are not currently honoured but haven't reached their stored expiry (new `is_honoured` field; `expires_at` is the effective expiry), and the profile page shows them as "Not currently trusted", so they can still be revoked before they could become valid again. See [`docs/features/two-factor-trusted-devices.md`](docs/features/two-factor-trusted-devices.md#api-endpoints).
- **Security setting changes and log clears need the password again.** `PUT /api/settings/security` and `DELETE /api/settings/system-logs/errors`, `/settings/system-logs/slow-queries` and `/settings/email-log` now require `current_password`. A missing or wrong password returns 422 under `errors.current_password`, and nothing changes or is audited. They use the `password-confirm` limiter (6/min per user, shared with profile updates and recovery-code regeneration), which replaces the old `throttle:10,1` on the three clears. Security setting changes are also written to the `security` log channel (`user_id`, `ip`, `key`, `old`, `new`) alongside the existing `security_setting.updated` audit row, and failed re-confirmations are logged there too. In the SPA these actions open a "Confirm your password" dialog (`components/ui/PasswordConfirmDialog.tsx`). See [`docs/architecture.md`](docs/architecture.md#rate-limiters-and-security-settings).
- **Refused requests no longer spend rate-limit budget.** `EnsureUserIsActive`, `EnforceIdleTimeout` and the `permission:` middleware now run before `throttle:*`, so denied, inactive and idle-expired requests don't count against `password-confirm` or `authenticated-api`. Route-level `authorization.denied` audit rows now record the raw route `parameters` (even for a non-existent record) and get their subject bound by the exception handler. See [`docs/architecture.md`](docs/architecture.md#audit-logging).

## 2026-10-20

On `iris-staging`. Commits `95d7977` (backend, under an unrelated "wip: roles rework" message) and `babbbbb` (frontend). Migration `2026_10_20_170000`. Covered by `backend/tests/Feature/EquipmentDataScopeTest.php`.

### Added

- **Role data scope (`own` / `school` / `all`).** Each role now limits which Equipment rows its permissions reach: items currently issued to the user's own Personnel record (matched by DepEd email), items issued to anyone at the user's school or office, or everything. The scope applies to the Equipment list, show/QR/edit/delete/transactions (an out-of-scope id gets 403) and the dashboard's equipment figures and recent transactions. Personnel, ISP Accounts and the other modules are not scoped. Existing roles were set to `all`, except `viewer`, which was set to `own`. `super_administrator` is always `all`. The Roles screen has a "Data scope" column and select. See [`docs/features/roles-and-permissions.md`](docs/features/roles-and-permissions.md#data-scope).
- **Personnel "School/office"** (`personnel.office_id`, nullable FK to `offices`). The `school` scope uses it. It's on the Personnel form and in `PersonnelResource` (`office_id`, `office`), and `/api/personnel/options` gained an `office` list.

### Fixed

- The Super Administrator role can be saved again. `UpdateRoleRequest` still required that role to have zero permissions, a rule left over from the removed `pending` role.

## 2026-10-19 — Decoupled SPA conversion

The whole app moved from the Inertia/Vue monolith to a Laravel JSON API (`backend/`) plus a React SPA (`frontend/`), mirroring the sibling project rwatch. Decision: [`docs/architecture-decisions/decoupled-spa-stack.md`](docs/architecture-decisions/decoupled-spa-stack.md); execution and deviations: [`decoupled-spa-stack-plan.md`](docs/architecture-decisions/decoupled-spa-stack-plan.md). Commits `ea3b08a`..`1d7732b` on `iris-staging`; migrations `2026_10_19_000001`-`000012`. Features and data are unchanged.

### Changed

- **New staff portal UI** (React, rwatch's sidebar and components). Every screen was ported: Dashboard, Personnel, Equipment (QR, transactions), ISP Accounts, Stakeholder Profile, Internet Connectivity Survey, Offices, Reference Data, Users, Roles, Audit Log, Profile. Users now create, edit and reset 2FA for accounts in dialogs on one page; roles are edited in a dialog.
- **Two-factor authentication is mandatory for every user.** Anyone not yet enrolled is taken through authenticator setup at their next login. Existing authenticator enrollments and recovery codes carried over (migration `000008`); every previously trusted device was forgotten once. "Trust this device" is now configurable (0-30 days) and never applies to users who can manage roles or users. Admins can reset a user's 2FA when they lose their device.
- **Accounts can be deactivated** via a new `is_active` flag (a deactivated user cannot sign in), or deleted outright.
- **Timezone is now Asia/Manila** (was UTC). Timestamps already in the database were shifted +8 hours (migration `000011`), so record and audit times match the office clock. User-entered times (speed test `tested_at`, profile `submitted_at`) and dates were not shifted.
- **Authorization and audit moved onto Spatie packages** (laravel-permission, laravel-activitylog). Permission names, role grants and ids were preserved in place; the old `audit_logs` rows were copied into `activity_log` with actor snapshots kept, then the old table was dropped. The audit trail is still never pruned.
- **Two-server dev setup:** `php artisan serve` (:8000) and `npm run dev` (:5173), browsed at `http://localhost:5173` (`localhost`, not `127.0.0.1`). See the README.

### Added

- Login reCAPTCHA v3, idle sign-out, named rate limiters, security headers and a `security` log channel (from rwatch). A Settings screen (`security_settings.manage`, new permission, admin-only) edits rate limits, trusted-device days and the idle timeout.
- Audit log filter by actor.
- Printed equipment QR labels keep working: the old `/equipment/{id}/edit` URL redirects to the same page in the new frontend.

### Removed

- **Self-registration and email verification** — accounts are created by administrators only.
- **Passkeys**, self-service account deletion, the confirm-password page (sensitive profile actions ask for the current password instead), and the option to turn 2FA off.
- Fortify, Inertia, Wayfinder, Vue/shadcn-vue, and all Node tooling from `backend/`; the unused `.husky/` and `.github/` folders.

## 2026-07-15

### Added

- **Full CRUD for Offices** (`/offices` — index/create/edit/update/destroy, no `show`), a dedicated resource rather than a 14th reference-data table — `Office`'s column shape doesn't fit the Tier 1/Tier 2 pattern, and it's the FK backbone `users`/`stakeholder_profiles`/`internet_connectivity_surveys` all point at, not a closed lookup vocabulary. Reachable from the "Libraries" sidebar entry via a new "Institutional data" section on `/reference-data`, not a separate top-level nav item. Four new granular permissions (`office.view`/`.create`/`.edit`/`.delete`), seeded admin-only — no existing encoder/viewer screen shows an Office picklist. Deleting an office now fails with a friendly validation message instead of silently nulling/cascading dependent data: `users.office_id`/`stakeholder_profiles.office_id`/`internet_connectivity_surveys.office_id` switched from `nullOnDelete()`/`cascadeOnDelete()` to `restrictOnDelete()`, now that a delete UI actually exists. `office_name` uniqueness is enforced both in the Form Requests (friendly message) and via a real DB unique index (closes a TOCTOU race the app-layer check alone couldn't — caught by the same-day security review). See `docs/features/offices.md`.
- **Read-only admin screen for the audit log** (`/audit-log`, `AuditLogController::index()` — one action, no create/store/edit/update/destroy, matching the table's append-only design). `App\Models\AuditLog` has been written to by nearly every controller/policy since 2026-07-10 but had no way to actually browse it until now. Paginated (20/page, newest-first), filterable by free-text `search` (action, or the actor's live name/email, or — for a since-deleted actor — the `properties.actor_snapshot` name/email captured at write time), exact `action`, exact `subject_type` (full FQCN), and an inclusive `from`/`to` date range. New `audit_log.view` permission, seeded admin-only and deliberately not paired with per-resource `.view` permissions — a role holding it alone would see other resources' PII inside `properties` diffs (e.g. Personnel/StakeholderProfile contact data) without holding that resource's own `.view` permission, safe today only because `admin` already holds every `.view` permission in the app (see `Permission::AuditLogView`'s doc-comment). Security-reviewed with no exploitable findings. 17 new tests (`tests/Feature/AuditLogControllerTest.php`); full suite 263/263 passing. See `docs/features/audit-log.md`.

### Removed

- **The Internet Connectivity Survey's "Protected summary" aggregates block (Total ISPs, Total Cost/month, etc.) was removed from the per-office edit page**, shortly after the office-scoping conversion below shipped. It was computed division-wide across all `IspAccount`/`IspSubscriptionCost` rows (`isp_accounts` has no `office_id` column), so every office's edit page showed the exact same totals — confusing rather than useful once each office got its own survey page. See `docs/features/internet-connectivity-survey.md`.

### Changed

- **Internet Connectivity Survey converted from a global singleton to one row per office**, mirroring the previous day's Stakeholder Profile conversion. Added `office_id` (`NOT NULL`, unique FK to `offices`, cascade-deletes) to `internet_connectivity_surveys`, dropped the `singleton_guard` race-condition guard in favor of the same unique-index guarantee used for `stakeholder_profiles`. `InternetConnectivitySurveyController` gains an admin-only `internet-connectivity-surveys.index` (new `internet_connectivity.view_all` permission, admin-only) listing every office and whether it's submitted a survey yet; `edit()`/`update()` are now always scoped to a specific `{office}` route parameter, replacing the old parameterless `internet-connectivity-survey.edit`/`.update` routes. Access is scoped the same way as Stakeholder Profile: `.view`/`.edit` holders can only reach their own `office_id`, `.view_all` holders can reach any office. Unlike Stakeholder Profile's old singleton row (empty junk), this table's one pre-existing row held real seeded survey answers (`InternetConnectivitySurveySeeder`, from the source workbook's "Internet Connectivity" sheet) — it was still discarded rather than attached to a specific office, a deliberate product-owner call, since there was no reliable way to know which office that single division-wide answer set was ever meant to represent. `InternetConnectivitySurveySeeder` and its data file are removed entirely; every office's survey now starts empty, lazily created via `firstOrCreate(['office_id' => ...])` on first edit. See `docs/architecture.md`'s data-access-patterns section and `docs/features/internet-connectivity-survey.md`.

### Security

- **The `aggregates` prop (Total ISPs, Total Cost/month, Total Amount Spent, Total Projected Expenditure, Rooms Covered Admin/Classroom, Total Access Points) was not independently permission-gated.** `InternetConnectivitySurveyPolicy` authorizes the survey itself, not ISP account data — so a custom role holding `internet_connectivity.view`/`.edit` without also holding `isp_accounts.view` could still see division-wide ISP budget/procurement totals it was never granted visibility into, simply by opening a survey's edit page. Flagged during this conversion's security review and fixed the same day: `edit()` now only computes and includes `aggregates` when the requesting user separately holds `isp_accounts.view`, else the prop is `null` and the frontend hides the "Protected summary" card entirely. These aggregates still stay division-wide rather than per-office — `isp_accounts` has no `office_id` column at all, so there's no per-office subset to scope them to; this is a known pre-existing gap in the source data, not something this conversion could fix. Full test suite (224/224) passing, including a rewritten `tests/Feature/InternetConnectivitySurveyControllerTest.php` (15 tests: own-office access, cross-office 403 with no side-effect row creation, `.view_all` bypass, audit logging, validation).

## 2026-07-14

### Added

- **PSGC location lookup (province → municipality → barangay) replaces free-text address fields on Stakeholder Profile.** Added `psgc_provinces`/`psgc_municipalities`/`psgc_barangays` (Region III only, ~3,100 barangays, imported from the division's legacy PSGC dump), each with a real FK to its parent. Stakeholder Profile's `province`/`city_municipality`/`barangay` free-text inputs are now cascading `<Select>`s bound to `province_id`/`municipality_id`/`barangay_id`; the separate `psgc` field is gone (it's just the selected barangay's own code now). `complete_address` moved from a MySQL STORED generated column to a PHP accessor, since generated columns can't join across the new FK tables.
- **Stakeholder Profile: completion bar + numbered section stepper.** Added a `Progress` UI component (shadcn/reka-ui, none existed yet; green with a looping shine sweep) showing % of fields filled in. Above the form, a clickable numbered stepper (1–8, connected by a line) switches which single section (Location, Organization, Key Personnel, ...) is shown — only one is rendered at a time. Any step still missing fields gets a small amber dot on its number.
- **"Remember this device" for two-factor authentication.** 2FA-enrolled users were being challenged on every login with no way to trust a device they'd already verified. Adds a "Remember this device for 30 days" checkbox to the 2FA challenge screen (both the authenticator-code and recovery-code forms). Backed by `two_factor_trusted_devices` (per-user, hashed token, `expires_at`) and an `httpOnly`/`secure` cookie holding the raw token — never a bare unrevocable signed cookie. Implemented by extending Fortify (never forking it): `App\Actions\Fortify\RedirectIfTwoFactorAuthenticatable` and `App\Actions\Fortify\TwoFactorLoginResponse` override the stock actions via container binding in `FortifyServiceProvider`. See `docs/features/two-factor-trusted-devices.md`.
- **Confirmation dialog before logging out.** Clicking "Log out" in the navbar user menu previously logged out immediately; it now opens a confirm dialog first, matching the existing "Delete account" `Dialog` pattern.
- **11-agent roster implemented as real Claude Code subagents** (`.claude/agents/*.md`). `CLAUDE.md` has documented this project's specialist-agent routing table since the initial commit, but the actual subagent definition files backing it never existed until now — each agent's scope and conventions are grounded in this project's own documented decisions, not generic Laravel advice.
- **User accounts now show School/Office, and Created/Modified dates.** `/users` was previously limited to name, email, and role. Added a nullable `office_id` on `users`, backed by a new `offices` table (schools and division-level offices/units together, imported wholesale from the division's existing offices data — 95 rows, `school_id` populated only for actual schools). Both Create and Edit expose an optional School/Office dropdown; the Index table shows the assigned office (with its School ID, if any) plus Created/Modified timestamps.
- **Profile photo upload on Settings > Profile.** `user.avatar` had existed as an unused stub since the starter kit (declared on the frontend `User` type, referenced by `UserInfo.vue`, but no backing column or upload path ever existed). Added a nullable `avatar` column on `users` (stores the relative `public`-disk path; `User::avatar()` resolves it to a full URL on read), image upload validation (`image`, `jpeg`/`png`/`webp`, max 2MB), and a separate "Remove photo" action so clearing a photo doesn't require resubmitting name/email. Requires `php artisan storage:link` (see README) for uploaded photos to actually be servable.

### Changed

- **Logged-in user menu moved from the sidebar footer into the navbar.** `NavUser.vue` (sidebar `SidebarFooter`) removed; the same avatar/dropdown (reusing `UserInfo`/`UserMenuContent`) now lives in `AppSidebarHeader.vue`.
- **Declared PHP floor corrected from `^8.3` to `^8.4`.** `composer.json` claimed `^8.3` while `composer.lock` had already resolved `symfony/clock` to a version requiring PHP `>=8.4.1` — the lock file never actually satisfied the declared floor, silently breaking any environment/CI still targeting 8.3. `composer.lock` only changed its content-hash/platform metadata; no package versions moved.
- **Renamed the top role from `division-ict-admin` to `admin`** (display label: "Administrator", changed from "Division ICT Admin"). A data migration renames the existing seeded row in place rather than leaving a duplicate — `RolePermissionSeeder`'s own upsert can't do this rename by itself, since it matches on the new name. Every hardcoded reference across seeders, tests, and docs updated to match.
- **Stakeholder Profile converted from a global singleton to one row per office.** Now that the app tracks multiple schools under the division (`offices` table), a single division-wide profile no longer fit — each school now has its own stakeholder profile, editable only by that school's own users (`User::office_id` scoping) or by an admin (`stakeholder_profile.view_all`, new permission, admin-only). Added an admin-only `stakeholder-profiles.index` list of every office and whether it's submitted a profile yet. The old `singleton_guard` race-condition guard was dropped in favor of a plain `unique` index on the new `office_id` column. This also reverses this same day's earlier move of Stakeholder Profile into Settings as a personal tab — it's back in the main sidebar nav, since it's now a genuine multi-record admin resource, not a personal settings page. See `docs/architecture.md`'s data-access-patterns section and `docs/features/stakeholder-profile.md`.

### Fixed

- **`complete_address` was `null` instead of `''` on a stakeholder profile's very first load.** `firstOrCreate()`'s INSERT half doesn't refetch STORED generated columns into the in-memory model; now refreshes after a brand-new row is created.
- **Newly-added permissions could be checked on a role's edit screen and silently fail to save.** The Roles admin screen's checkbox list is built from the code-defined `Permission` enum (always current), but `RoleService::syncPermissions()` resolved submitted permission names to ids via the separately-seeded `permissions` table — a permission added to the enum without re-running `RolePermissionSeeder` (as had just happened with `stakeholder_profile.view_all`) would pass validation, then silently resolve to zero ids and get dropped from the sync with no error shown, looking like "it just didn't save." Re-seeded the live permissions table, and `syncPermissions()` now throws a clear validation error naming the unresolved permission(s) instead of silently truncating the set.
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
