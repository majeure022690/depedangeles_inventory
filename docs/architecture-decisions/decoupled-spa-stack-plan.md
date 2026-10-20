# Conversion plan: decoupled SPA stack (mirrors rwatch)

Execution plan for [`decoupled-spa-stack.md`](decoupled-spa-stack.md). Read the ADR first; this document says *what to do in what order*, the ADR says *why*.

## Execution status (2026-10-20)

| Phase | Status | Commit(s) on `iris-staging` |
|---|---|---|
| 0 Dependencies | Done | `e3e85f6` |
| 1 Database | Done | `630f45e` |
| 2 Auth | Done | `eb9ef43` |
| 3 Backend API | Done | `63e9095` |
| 4.0 Frontend shell, 4A-4D page ports | Done | `83ae447`, `f83f5c2`, `e04a474` |
| 5 Tests | Done alongside each phase | — |
| 6 DevOps, 7 Cleanup | Done; the `drop_audit_logs_table` migration (`2026_10_19_000012`) is pending commit | `1d7732b` |
| 8 Sign-off | Documentation done; `qa` → `security` → `reviewer` outstanding | — |

Deviations from the plan below, recorded during execution:

- **`export` rate limiter and `export_rate_limit_per_minute` setting removed** (`436b336`). Inventory has no export feature; re-add both with the feature if one is ever built.
- **App timezone changed from UTC to `Asia/Manila`** (`436b336`), not in the original plan. Form Requests convert UTC ISO input for user-entered datetimes (`NormalizesDateTimesToAppTimezone`), and migration `2026_10_19_000011` shifted existing system-written timestamps +8h (`1108a5c`). See [`architecture.md`](../architecture.md#timezone-asiamanila).
- **Audit log gained an actor filter** (`causer_id` + `actors` in `/api/audit-log/options`, `76d77de`).
- **Route-level `permission:` denials on writes are audited** (`authorization.denied`, `bootstrap/app.php`), since they never reach the Policy.
- **Internet Connectivity Survey `aggregates` not ported**: the panel had already been removed on 2026-07-15, so `GET /api/internet-connectivity-surveys/{office}` returns `{office, survey}` only.
- **`.husky/` and `.github/` were deleted** (`b3e3ebd`) rather than adapted; there are no git hooks or CI workflows. The stray root `inventory` SQLite file was removed earlier than Phase 7 (`cb95a7a`).
- **`PUT /api/users/{self}` is refused outright** (edit yourself via `/api/profile`), stricter than the plan's "self-deactivation blocked".
- **Permissions-Policy is `camera=()`**: no QR scanner was built.

**Reference project (READ-ONLY, never modify anything there):** `C:\xampp\htdocs\rwatch`, referred to below as `rwatch/`. Paths without a prefix are relative to this repo's root.

**Porting rules that apply to every phase:**

1. "Port verbatim" means: copy the rwatch file, then change only rwatch-specific names (`RabiesWatch`/`rwatch_`/`rabieswatch.*` become inventory equivalents), remove references to features inventory doesn't have (geographic scope, facilities, notifications, tours, i18n, site settings), and swap permission names for inventory's. Don't restyle or "improve" anything while porting.
2. **Never port a role-name check.** rwatch's `role:` middleware and `config('rabieswatch.two_factor_always_required_roles')` have permission-based replacements (ADR §5).
3. The old Vue app at `backend/resources/js` is a **read-only porting reference** from Phase 2 onward, once Fortify is removed. It isn't expected to run. Delete it in Phase 7.
4. Keep the suite green at every commit. Each phase deletes a feature's old Inertia test in the same commit that adds its JSON replacement.
5. Product name: `APP_NAME="DepEd Division ICT Inventory System"`, short name `VITE_APP_NAME="DICTIS"` (both already in `backend/.env.example`). Use "DICTIS" wherever rwatch shows "RabiesWatch" (2FA issuer, sidebar brand, page titles, cookie prefix `dictis_trusted_device`).

**Phase order:** 0 devops prep → 1 database → 2 auth → 3 backend → 4 frontend shell, then 4A-4D in parallel → 5 tests/QA → 6 devops → 7 cleanup → 8 security → reviewer → documentation.

---

## Phase 0: Dependencies (`devops`)

In `backend/`:

- `composer require laravel/sanctum spatie/laravel-permission spatie/laravel-activitylog pragmarx/google2fa-laravel bacon/bacon-qr-code`
  - `bacon/bacon-qr-code` is required explicitly because `TwoFactorAuthService` imports it directly. `endroid/qr-code` stays for the existing equipment QR PNG endpoint.
- Do **not** remove Fortify, Inertia, Wayfinder or passkeys yet. Phase 2 and Phase 3 remove each one in the commit that replaces it.
- Publish configs from rwatch, not vendor defaults. Copy these from `rwatch/backend/config/`:
  - `cors.php` (verbatim);
  - `sanctum.php` (verbatim);
  - `permission.php`, keeping `enable_wildcard_permission => false` and `models.role => App\Models\Role`;
  - `activitylog.php`, setting `clean_after_days` to `null` with a comment that inventory never prunes. See ADR §6.
- Add the `security` channel to `config/logging.php`, verbatim from rwatch (lines 134-140).
- Add to `config/app.php`: `'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173')` and `'trusted_proxies' => env('TRUSTED_PROXIES')`.
- Add to `config/services.php`: `'recaptcha' => ['secret_key' => env('RECAPTCHA_SECRET_KEY')]`.

---

## Phase 1: Database (`database`)

New migrations go in `backend/database/migrations/`, dated `2026_10_19_*`, run in this order. Each one is reversible unless it says otherwise. Every data-copy migration gets a migration test in `tests/Feature/Migrations/` (Phase 5).

| # | Migration | Up | Down |
|---|---|---|---|
| 1 | `create_personal_access_tokens_table` | Verbatim from rwatch (`2026_09_14_075228`). Only there so a stray bearer token gets a 401, not a 500 (ADR §9). | drop |
| 2 | `convert_rbac_tables_to_spatie_permission` | **In place, ids preserved** (see the detail below). | full reverse |
| 3 | `create_activity_log_table` | Verbatim from rwatch (`2026_09_14_075229_create_activity_log_table`; activitylog v5 schema with `attribute_changes` + `properties`). | drop |
| 4 | `copy_audit_logs_into_activity_log` | Data copy (see the mapping below). `audit_logs` is **not** dropped here. | delete the copied rows (`where log_name = 'default' and properties->migrated_from_audit_log_id is not null`) |
| 5 | `add_is_active_to_users_table` | `boolean('is_active')->default(true)->after('password')`. Every existing user stays active. | drop column |
| 6 | `rename_avatar_to_avatar_path_on_users_table` | `renameColumn('avatar', 'avatar_path')`, matching rwatch's `AuthController`/`UserResource`. | rename back |
| 7 | `replace_two_factor_trusted_devices_with_trusted_devices` | `dropIfExists('two_factor_trusted_devices')`, then create `trusted_devices` verbatim from rwatch (`2026_10_01_040000`: `user_id` cascade, `token_hash(64)` unique, `user_agent(512)`, `ip_address`, `last_used_ip`, `last_used_at`, `expires_at`, `[user_id, expires_at]` index). Existing trusts are intentionally invalidated: different cookie name and token format. | drop `trusted_devices`, recreate the old table empty |
| 8 | `convert_fortify_two_factor_columns_to_encrypted_casts` | Same column names as rwatch; only the encoding changes (see the detail below). **Irreversible**: `down()` nulls the three 2FA columns. | null 2FA columns |
| 9 | `drop_passkeys_table` | `dropIfExists('passkeys')` (from `2024_01_01_000000_create_passkeys_table`). | recreate the schema of that migration |
| 10 | `create_system_settings_table` | Verbatim from rwatch (`2026_09_14_090028`: `key` unique, `value` text, `type` enum, `description`). Backs `SecuritySettings`. | drop |

**Migration 2 in detail:**

1. `permissions`: add `string('guard_name')->default('web')`, drop `unique(name)`, add `unique(['name', 'guard_name'])`.
2. `roles`: same changes. **Keep** `label` (NOT NULL) and `description` (nullable).
3. Create `model_has_permissions`, `model_has_roles` and `role_has_permissions` with exactly the column, index, PK and FK definitions in `rwatch/backend/database/migrations/2026_09_14_075229_create_permission_tables.php`. No teams.
4. Copy `permission_role(permission_id, role_id)` into `role_has_permissions`.
5. Copy `role_user(role_id, user_id)` into `model_has_roles(role_id, model_type = 'App\Models\User', model_id = user_id)`. Inventory has no morph map, so use the FQCN.
6. Drop `permission_role` and `role_user`.

Do **not** publish Spatie's stock `create_permission_tables` migration.

**Migration 4 mapping (`audit_logs` → `activity_log`)**, in `id` order, chunked:

| `activity_log` column | Source |
|---|---|
| `log_name` | `'default'` |
| `event` | For an `action` matching `<resource>.(created\|updated\|deleted)`, the suffix (`created`/`updated`/`deleted`). For anything else, the full action (`equipment.transaction.recorded`, `role.assigned`, `authorization.denied`, `user.roles_changed`, `account.self_delete_blocked`, ...). |
| `description` | the original `action`, unchanged |
| `subject_type`, `subject_id` | verbatim |
| `causer_type`, `causer_id` | `'App\Models\User'`, `actor_id` when `actor_id` is set; otherwise both null |
| `attribute_changes` | `properties.changes` (`{key: {old, new}}`) becomes `{"old": {key: old}, "attributes": {key: new}}`. `properties.attributes` becomes `{"attributes": …}`. `properties.before`/`after` (reference data) become `{"old": before, "attributes": after}`. Otherwise null. |
| `properties` | the remaining `properties` keys, including `actor_snapshot`, plus `migrated_from_audit_log_id` |
| `created_at`, `updated_at` | `audit_logs.created_at` |

**Migration 8 in detail:**

- Rows where `two_factor_confirmed_at` is null: null all three columns. These are abandoned enrollments; 2FA is now mandatory, so those users enroll fresh.
- Confirmed rows:
  - `two_factor_secret`: `Crypt::encryptString(decrypt($raw))`. Fortify stored `encrypt()` (serialized); rwatch reads the `encrypted` cast (unserialized).
  - `two_factor_recovery_codes`: `json_decode(decrypt($raw))`, then `Hash::make(strtoupper($code))` for each code, then `Crypt::encryptString(json_encode($hashed))`. **The `strtoupper` is required**: rwatch's `TwoFactorAuthService::verifyRecoveryCode()` upper-cases the input before `Hash::check`, and Fortify's codes are mixed-case.
  - On `DecryptException`: null the three columns and `Log::channel('security')->warning(...)`. That user re-enrolls.
- Must run under the same `APP_KEY` that wrote the data.

**Seeders:**

- `RolePermissionSeeder` (owned with `auth`):
  - Rewrite on Spatie: `Permission::findOrCreate($case->value, 'web')` for every `App\Enums\Permission` case, including the new `security_settings.manage`.
  - `Role::updateOrCreate(['name' => …, 'guard_name' => 'web'], ['label' => …, 'description' => …])`, then `syncPermissions()`, keeping today's semantics.
  - Finish with `app(PermissionRegistrar::class)->forgetCachedPermissions()`.
  - `admin` also gets `security_settings.manage`.
- `DatabaseSeeder`: the seeded admin uses `assignRole('admin')` (Spatie). It gets no 2FA columns, so first login goes through mandatory setup, same as rwatch.
- `UserFactory`: add `is_active => true`, rename `avatar` to `avatar_path`, and drop the Fortify `withTwoFactor()` helper in favor of an rwatch-style `twoFactorConfirmed()` state that sets the three columns with the new encoding.

**Phase 7 (later):** `drop_audit_logs_table`, only after QA has verified the copied rows on a real database dump.

---

## Phase 2: Authentication and authorization (`auth`)

### 2a. Files to port from `rwatch/backend` (verbatim unless noted)

| rwatch file | Inventory target / adaptation |
|---|---|
| `app/Http/Controllers/Api/Auth/AuthController.php` | Same path. `user()`/`LoginSession` load `roles.permissions` and `office`. `updateProfile` uses `avatar_path` (Phase 1 #6). |
| `app/Http/Controllers/Api/Auth/TwoFactorController.php` | verbatim |
| `app/Http/Controllers/Api/Auth/TrustedDeviceController.php` | verbatim |
| `app/Http/Requests/Auth/{LoginRequest,ForgotPasswordRequest,ResetPasswordRequest,TwoFactorSetupRequest,TwoFactorConfirmRequest,TwoFactorVerifyRequest,RegenerateRecoveryCodesRequest,UpdateProfileRequest}.php` | Verbatim. `LoginRequest` keeps `Recaptcha::rules('admin_login')`. |
| `app/Services/LoginSession.php` | verbatim |
| `app/Services/TwoFactorAuthService.php` | `ISSUER = 'DICTIS'` |
| `app/Services/TrustedDeviceService.php` | `COOKIE = 'dictis_trusted_device'`. **Replace** the `hasAnyRole(config(...two_factor_always_required_roles))` line with `$user->hasAnyPermission([Permission::RolesManage->value, Permission::UsersManage->value])`. |
| `app/Services/SecuritySettings.php` | Keep `login_rate_limit_per_minute`, `password_reset_rate_limit_per_hour`, `two_factor_verify_rate_limit_per_minute`, `trusted_device_days`, `idle_timeout_seconds`. Remove the three public-portal keys and `export_rate_limit_per_minute` (no export feature; removed in `436b336`). Reword the `trusted_device_days` description to "Users who can manage roles or users always need the code." |
| `app/Services/FileUploadService.php` | verbatim (avatar uploads) |
| `app/Models/SystemSetting.php`, `app/Models/TrustedDevice.php` | verbatim |
| `app/Http/Middleware/SecurityHeaders.php` | Remove the `api/public/lookups` cache branch and the OSM/ArcGIS/Nominatim CSP hosts. Permissions-Policy: `geolocation=(), camera=(self), microphone=()`. `camera=(self)` is kept only if the equipment QR scanner is built later; otherwise use `camera=()`. |
| `app/Rules/Recaptcha.php` | verbatim |
| `app/Notifications/ResetPasswordNotification.php` + `Notifications/Concerns/BuildsBrandedMail.php` + `resources/views/emails/auth/reset-password{,-text}.blade.php` + `resources/views/emails/layouts/branded.blade.php` | Swap the seal/logo images for inventory/DepEd branding. Images go under `resources/images/email/`. |
| `app/Http/Controllers/Api/Management/SecuritySettingController.php` | Route gated by `permission:security_settings.manage` |
| `app/Http/Requests/Management/ResetTwoFactorRequest.php` + `UserController::resetTwoFactor` | Admin reset for a lost authenticator, gated by `users.manage` + `UserPolicy`. Blocks resetting yourself. |
| `bootstrap/app.php` | `statefulApi()`, append `SecurityHeaders`, the Spatie `permission` middleware alias (**not** `role`/`role_or_permission`), and the JSON-only exception rendering block verbatim. Remove `HandleInertiaRequests`, `HandleAppearance`, `web`-group Inertia middleware. |
| `AppServiceProvider` | `configureRateLimiting()` with `login`, `password-reset`, `password-confirm`, `authenticated-api`, `two-factor-verify` (drop `report-submit`, `tracking-lookup`, `public-api`, and `export`, which was ported and then removed with its setting), `TrustProxies`, `Password::defaults(min 12, mixedCase, numbers, symbols)`, `ResetPassword::createUrlUsing(... '/reset-password?...')`, `Gate::policy(Role::class, RolePolicy::class)`. **Delete** inventory's custom `Gate::before`; Spatie's `register_permission_check_method` replaces it. Add the `Activity::creating` hook that writes `properties.actor_snapshot` (ADR §6). |
| `app/Console/Commands/CreateSuperAdmin.php` | Optional. Port it as `app:create-admin` only if `devops` wants a CLI bootstrap path; `DatabaseSeeder` already covers dev. |

### 2b. Model and authorization changes

- **`User`**:
  - Remove `MustVerifyEmail`, `PasskeyUser`, `PasskeyAuthenticatable`, `TwoFactorAuthenticatable`, and the custom `roles()`/`permissionNames()`/`hasPermissionTo()`/`assignRole()`/`removeRole()`/`forgetCachedPermissions()`.
  - Add `HasApiTokens, HasRoles, LogsActivity`.
  - Casts from rwatch: `is_active` boolean, `two_factor_secret` encrypted, `two_factor_recovery_codes` `encrypted:array`.
  - Add `trustedDevices()`, `hasTwoFactorEnabled()`, `sendPasswordResetNotification()`, and the rwatch `saved` hook that deletes trusted devices on a password, secret or deactivation change.
  - Keep `office()`.
  - `getActivitylogOptions()`: `logOnly(['name', 'email', 'avatar_path', 'office_id', 'is_active'])`.
- **`Role`**: `extends Spatie\Permission\Models\Role`. Keep `PENDING`, `isProtected()`, `label`/`description` fillable. Add rwatch's `assignedUsersCount()`/`assignedUsersCountByRoleId()`.
- **Delete:** `App\Models\Permission` (use `Spatie\Permission\Models\Permission`), `App\Models\TwoFactorTrustedDevice`, `app/Actions/Fortify/*`, `app/Providers/FortifyServiceProvider.php` (and its `bootstrap/providers.php` entry), `config/fortify.php`, `app/Concerns/PasswordValidationRules.php`/`ProfileValidationRules.php` once nothing references them, and `laravel/fortify` + `laravel/passkeys` (composer).
- **Policies:**
  - Unchanged in substance. Every `$user->hasPermissionTo(Permission::X)` keeps compiling: Spatie accepts `BackedEnum`.
  - Replace each `AuditLog::record('authorization.denied', …)` in `EquipmentPolicy`, `IspAccountPolicy`, `OfficePolicy`, `PersonnelPolicy`, `RolePolicy` with `activity()->causedBy($user)->event('authorization.denied')->withProperties([...])->log('authorization.denied')`.
  - Add `UserPolicy` (`viewAny`/`create`/`update`/`delete`/`resetTwoFactor`, all `users.manage`; `delete`/`resetTwoFactor` deny self).
  - `RolePolicy` is registered explicitly (Spatie model).
- **`RoleService` / `UserRoleService`:**
  - Same guards.
  - The row lock stays `lockForUpdate()` on the sensitive row in Spatie's `permissions` table.
  - `syncRoles()` becomes Spatie's `$user->syncRoles($roles)`.
  - Audit calls switch to `activity()` with the same event names.
  - The "no remaining holder" count queries `model_has_roles`/`role_has_permissions`.
- **`App\Enums\Permission`:**
  - Add `case SecuritySettingsManage = 'security_settings.manage'`.
  - Add a `group(): string` method (Personnel, Equipment, ISP Accounts, Offices, Stakeholder Profile, Internet Connectivity, Administration). It feeds `GET /api/permissions` so the frontend `PermissionPicker` groups permissions from server data, not a duplicated frontend list.
- **Self-registration** is gone: no `CreateNewUser`, no `register` route. Accounts are created via `POST /api/users`.

### 2c. Auth routes (`backend/routes/api/auth.php`), verbatim from rwatch

```
POST   /api/login                         throttle:login                  AuthController@login
POST   /api/forgot-password               throttle:password-reset         AuthController@forgotPassword
POST   /api/reset-password                throttle:password-reset         AuthController@resetPassword
POST   /api/2fa/setup                     throttle:two-factor-verify      TwoFactorController@setup
POST   /api/2fa/setup/confirm             throttle:two-factor-verify      TwoFactorController@confirmSetup
POST   /api/2fa/verify                    throttle:two-factor-verify      TwoFactorController@verify
-- auth:sanctum + throttle:authenticated-api --
POST   /api/logout                                                        AuthController@logout
GET    /api/user                                                          AuthController@user
GET    /api/session-settings                                              AuthController@sessionSettings
POST   /api/profile                       throttle:password-confirm       AuthController@updateProfile
POST   /api/2fa/recovery-codes/regenerate throttle:password-confirm       TwoFactorController@regenerateRecoveryCodes
GET    /api/trusted-devices                                               TrustedDeviceController@index
DELETE /api/trusted-devices                                               TrustedDeviceController@destroyAll
DELETE /api/trusted-devices/{device}                                      TrustedDeviceController@destroy
```

Fortify/settings routes this replaces:

| Old route(s) | Replacement |
|---|---|
| `login`, `logout` | `/api/login`, `/api/logout` |
| `password.request`, `password.email`, `password.reset`, `password.update` | `/api/forgot-password`, `/api/reset-password` |
| `two-factor.login` | `/api/2fa/verify` |
| `two-factor.enable`, `.confirm`, `.qr-code`, `.secret-key`, `.recovery-codes` | `/api/2fa/setup*`, `/api/2fa/recovery-codes/regenerate` |
| `two-factor.disable` | **removed**: 2FA is mandatory; admins use `POST /api/users/{user}/2fa/reset` |
| `profile.edit` / `profile.update` / `profile.avatar.destroy` | `GET /api/user` + `POST /api/profile` (multipart, `avatar` / `remove_avatar`) |
| `user-password.update` | `POST /api/profile` with `current_password` + `password` + `password_confirmation` |
| `security.edit` | `GET /api/user` (`two_factor_enabled`) + `GET /api/trusted-devices` |
| `appearance.edit` | client-only `ThemeToggle` |

Removed outright: `register`, `verification.*`, `password.confirm`, `passkey.*`, `well-known.passkeys`, `profile.destroy`.

### 2d. `UserResource` (port from rwatch, adapted)

Fields: `id`, `name`, `email`, `avatar_url`, `office_id`, `office {id, office_name, school_id}` (whenLoaded), `is_active`, `two_factor_enabled`, `roles` (names) + `role_ids`, `permissions` (`getAllPermissions()->pluck('name')`, only when roles are loaded), `is_self`, `created_at`, `updated_at`.

The frontend's `AuthContext.can()` reads `user.permissions`. This replaces the Inertia `auth.permissions` shared prop and `HandleInertiaRequests::share()`.

---

## Phase 3: Backend API (`backend`)

**Conventions** (from `rwatch/docs/api-conventions.md`, adopted as-is):

- `{ data, message }` envelope; Laravel paginator shape (`data`/`meta`/`links`).
- `per_page` default 20, clamped to 100.
- Status codes: `201` on create, `204` on delete, `422` on validation, `409` on conflicts.
  - Example: deleting an office still in use returns `409` with a message, replacing today's redirect-back `errors.delete`.
- Every response goes through an explicit-allowlist `JsonResource`, never a raw model.
- Each route group carries a one-line security-classification comment.
- Every `{resource}/options` route is registered **before** `{resource}/{id}`.

**Data-access patterns are unchanged:**

- `EquipmentAccountabilityService` still owns transaction writes.
- Speed tests and subscription costs remain store-only, authorized against the parent IspAccount `update`.
- Stakeholder Profile and Internet Connectivity remain `firstOrCreate(['office_id'])`. The policy is still checked against a transient model *before* the row is created.

**Audit:**

- Add `LogsActivity` with an explicit `logOnly` allowlist to `Personnel`, `Equipment`, `IspAccount`, `Office`, `StakeholderProfile`, `InternetConnectivitySurvey`, and all 13 reference-data models. Use a shared trait in `app/Concerns/` if the 13 reference models need identical options.
- Delete every per-controller `AuditLog::record('<x>.created|updated|deleted')`; `LogsActivity` now produces those rows.
- Convert the non-CRUD calls to `activity()` with the same event names: `equipment.transaction.recorded`, `isp_account.speed_test.recorded`, `isp_account.subscription_cost.recorded`, `reference_data.*` when logged against the registry-resolved model, and `role.*`/`user.*`.
- Then delete `App\Models\AuditLog`.

**Equipment QR stability (ADR §8):**

- `routes/web.php` contains exactly one route: `Route::get('/equipment/{equipment}/edit', fn (Equipment $equipment) => redirect()->away(rtrim(config('app.frontend_url'), '/')."/equipment/{$equipment->id}/edit"))->name('equipment.edit');`.
- `EquipmentObserver` stays unchanged and keeps calling `route('equipment.edit', $equipment)`.

### 3a. Endpoint map: `backend/routes/api/management.php`

Every route below sits inside `Route::middleware(['auth:sanctum', 'throttle:authenticated-api'])`. "Perm" is the route-level `permission:` fast-fail. The Policy column is the authoritative check inside the controller. Controllers live in `App\Http\Controllers\Api\Management\`, Requests in `App\Http\Requests\Management\`, and Resources in `App\Http\Resources\Management\`.

The 4th column lists the Inertia props each old page received. Every one of them must be reachable from the new endpoint(s).

#### Dashboard

| Old route → page (props) | New endpoint | Controller | Perm / Policy | Response |
|---|---|---|---|---|
| `dashboard` → `Dashboard` (`equipment`, `personnel`, `ispAccounts`, `recentTransactions` sections, each included only if the user holds that section's `.view`) | `GET /api/dashboard/summary` | `DashboardController` (invokable) | none at the route; per-section permission gating inside, same as today | `{data: {equipment?, personnel?, isp_accounts?, recent_transactions?}}`: same section shapes as today's props, snake_case keys |

#### Personnel

| Old route → page (props) | New endpoint | Controller@method | Perm / Policy | Request → Resource |
|---|---|---|---|---|
| `personnel.index` → `personnel/Index` (`personnel` paginated, `filters{search,status}`) | `GET /api/personnel?search&status&per_page&page` | `PersonnelController@index` | `personnel.view` / `viewAny` | → `PersonnelResource::collection` |
| `personnel.create` → `personnel/Create` (`options`) and `personnel.edit` → `personnel/Edit` (`options`) | `GET /api/personnel/options` | `PersonnelController@options` | `personnel.view\|personnel.create\|personnel.edit` | `{data: {position, ro_office, sdo_office, name_suffix, cause_of_separation, teachers_funding_source, …}}`: exactly today's `formOptions()` |
| `personnel.store` | `POST /api/personnel` | `@store` | `personnel.create` / `create` | `StorePersonnelRequest` (was `PersonnelStoreRequest`) → `PersonnelResource`, 201 |
| `personnel.edit` → `personnel/Edit` (`personnel`) | `GET /api/personnel/{personnel}` | `@show` | `personnel.view` / `view` | → `PersonnelResource` (detail shape = today's `personnelProps()`) |
| `personnel.update` | `PUT /api/personnel/{personnel}` | `@update` | `personnel.edit` / `update` | `UpdatePersonnelRequest` → `PersonnelResource` |
| `personnel.destroy` | `DELETE /api/personnel/{personnel}` | `@destroy` | `personnel.delete` / `delete` | 204 |

#### Equipment

| Old route → page (props) | New endpoint | Controller@method | Perm / Policy | Request → Resource |
|---|---|---|---|---|
| `equipment.index` → `equipment/Index` (`equipment` paginated, `filters{search,condition,category,disposition_status}`, `options{condition,category,disposition_status}`) | `GET /api/equipment?search&condition&category&disposition_status` | `EquipmentController@index` | `equipment.view` / `viewAny` | → `EquipmentResource::collection` |
| `equipment.create`/`equipment.edit` (`options`, `transactionOptions`, `personnelOptions`) and the Index filter `options` | `GET /api/equipment/options` | `@options` | `equipment.view` | `{data: {form: {item_type, brand, category, classification, condition, unit_of_measure, dcp_package, mode_of_acquisition, source_of_acquisition, source_of_funds, allotment_class, disposition_status}, filters: {condition, category, disposition_status}, transaction: {transaction_type, supporting_document_type}, personnel?: [{value,label}]}}`. `personnel` is included **only** when the user holds `equipment.transactions.create` (it feeds only the transaction form). |
| `equipment.store` | `POST /api/equipment` | `@store` | `equipment.create` / `create` | `StoreEquipmentRequest` → `EquipmentResource`, 201 |
| `equipment.edit` → `equipment/Edit` (`equipment` incl. `transactions`) | `GET /api/equipment/{equipment}` | `@show` | `equipment.view` / `view` | → `EquipmentResource` with `transactions` (`EquipmentTransactionResource::collection`) |
| `equipment.update` | `PUT /api/equipment/{equipment}` | `@update` | `equipment.edit` / `update` | `UpdateEquipmentRequest` → `EquipmentResource` |
| `equipment.destroy` | `DELETE /api/equipment/{equipment}` | `@destroy` | `equipment.delete` / `delete` | 204 |
| `equipment.qr-code` | `GET /api/equipment/{equipment}/qr-code` | `@qrCode` (unchanged body: endroid PNG, `inline`) | `equipment.view` / `view` | `image/png`. The frontend fetches it as a blob via axios, since `<img src>` cross-origin would need cookies. |
| `equipment.transactions.store` | `POST /api/equipment/{equipment}/transactions` | `EquipmentTransactionController@store` → `EquipmentAccountabilityService::recordTransaction()` | `equipment.transactions.create` / `EquipmentPolicy::createTransaction` (existing) | `StoreEquipmentTransactionRequest` → `EquipmentTransactionResource`, 201 |

#### ISP Accounts

Route parameter `{ispAccount}` (keep the camelCase `->parameters()` mapping).

| Old route → page (props) | New endpoint | Controller@method | Perm / Policy | Request → Resource |
|---|---|---|---|---|
| `isp-accounts.index` (`ispAccounts`, `filters{search,isp,status}`, `options{isp_provider}`) | `GET /api/isp-accounts?search&isp&status` | `IspAccountController@index` | `isp_accounts.view` | → `IspAccountResource::collection` |
| `isp-accounts.create`/`.edit` (`options`, `speedTestOptions`) | `GET /api/isp-accounts/options` | `@options` | `isp_accounts.view` | `{data: {form: {isp_provider, school_level_coverage, subscription_type, isp_connection_type, purpose_of_subscription, signal_quality, mode_of_acquisition, source_of_acquisition, source_of_funds}, filters: {isp_provider}, speed_test: {signal_quality}}}` |
| `isp-accounts.store` | `POST /api/isp-accounts` | `@store` | `isp_accounts.create` | `StoreIspAccountRequest` → 201 |
| `isp-accounts.edit` (`ispAccount` incl. `speedTests`, `subscriptionCosts`) | `GET /api/isp-accounts/{ispAccount}` | `@show` | `isp_accounts.view` / `view` | → `IspAccountResource` + `speed_tests` + `subscription_costs` |
| `isp-accounts.update` | `PUT /api/isp-accounts/{ispAccount}` | `@update` | `isp_accounts.edit` | `UpdateIspAccountRequest` |
| `isp-accounts.destroy` | `DELETE /api/isp-accounts/{ispAccount}` | `@destroy` | `isp_accounts.delete` | 204 |
| `isp-accounts.speed-tests.store` | `POST /api/isp-accounts/{ispAccount}/speed-tests` | `IspSpeedTestController@store` | `isp_accounts.edit` / parent `update` | `StoreIspSpeedTestRequest` → `IspSpeedTestResource`, 201 |
| `isp-accounts.subscription-costs.store` | `POST /api/isp-accounts/{ispAccount}/subscription-costs` | `IspSubscriptionCostController@store` | `isp_accounts.edit` / parent `update` | `StoreIspSubscriptionCostRequest` → `IspSubscriptionCostResource`, 201 |

#### Offices

| Old route → page (props) | New endpoint | Controller@method | Perm / Policy | Request → Resource |
|---|---|---|---|---|
| `offices.index` (`offices`, `filters{search}`) | `GET /api/offices?search` | `OfficeController@index` | `office.view` | → `OfficeResource::collection` |
| `offices.create` (no props) / `offices.store` | `POST /api/offices` | `@store` | `office.create` | `StoreOfficeRequest` → 201 |
| `offices.edit` (`office`) | `GET /api/offices/{office}` | `@show` | `office.view` | `OfficeResource` |
| `offices.update` | `PUT /api/offices/{office}` | `@update` | `office.edit` | `UpdateOfficeRequest` |
| `offices.destroy` | `DELETE /api/offices/{office}` | `@destroy` | `office.delete` / `delete` | 204, or **409** "still in use, deactivate instead" |

#### Stakeholder Profile (one row per office)

| Old route → page (props) | New endpoint | Controller@method | Perm / Policy | Request → Resource |
|---|---|---|---|---|
| `stakeholder-profiles.index` (`offices` paginated with `has_profile`/`updated_at`, `filters{search}`) | `GET /api/stakeholder-profiles?search` | `StakeholderProfileController@index` | `stakeholder_profile.view_all` / `viewAny` | paginated `{id, office_name, office_type, school_id, has_profile, updated_at}` |
| `stakeholder-profiles.edit` (`options`, incl. the PSGC province/municipality/barangay arrays) | `GET /api/stakeholder-profiles/options` | `@options` | `stakeholder_profile.view\|stakeholder_profile.view_all` | `{data: {governance_level, transaction_type, nearby_institution, type_of_access_road, by_transportation, community_engagement, province, municipality[parent_id], barangay[parent_id]}}`. Same shapes as today. The full Region III tree is sent once and filtered client-side. |
| `stakeholder-profiles.edit` (`office`, `stakeholderProfile`) | `GET /api/stakeholder-profiles/{office}` | `@show` | `stakeholder_profile.view\|stakeholder_profile.view_all` / `view` on a transient model **before** `firstOrCreate` | `{data: {office: {id, office_name, school_id}, profile: StakeholderProfileResource}}` |
| `stakeholder-profiles.update` | `PUT /api/stakeholder-profiles/{office}` | `@update` | `stakeholder_profile.edit\|stakeholder_profile.view_all` / `update` on a transient model | `UpdateStakeholderProfileRequest` → `StakeholderProfileResource` |

#### Internet Connectivity Survey (one row per office)

| Old route → page (props) | New endpoint | Controller@method | Perm / Policy | Request → Resource |
|---|---|---|---|---|
| `internet-connectivity-surveys.index` (`offices`, `filters`) | `GET /api/internet-connectivity-surveys?search` | `InternetConnectivitySurveyController@index` | `internet_connectivity.view_all` / `viewAny` | paginated `{id, office_name, office_type, school_id, has_survey, updated_at}` |
| `internet-connectivity-surveys.edit` (`options`) | `GET /api/internet-connectivity-surveys/options` | `@options` | `internet_connectivity.view\|internet_connectivity.view_all` | `{data: {isp_provider, signal_quality, mobile_network_signal, coverage_area, source_of_electricity}}` |
| `internet-connectivity-surveys.edit` (`office`, `internetConnectivitySurvey`, `aggregates`) | `GET /api/internet-connectivity-surveys/{office}` | `@show` | `internet_connectivity.view\|internet_connectivity.view_all` / `view` (transient, pre-create) | `{data: {office, survey: InternetConnectivitySurveyResource, aggregates: {...}\|null}}`. `aggregates` stays `null` unless the user **also** holds `isp_accounts.view` (existing security finding; keep the test). |
| `internet-connectivity-surveys.update` | `PUT /api/internet-connectivity-surveys/{office}` | `@update` | `internet_connectivity.edit\|internet_connectivity.view_all` / `update` (transient) | `UpdateInternetConnectivitySurveyRequest` → resource (no `aggregates` in the writable payload) |

#### Reference data (one generic controller, driven by `config/reference-data.php`)

| Old route → page (props) | New endpoint | Controller@method | Perm / Policy | Request → Resource |
|---|---|---|---|---|
| `reference-data.index` (`tables[{key,label,tier,row_count}]`, `officeCount`) | `GET /api/reference-data` | `ReferenceDataController@index` | `reference-data.manage` | `{data: {tables: [...], office_count}}` |
| `reference-data.show` (`table`, `label`, `tier`, `rows` paginated, `types`, `filters{search,type}`) | `GET /api/reference-data/{table}?search&type` (`where('table','[a-z-]+')`) | `@show` | `reference-data.manage` | paginated `ReferenceDataRowResource` (Tier 1: `id, name, description, sort_order, is_active`; Tier 2: `+type, value`), plus `meta.table = {key, label, tier, types\|null}` via `additional()` |
| `reference-data.store` | `POST /api/reference-data/{table}` | `@store` | `reference-data.manage` | `StoreReferenceDataRequest` → 201 |
| `reference-data.update` | `PATCH /api/reference-data/{table}/{id}` | `@update` | `reference-data.manage` | `UpdateReferenceDataRequest` |
| `reference-data.destroy` | `DELETE /api/reference-data/{table}/{id}` | `@destroy` | `reference-data.manage` | 204, or **409** when referenced (existing guarded-delete rule) |

#### Users (`users.manage`)

| Old route → page (props) | New endpoint | Controller@method | Perm / Policy | Request → Resource |
|---|---|---|---|---|
| `users.index` (`users` paginated with `roles`, `role_ids`, `is_self`, `office`; `roles` assignable options; `offices`; `filters{search,role}`) | `GET /api/users?search&role` | `UserController@index` | `users.manage` / `UserPolicy::viewAny` | `UserResource::collection` |
| (same page) `roles` + `offices` | `GET /api/users/options` | `@options` | `users.manage` | `{data: {roles: assignable-only (UserRoleService tier filter, as today), offices: Office::activeOptions()}}` |
| `users.create` → `users/Create` / `users.store` | `POST /api/users` | `@store` → `UserRoleService` | `users.manage` | `StoreUserRequest` (name, email, password, office_id, role_ids[], is_active) → 201 |
| `users.update` | `PUT /api/users/{user}` | `@update` → `UserRoleService` | `users.manage` | `UpdateUserRequest` (+ `is_active`; self-deactivation blocked) |
| `users.destroy` | `DELETE /api/users/{user}` | `@destroy` | `users.manage` / `delete` (no self) | 204 |
| *(new, rwatch)* | `POST /api/users/{user}/2fa/reset` | `@resetTwoFactor` | `users.manage` / `resetTwoFactor` | `ResetTwoFactorRequest`. Clears the 2FA columns and trusted devices; writes the `security` log and activity. |

The two rwatch user-avatar admin endpoints are **not** ported: inventory users manage their own avatar via `/api/profile`.

#### Roles and permissions

| Old route → page (props) | New endpoint | Controller@method | Perm / Policy | Request → Resource |
|---|---|---|---|---|
| `roles.index` (`roles[{…, user_count, permission_count, is_protected}]`) | `GET /api/roles` | `RoleController@index` | `roles.manage\|users.manage` / `viewAny` | `RoleResource::collection` |
| `roles.edit` (`role` incl. `permissions`) | `GET /api/roles/{role}` | `@show` | `roles.manage\|users.manage` / `view` | `RoleResource` with permissions |
| `roles.create`/`roles.edit` (`permissions` catalog) | `GET /api/permissions` | `PermissionController@index` | `roles.manage\|users.manage` | `PermissionResource` `{name, label, group}` from the enum, joined with DB rows |
| `roles.store` | `POST /api/roles` | `@store` → `RoleService` | `roles.manage` | `StoreRoleRequest` (name, label, description, permissions[]) → 201 |
| `roles.update` | `PUT /api/roles/{role}` | `@update` → `RoleService` | `roles.manage` | `UpdateRoleRequest` |
| `roles.destroy` | `DELETE /api/roles/{role}` | `@destroy` → `RoleService` | `roles.manage` | 204, or **409** (protected / still assigned) |

#### Audit log and security settings

| Old route → page (props) | New endpoint | Controller@method | Perm / Policy | Request → Resource |
|---|---|---|---|---|
| `audit-log.index` (`logs` paginated, `filters{search,action,subject_type,from,to}`) | `GET /api/audit-log?search&event&subject_type&causer_id&date_from&date_to` | `AuditLogController@index` (Spatie `Activity`; filters whitelisted; `search` matches `description` or causer name/email) | `audit_log.view` | `AuditLogResource` (rwatch shape **plus** `actor` resolved from causer, falling back to `properties.actor_snapshot`, and `properties`) |
| (same page) `filterOptions{actions, subjectTypes}` | `GET /api/audit-log/options` | `@options` | `audit_log.view` | `{data: {events: distinct event, subject_types: [{value: FQCN, label: basename}]}}` |
| *(new, rwatch)* | `GET /api/settings/security` | `SecuritySettingController@index` | `security_settings.manage` | `SecuritySettings::all()` |
| *(new, rwatch)* | `PUT /api/settings/security` | `@update` | `security_settings.manage` | `{key, value}` |

Not ported (YAGNI; inventory has no use for them): rwatch's system-log/slow-query viewer, email log, SMS, site settings, notifications, exports. rwatch's `export` limiter was not kept either; add it back together with an export feature if one is ever requested.

### 3b. Backend files deleted in Phase 3

- `app/Http/Controllers/*.php`: the old Inertia controllers. Each is replaced by its `Api/Management` counterpart.
- `app/Http/Controllers/Settings/*`
- `app/Http/Controllers/Concerns/HasLookupOptions.php`: move it to `app/Http/Controllers/Api/Management/Concerns/` if the options methods still use it. Its logic is unchanged.
- `app/Http/Requests/*.php` and `Requests/Settings/*`: moved into `Requests/Management/` with rwatch's `Store<X>Request`/`Update<X>Request` naming.
- `app/Http/Middleware/HandleInertiaRequests.php`, `HandleAppearance.php`
- `config/inertia.php`, `resources/views/app.blade.php`, `routes/settings.php`
- Old `routes/web.php` content, except the single QR redirect route.
- Composer: `inertiajs/inertia-laravel`, `laravel/wayfinder`.

---

## Phase 4: Frontend (`frontend`)

Create `frontend/` as a fresh Vite React-TS app. Copy these from `rwatch/frontend` and edit names: `package.json`, `vite.config.ts` (port 5173, `/api` proxy to :8000, `@` alias), `tsconfig*.json`, `index.html` (title "DICTIS"), and `.env.example` (`VITE_APP_NAME="DICTIS"`, `VITE_API_URL=http://localhost:8000`, `VITE_RECAPTCHA_SITE_KEY=<Google test key>`).

From rwatch's `package.json`, **keep only these dependencies**: `react`, `react-dom`, `react-router-dom`, `axios`, `@radix-ui/react-dialog`, `@tanstack/react-table`, `lucide-react`, `clsx`, `tailwind-merge`, `react-google-recaptcha-v3`, `react-easy-crop` (avatar crop), and `recharts` (dashboard). Dev dependencies as in rwatch.

**Drop:** `i18next*`, `react-i18next`, `leaflet*`, `react-leaflet*`, `@tiptap/*`, `dompurify`, `driver.js` (tours), `jspdf*`, `qr-scanner`, `qrcode`.

### 4.0 Shell (one agent, must finish before 4A-4D start)

**Copy and adapt from `rwatch/frontend/src`:**

- `main.tsx`, `App.tsx` (rewritten route table; see the routes list below), `index.css` (verbatim; Poppins plus the blue primary scale already match inventory's visual language).
- `components/ui/*`: all of them **except** `RichContent`, `RichTextEditor`, `TourButton`. That leaves `Alert, Badge, Button, Card, Checkbox, ConfirmDialog, DataGrid, DetailSection, Dialog, FieldLabelText, FileInput, FilterControls, Input, OtpInput, Pagination, Select, SelectPicker, Skeleton, Spinner, SuggestInput, Switch, Tabs, Textarea`.
- `components/`:
  - `ErrorBoundary`, `IdleLogout`, `ThemeToggle`, `TrustedDevicesCard`, `PagePlaceholder`, `ImageCropDialog`, `DetailPageSkeleton`: verbatim.
  - `BrandMarks`: inventory/DepEd marks.
  - `management/AuthLayout`: **remove** the `publicSiteSettingService` login-background fetch and use a static background.
  - `management/PermissionPicker`: groups come from `GET /api/permissions` `group`/`label`, not a hard-coded list.
- `context/AuthContext.tsx`: verbatim. `can()` reads `user.permissions`.
- `router/Guards.tsx`: verbatim.
- `hooks/`: `usePageTitle`, `usePortalTheme`, `useRecaptcha`, `useShowRecaptchaBadge`, `useServerTable`.
- `lib/`:
  - `api.ts`, `errors.ts`, `utils.ts`, `recaptcha.ts`, `idleTicker.worker.ts`, `cropImage.ts`: verbatim.
  - `branding.ts`: DICTIS.
  - `permissions.ts`: reduced to `SETTINGS_PERMISSIONS = ['security_settings.manage']` plus a typed `PermissionName` union mirroring `App\Enums\Permission`.
- `layouts/ManagementLayout.tsx`: remove `NotificationBell`; use the inventory sidebar below.
- `pages/`:
  - `NotFound`, `management/Login`, `ForgotPassword`, `ResetPassword`, `TwoFactorSetup`, `TwoFactorVerify`: verbatim, minus tours.
  - `ProfileSettings`: minus tours. Adds the avatar and password change that replace `settings/Profile.vue` + `settings/Security.vue`.
  - `Settings`: only the `SecuritySettingsPanel` tab.
  - `SecuritySettingsPanel`: verbatim.
- `services/`: `profileService`, `twoFactorService`, `trustedDeviceService`, `securitySettingService` (verbatim); `lookupService` (shared `Option` fetch helpers).
- `types/`: `api.ts`, `auth.ts` (User has `office_id`, `office`, `role_ids`), `lookup.ts` (`Option = {value: string|number, label: string, parent_id?: number}`).
- **Strip from every copied file:** `TourButton`, `usePageTour`, `lib/tours`, `useTranslation`.

**Shared form helpers needed by more than one batch** are created in the shell: `components/forms/CheckboxGroupField.tsx` (port of `components/CheckboxGroupField.vue` + `composables/useCheckboxGroupField.ts`) and `components/forms/FieldHint.tsx` (port of `FieldHint.vue`).

**The shell pre-registers every route below in `App.tsx`**, pointing at the final page file paths. It also creates each of those page files as a `PagePlaceholder` stub. Batch agents then only **overwrite their own page files** plus their own `services/<feature>Service.ts`, `types/<feature>.ts`, and `components/<feature>/*`. No batch edits `App.tsx`, `ManagementLayout.tsx`, or another batch's files, so the batches can't conflict.

**Routes (`App.tsx`):**

```
/                                   → <Navigate to="/dashboard">
/equipment/:id/edit                 → <Navigate to="/equipment/:id/edit">     (QR alias, ADR §8)
/login | forgot-password | reset-password | 2fa/setup | 2fa/verify   (GuestOnly)
  (RequireAuth → ManagementLayout)
  dashboard
  profile
  personnel, personnel/new, personnel/:id/edit                         RequirePermission personnel.view
  equipment, equipment/new, equipment/:id/edit                         equipment.view
  isp-accounts, isp-accounts/new, isp-accounts/:id/edit                isp_accounts.view
  stakeholder-profiles                                                 stakeholder_profile.view_all
  stakeholder-profiles/:officeId                                       stakeholder_profile.view | .view_all
  internet-connectivity-surveys                                        internet_connectivity.view_all
  internet-connectivity-surveys/:officeId                              internet_connectivity.view | .view_all
  offices, offices/new, offices/:id/edit                               office.view
  reference-data, reference-data/:table                                reference-data.manage
  users                                                                users.manage
  roles                                                                roles.manage
  audit-log                                                            audit_log.view
  settings                                                             security_settings.manage
*                                   → NotFound
```

**Sidebar (`ManagementLayout` `NAV_GROUPS`, lucide icons, colored like rwatch):**

| Group | Item | Route | Permission |
|---|---|---|---|
| *(none)* | Dashboard (`LayoutDashboard`) | `/dashboard` | none |
| **Records** | Personnel (`Users`) | `/personnel` | `personnel.view` |
| | Equipment (`Monitor`) | `/equipment` | `equipment.view` |
| | ISP Accounts (`Wifi`) | `/isp-accounts` | `isp_accounts.view` |
| **Office Profiles** | Stakeholder Profile (`School`) | `/stakeholder-profiles` if the user holds `stakeholder_profile.view_all`; otherwise `/stakeholder-profiles/{user.office_id}` if they hold `.view` and have an `office_id` (same branching as today's `AppSidebar.vue`) | `stakeholder_profile.view\|.view_all` |
| | Internet Connectivity (`Signal`) | same branching | `internet_connectivity.view\|.view_all` |
| **Libraries** | Reference Data (`Library`) | `/reference-data` | `reference-data.manage` |
| | Offices (`Building2`) | `/offices` | `office.view` |
| **Administration** | Users (`UserCog`) | `/users` | `users.manage` |
| | Roles (`ShieldCheck`) | `/roles` | `roles.manage` |
| | Audit Log (`History`) | `/audit-log` | `audit_log.view` |
| | Settings (`Settings`) | `/settings` | `security_settings.manage` |

The `ManagementLayout` nav-item type needs a `to` that can be a function of the user, for the two office-scoped items. That is the shell's one small extension to rwatch's layout.

### 4A-4D: Page ports (four batches, run in parallel after 4.0)

Each Vue page maps to the TSX file(s) listed. A `Partials/Form.vue` becomes a `components/<feature>/<Feature>Fields.tsx` shared by that feature's create and edit pages. Lists use `DataGrid` + `useServerTable` + `FilterControls`. Destructive actions use `ConfirmDialog`. Toasts and flash messages (`lib/flashToast.ts`) become rwatch's inline `Alert` pattern.

**Batch 4A: Assets (Equipment and Personnel)**

| Vue source (`backend/resources/js/…`) | New file(s) (`frontend/src/…`) |
|---|---|
| `pages/equipment/Index.vue` | `pages/management/equipment/EquipmentList.tsx` |
| `pages/equipment/Create.vue` | `pages/management/equipment/EquipmentCreate.tsx` |
| `pages/equipment/Edit.vue` | `pages/management/equipment/EquipmentEdit.tsx` (includes the QR image fetched as a blob, plus print/download) |
| `pages/equipment/Partials/Form.vue` | `components/equipment/EquipmentFields.tsx` |
| `pages/equipment/Partials/TransactionLog.vue` | `components/equipment/TransactionLog.tsx` |
| `pages/personnel/Index.vue` | `pages/management/personnel/PersonnelList.tsx` |
| `pages/personnel/Create.vue` | `pages/management/personnel/PersonnelCreate.tsx` |
| `pages/personnel/Edit.vue` | `pages/management/personnel/PersonnelEdit.tsx` |
| `pages/personnel/Partials/Form.vue` | `components/personnel/PersonnelFields.tsx` |
| `types/equipment.ts`, `types/personnel.ts` | `types/equipment.ts`, `types/personnel.ts` + `services/equipmentService.ts`, `services/personnelService.ts` |

**Batch 4B: Connectivity and office profiles**

| Vue source | New file(s) |
|---|---|
| `pages/isp-accounts/Index.vue` | `pages/management/isp-accounts/IspAccountList.tsx` |
| `pages/isp-accounts/Create.vue` | `pages/management/isp-accounts/IspAccountCreate.tsx` |
| `pages/isp-accounts/Edit.vue` | `pages/management/isp-accounts/IspAccountEdit.tsx` |
| `pages/isp-accounts/Partials/Form.vue` | `components/isp-accounts/IspAccountFields.tsx` |
| `pages/isp-accounts/Partials/SpeedTestLog.vue` | `components/isp-accounts/SpeedTestLog.tsx` |
| `pages/isp-accounts/Partials/SubscriptionCostLog.vue` | `components/isp-accounts/SubscriptionCostLog.tsx` |
| `pages/stakeholder-profile/Index.vue` | `pages/management/stakeholder-profiles/StakeholderProfileList.tsx` |
| `pages/stakeholder-profile/Edit.vue` | `pages/management/stakeholder-profiles/StakeholderProfileEdit.tsx` |
| `pages/stakeholder-profile/Partials/Form.vue` | `components/stakeholder-profiles/StakeholderProfileFields.tsx` (PSGC province → municipality → barangay cascade, filtered client-side by `parent_id`) |
| `pages/internet-connectivity-survey/Index.vue` | `pages/management/internet-connectivity-surveys/ConnectivitySurveyList.tsx` |
| `pages/internet-connectivity-survey/Edit.vue` | `pages/management/internet-connectivity-surveys/ConnectivitySurveyEdit.tsx` (renders the read-only `aggregates` panel only when it isn't `null`) |
| `pages/internet-connectivity-survey/Partials/Form.vue` | `components/internet-connectivity-surveys/ConnectivitySurveyFields.tsx` |
| `types/isp-account.ts`, `types/stakeholder-profile.ts`, `types/internet-connectivity-survey.ts` | same names under `types/` + `services/ispAccountService.ts`, `services/stakeholderProfileService.ts`, `services/connectivitySurveyService.ts` |

**Batch 4C: Administration and RBAC** (closest to rwatch, so port rwatch pages first and adapt)

| Vue source | New file(s) |
|---|---|
| `pages/users/Index.vue` + `pages/users/Create.vue` | `pages/management/users/UserList.tsx`. Start from **rwatch's `UserList.tsx`**: create/edit in dialogs; replace `PsgcLocationPicker`/scope fields with an office `SelectPicker`; multi-role checkboxes from `/api/users/options`; `is_active` `Switch`; "Reset 2FA" action. `users/Create.vue` folds into the create dialog. |
| `pages/roles/Index.vue`, `Create.vue`, `Edit.vue`, `Partials/Form.vue` | `pages/management/roles/RoleList.tsx`. Start from **rwatch's `RoleList.tsx`** + `PermissionPicker`. Create/edit in a dialog. Show `label`, `description`, `user_count`; protected-role badge. The three Vue pages fold into this one. |
| `pages/audit-log/Index.vue` | `pages/management/AuditLog.tsx`. Start from **rwatch's `AuditLog.tsx`**; add the search box, the `event`/`subject_type` filters from `/api/audit-log/options`, and the actor-snapshot fallback display. |
| *(new)* | `pages/management/Settings.tsx` + `SecuritySettingsPanel.tsx` (copied in 4.0; this batch verifies them) |
| `types/users.ts`, `types/roles.ts`, `types/audit-log.ts` | `types/role.ts`, `types/auditLog.ts` + `services/userService.ts`, `services/roleService.ts`, `services/auditLogService.ts` (adapted from rwatch) |

**Batch 4D: Libraries and Dashboard**

| Vue source | New file(s) |
|---|---|
| `pages/offices/Index.vue` | `pages/management/offices/OfficeList.tsx` |
| `pages/offices/Create.vue` | `pages/management/offices/OfficeCreate.tsx` |
| `pages/offices/Edit.vue` | `pages/management/offices/OfficeEdit.tsx` (handles the 409 "still in use" message on delete) |
| `pages/offices/Partials/Form.vue` | `components/offices/OfficeFields.tsx` |
| `pages/reference-data/Index.vue` | `pages/management/reference-data/ReferenceDataIndex.tsx` (Tier 1 / Tier 2 grid + "Institutional data → Offices" card) |
| `pages/reference-data/Show.vue` | `pages/management/reference-data/ReferenceDataTable.tsx` (inline create/edit dialog, `type` filter for Tier 2, 409 on in-use delete) |
| `pages/Dashboard.vue` + `components/dashboard/BreakdownList.vue` | `pages/management/Dashboard.tsx` + `components/dashboard/BreakdownList.tsx`. Layout and cards modeled on rwatch's `Dashboard.tsx`; `recharts` is allowed. Each section renders only when present in the response. |
| `types/office.ts`, `types/reference-data.ts`, `types/dashboard.ts` | same under `types/` + `services/officeService.ts`, `services/referenceDataService.ts`, `services/dashboardService.ts` |

**Not ported (replaced or dropped):**

- `pages/Welcome.vue`
- `pages/auth/*`: replaced by the rwatch auth pages in 4.0. `Register`, `VerifyEmail` and `ConfirmPassword` are dropped.
- `pages/settings/Profile.vue` and `Security.vue`: replaced by `ProfileSettings.tsx`.
- `pages/settings/Appearance.vue`: replaced by `ThemeToggle`.
- All of `actions/`, `routes/`, `wayfinder/` (Wayfinder output).
- Passkey components, `ManageTwoFactor`, `TwoFactorSetupModal`, `TwoFactorRecoveryCodes`: replaced by the rwatch 2FA pages and `ProfileSettings`.
- `AppSidebar`, `NavMain`, `AppShell`, `AppContent`, `Breadcrumbs`, `UserInfo`, `UserMenuContent`, and every layout under `layouts/`: replaced by `ManagementLayout`.
- `composables/usePermissions`: replaced by `AuthContext.can()`.
- `useAppearance`, `useTableFilters`, `useCurrentUrl`, `useInitials`: replaced by rwatch hooks and `useServerTable`.

**Frontend done-criteria for every batch:**

- `npm run build` (`tsc -b && vite build`) passes.
- Every page checked light and dark, plus at the collapsed sidebar width.
- Permission-gated buttons use `can()`. They are UX only; the server re-checks every request.
- Forms render 422 `errors` per field via `lib/errors.ts`.

---

## Phase 5: Tests (`qa`, with the owning agent writing each test alongside its endpoint in Phases 2-3)

All Feature tests use `getJson`/`postJson`/`putJson`/`deleteJson` + `actingAs($user)` with the session guard (Sanctum stateful). Seed with `RolePermissionSeeder` and call `PermissionRegistrar::forgetCachedPermissions()` in `TestCase::setUp`.

| Existing test | Action |
|---|---|
| `Auth/AuthenticationTest`, `Auth/PasswordResetTest` | Replace with ports of `rwatch/backend/tests/Feature/Auth/AuthenticationTest.php`, `PasswordResetTest.php` |
| `Auth/TwoFactorChallengeTest`, `Settings/SecurityTest` | Replace with ports of rwatch `Auth/TwoFactorAuthenticationTest.php` + `Auth/TrustedDeviceTest.php`. Add a case that a `roles.manage`/`users.manage` holder never gets a trusted-device bypass. |
| `Auth/RegistrationTest`, `Auth/RegistrationApprovalTest`, `Auth/EmailVerificationTest`, `Auth/VerificationNotificationTest`, `Auth/PasswordConfirmationTest` | **Delete.** Replace with `Security/UnusedPackageRoutesTest` (port from rwatch), asserting `/register`, `/email/verify`, `/user/confirm-password`, `/two-factor-challenge`, `/passkeys/*` return 404. |
| `Settings/ProfileUpdateTest` | Rewrite against `POST /api/profile` (name/email/avatar/remove_avatar/password with `current_password`) |
| `DashboardTest`, `EquipmentControllerTest`, `EquipmentQrCodeTest`, `PersonnelControllerTest`, `IspAccountControllerTest`, `IspAccountPolicyTest`, `OfficeControllerTest`, `StakeholderProfileControllerTest`, `InternetConnectivitySurveyControllerTest`, `ReferenceDataControllerTest`, `ReferenceDataPolicyTest`, `RoleControllerTest`, `UserControllerTest`, `AuditLogControllerTest` | Rewrite in place against the endpoints in §3a. Same scenarios, JSON assertions (`assertJsonPath`, `assertCreated`, `assertNoContent`, `assertForbidden`, `assertConflict`). Keep every existing invariant test: accountability-sync exception, append-only (no update/destroy routes), office scoping + transient-model pre-create authorization, ICS `aggregates` null without `isp_accounts.view`, RBAC self-escalation / lockout / tier separation. |
| `ReferenceDataSeederTest`, `ReferenceDataJsonBackfillSeederTest`, `*ReferentialIntegrityTest`, `Unit/PersonnelTier1ForeignKeyMigrationTest` | Keep. Adjust only for the role and audit model changes. |
| *(new)* | `Feature/Migrations/ConvertRbacToSpatieTest` (ids and grants preserved), `CopyAuditLogsToActivityLogTest` (field mapping, actor snapshot kept), `ConvertFortifyTwoFactorTest` (a converted secret verifies a TOTP; a converted mixed-case recovery code verifies case-insensitively; an undecryptable row is nulled) |
| *(new, from rwatch)* | `RateLimitingTest`, `Security/ProductionErrorResponseTest`, `Security/PermissionBoundaryTest` (every management route returns 403 for a zero-permission `pending` user), `Unit/RecaptchaRuleTest`, `Management/SecuritySettingTest`, an `EquipmentQrRedirectTest` for the web redirect |

`composer ci:check` becomes `pint --test` + `phpstan analyse` + `php artisan test`. The npm steps move to `frontend` (`npm run build`).

---

## Phase 6: DevOps (`devops`)

- **`backend/.env.example`:** add (with comments, rwatch wording):
  ```
  APP_URL=http://localhost:8000
  FRONTEND_URL=http://localhost:5173
  SANCTUM_STATEFUL_DOMAINS=localhost:5173
  SESSION_DOMAIN=localhost
  SESSION_SECURE_COOKIE=false
  SESSION_DRIVER=database
  RECAPTCHA_SECRET_KEY=<Google test secret>
  TRUSTED_PROXIES=
  ```
  Remove `VITE_*` (they move to `frontend/.env.example`). Keep the MySQL/XAMPP defaults and comments.
- **`backend/.env.production.example`:** port rwatch's file (HTTPS `FRONTEND_URL`, `SESSION_SECURE_COOKIE=true`, a real reCAPTCHA key; the test key fails closed in production via `Recaptcha::TEST_SECRET_KEY`).
- **`frontend/.env.example`:** `VITE_APP_NAME`, `VITE_API_URL`, `VITE_RECAPTCHA_SITE_KEY`.
- **Root `README.md` "Local setup":** rewrite modeled on `rwatch/DEPLOY.md`.
  - First-time setup: `cd backend && composer install && cp .env.example .env && php artisan key:generate && php artisan migrate --seed && php artisan storage:link`, then `cd ../frontend && cp .env.example .env && npm install`.
  - Daily: terminal 1 `cd backend && php artisan serve` (:8000); terminal 2 `cd frontend && npm run dev` (:5173).
  - Gotchas: browse `http://localhost:5173` (not `127.0.0.1`, or the session cookie won't be sent); the first login forces 2FA enrollment; `php artisan permission:cache-reset` after manual permission edits.
  - Remove the Wayfinder, `public/hot` and `composer run dev` notes.
- **Husky:**
  - `.husky/` stays at the repo root. Its `prepare` script moves to `frontend/package.json` as `"prepare": "cd .. && husky"`, and `husky` becomes a frontend devDependency.
  - Rewrite `.husky/post-merge` to: `(cd backend && composer install --no-interaction && php artisan migrate --force && php artisan db:seed --force && php artisan permission:cache-reset && php artisan optimize:clear)` then `(cd frontend && npm install)`.
  - Remove `wayfinder:generate` and `npm run build`; dev uses the Vite server.
- **`.github/`:** there are **no workflow files** (`.github/workflows/` contains only a stray `.DS_Store`; delete it), so no paths need fixing. `dependabot.yml` tracks only `github-actions` at `/`, so leave it as is. Optionally add `composer` (`/backend`) and `npm` (`/frontend`) ecosystems.
- **Production (doc only, no infra yet):** frontend nginx serves `dist/` and proxies `/api`, `/sanctum` and the QR redirect path `/equipment/*/edit` to the backend. Template: `rwatch/frontend/docker/default.conf.template`.

---

## Phase 7: Cleanup (`frontend` deletes the Vue tree; `devops` handles tooling; `database` handles the last migration)

Only after 4A-4D are merged and QA has signed off:

- Delete `backend/resources/js/`, `backend/resources/css/`, and `backend/resources/views/app.blade.php`. Keep `resources/views/emails/**`.
- Delete `backend/package.json`, `package-lock.json`, `node_modules/`, `vite.config.ts`, `tsconfig.json`, `eslint.config.js`, `components.json`, `.prettierrc`, `.prettierignore`, `.npmrc`, `pnpm-workspace.yaml`, and `public/build/` / `public/hot` if present.
  - rwatch/backend still carries a skeleton `package.json` + `vite.config.js`, but nothing references `@vite` (verified: no `@vite`/`Vite::` usage in its `resources/` or `app/`). It is dead weight, so inventory's backend gets **no** `package.json`.
- Composer: remove `laravel/chisel` if unused, plus anything that Phase 2/3 removals left behind (`composer why-not` check).
- `database`: `drop_audit_logs_table` migration. `down()` recreates the schema; the data comes back only from `activity_log` rows tagged `migrated_from_audit_log_id`.
- Remove `config/reference-data.php` references to Inertia, if any.
- **Repo root `inventory` file:** a tracked 200 KB SQLite database (`git ls-files inventory`). It looks like an accidental commit. `devops`: confirm with the owner, then `git rm` and add it to `.gitignore`.

---

## Phase 8: Sign-off

1. `security`: full review of Phase 2 plus CORS/cookie/CSRF config, the 2FA data migration, the trusted-device permission rule, reCAPTCHA fail-closed in production, `SecurityHeaders` CSP, and the QR redirect (only redirects to `config('app.frontend_url')`, so no open redirect).
2. `qa`: Phase 5 suite, plus a manual walk of every sidebar item as `admin`, `encoder`, `viewer`, `pending`, and an office-scoped user.
3. `reviewer`: final gate.
4. `documentation`:
   - Rewrite `docs/architecture.md` (Stack, Authorization model, Audit logging, Frontend permission gating sections).
   - Turn the `docs/features/*.md` "Inertia prop contract" sections into endpoint contracts from §3a.
   - Retire `docs/features/two-factor-trusted-devices.md` into an updated version describing the rwatch model.
   - Add a `CHANGELOG.md` entry.
