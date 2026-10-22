# Two-Factor Authentication & Trusted Devices

## Purpose

TOTP two-factor authentication is **mandatory for every account**, ported from rwatch (pragmarx/google2fa). A password alone never produces a session: a user without 2FA is sent through enrollment at login. "Trust this device" lets a user skip the code on a browser they have already verified, for a configurable number of days, except holders of high-impact permissions (access changes, security settings, audit and email logs), who always need the code.

This replaced the Fortify-based optional 2FA and its "remember this device for 30 days" extension (2026-07-14). Migration `2026_10_19_000008` re-encoded confirmed Fortify secrets and recovery codes into the format this code reads, so enrolled users kept their authenticator; unconfirmed enrollments were cleared, and every previously trusted device was forgotten once (`2026_10_19_000007` replaced the table and cookie). The login sequence itself is in [`docs/architecture.md`](../architecture.md#authentication-flow).

## Architecture

- `Api\Auth\TwoFactorController` — `setup`, `confirmSetup`, `verify` (pre-session, keyed by a short-lived cache token from `/api/login`), `regenerateRecoveryCodes`.
- `Api\Auth\TrustedDeviceController` — list and revoke the current user's trusted devices.
- `App\Services\TwoFactorAuthService` — secrets, QR data URI (issuer `DICTIS`), code verification with replay protection (`verifyCodeOnce()` remembers the last accepted time step per user, so a code can't be reused within its window), 8 recovery codes stored hashed.
- `App\Services\TrustedDeviceService` — issues, finds, touches and revokes devices; `days()` decides whether trusting is allowed.
- `App\Models\TrustedDevice` (`trusted_devices`: `token_hash` (SHA-256), `user_agent`, `ip_address`, `last_used_ip`, `last_used_at`, `expires_at`).
- User columns `two_factor_secret` (`encrypted` cast), `two_factor_recovery_codes` (`encrypted:array` of hashes), `two_factor_confirmed_at`. Never logged to activity.

## API endpoints

Pre-session (throttle `two-factor-verify`, keyed by the pending user):

| Method + path | Body | Response |
|---|---|---|
| `POST /api/2fa/setup` | `setup_token` | `{data: {qr_code, secret}}`; secret held in cache 10 min |
| `POST /api/2fa/setup/confirm` | `setup_token`, `code` | `UserResource` envelope (now logged in) plus `data.recovery_codes` — shown once |
| `POST /api/2fa/verify` | `two_fa_token`, `code` **or** `recovery_code`, optional `remember_device` | `UserResource` envelope; `warning` when ≤ 2 recovery codes remain; sets the `dictis_trusted_device` cookie when `remember_device` and trusting is allowed |

All three return **403 `{code: "no_access", message}`** instead if the pending user now holds no permissions, and discard the setup/2FA token so the user must restart at login. See [No-access refusal](../architecture.md#no-access-refusal).

Authenticated:

| Method + path | Body | Response |
|---|---|---|
| `POST /api/2fa/recovery-codes/regenerate` (throttle `password-confirm`) | `current_password` | `{data: {recovery_codes}}` — replaces all previous codes |
| `GET /api/trusted-devices` | — | `{data: [{id, label, ip_address, trusted_at, last_used_at, expires_at, is_current, is_honoured}], meta: {trust_device_days}}` — see below |
| `DELETE /api/trusted-devices/{id}` | — | revokes one (honoured or not); clears the cookie if it was this browser |
| `DELETE /api/trusted-devices` | — | revokes all, clears the cookie |
| `POST /api/users/{id}/2fa/reset` (`users.reset_two_factor`) | admin's `current_password` | clears the user's 2FA so they re-enroll at next login — see [roles-and-permissions.md](roles-and-permissions.md#users) |

`GET /api/trusted-devices` lists every device whose stored `expires_at` hasn't passed, including ones not currently honoured (`is_honoured: false`): devices older than the current `trusted_device_days` cap, every device when the setting is 0, and every device when the user holds an `ALWAYS_REQUIRE_CODE` permission. `expires_at` is the effective expiry: for an honoured device, the earlier of its stored `expires_at` and `created_at + trusted_device_days`; for a non-honoured device, its stored `expires_at`, i.e. the latest date it could become valid again. `meta.trust_device_days` is the value `days()` returns for this user, so it is 0 for users who always need the code. The card on `/profile` marks non-honoured rows "Not currently trusted" and shows "Sign out all devices" whenever any row exists.

There is deliberately **no "disable 2FA"** endpoint. A user who loses their authenticator uses a recovery code or asks an admin to reset it.

## Frontend

- `pages/management/TwoFactorSetup.tsx` (`/2fa/setup`), `TwoFactorVerify.tsx` (`/2fa/verify`, with the "trust this device" checkbox when `trust_device_days > 0`).
- `pages/management/ProfileSettings.tsx` (`/profile`) — recovery-code regeneration and `components/TrustedDevicesCard.tsx`.
- `services/twoFactorService.ts`, `services/trustedDeviceService.ts`; `components/ui/OtpInput.tsx`.

## Design decisions

- **Mandatory, not opt-in.** The data is DepEd staff PII and asset records; rwatch's model was adopted wholesale rather than keeping optional 2FA.
- **Trust is a DB-backed hashed token, not a bare signed cookie.** The cookie (`httpOnly`, `secure` per session config) holds a random 64-char token; only its SHA-256 is stored, scoped to the user. The DB row is the source of truth, so a device can be revoked individually, and all of a user's devices are revoked automatically when their password or 2FA secret changes or the account is deactivated (`User::booted()`).
- **No trust for high-impact permissions.** `TrustedDeviceService::days()` returns 0, so the code is always required, for anyone holding a permission in `TrustedDeviceService::ALWAYS_REQUIRE_CODE`. That constant is the list to edit. It covers three kinds of permission. Some change anyone's access (`roles.create`, `roles.edit`, `roles.delete`, `users.edit`, `users.reset_two_factor`). One weakens sign-in controls (`security_settings.manage`, which could itself lengthen or disable device trust). Others read or erase the record of what happened (`audit_log.view`, `email_log.manage`). A stolen trusted-device cookie plus a password must not be enough for any of these. rwatch keys this on role names; inventory keys it on permissions, per the no-role-name-checks rule. When you add a permission of the same kind, add it to the constant and to the `trusted_device_days` description in `SecuritySettings`.
- **Trust window is a setting**, `trusted_device_days` (0-30, default 30, 0 disables the option), edited at `/settings` (`security_settings.manage`). It applies to devices that are already trusted, not just new ones: `findValid()` accepts a device only while it is younger than the *current* setting **and** before its own `expires_at`. Lowering the setting therefore cuts existing trust short straight away, which is what an admin responding to an incident expects. Raising it never lengthens an existing device's trust, because `expires_at` was fixed when the device was trusted. Setting it to 0 stops all trusted devices from being honoured. Non-honoured devices are still listed and revocable because they can become valid again (the setting is raised back, or the user loses the permission that forces the code) until their stored `expires_at`, so a user must be able to kill them now rather than wait.
- **Recovery codes are case-insensitive.** Input is upper-cased before `Hash::check`; the Fortify migration upper-cased the old mixed-case codes before hashing so they kept working.

## Open follow-ups

- Expired `trusted_devices` rows are pruned only for the user who trusts a new device; they're inert (filtered by `expires_at`), so a scheduled prune is optional.
