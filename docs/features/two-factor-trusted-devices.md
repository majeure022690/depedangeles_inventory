# Two-Factor Authentication & Trusted Devices

## Purpose

TOTP two-factor authentication is **mandatory for every account**, ported from rwatch (pragmarx/google2fa). A password alone never produces a session: a user without 2FA is sent through enrollment at login. "Trust this device" lets a user skip the code on a browser they have already verified, for a configurable number of days, except holders of the access-changing permissions, who always need the code.

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

Authenticated:

| Method + path | Body | Response |
|---|---|---|
| `POST /api/2fa/recovery-codes/regenerate` (throttle `password-confirm`) | `current_password` | `{data: {recovery_codes}}` — replaces all previous codes |
| `GET /api/trusted-devices` | — | `{data: [{id, label, ip_address, trusted_at, last_used_at, expires_at, is_current}], meta: {trust_device_days}}` (unexpired only) |
| `DELETE /api/trusted-devices/{id}` | — | revokes one; clears the cookie if it was this browser |
| `DELETE /api/trusted-devices` | — | revokes all, clears the cookie |
| `POST /api/users/{id}/2fa/reset` (`users.manage`) | admin's `current_password` | clears the user's 2FA so they re-enroll at next login — see [roles-and-permissions.md](roles-and-permissions.md#users) |

There is deliberately **no "disable 2FA"** endpoint. A user who loses their authenticator uses a recovery code or asks an admin to reset it.

## Frontend

- `pages/management/TwoFactorSetup.tsx` (`/2fa/setup`), `TwoFactorVerify.tsx` (`/2fa/verify`, with the "trust this device" checkbox when `trust_device_days > 0`).
- `pages/management/ProfileSettings.tsx` (`/profile`) — recovery-code regeneration and `components/TrustedDevicesCard.tsx`.
- `services/twoFactorService.ts`, `services/trustedDeviceService.ts`; `components/ui/OtpInput.tsx`.

## Design decisions

- **Mandatory, not opt-in.** The data is DepEd staff PII and asset records; rwatch's model was adopted wholesale rather than keeping optional 2FA.
- **Trust is a DB-backed hashed token, not a bare signed cookie.** The cookie (`httpOnly`, `secure` per session config) holds a random 64-char token; only its SHA-256 is stored, scoped to the user. The DB row is the source of truth, so a device can be revoked individually, and all of a user's devices are revoked automatically when their password or 2FA secret changes or the account is deactivated (`User::booted()`).
- **No trust for the access-changing permissions.** `TrustedDeviceService::days()` returns 0 for anyone holding `roles.manage` or `users.manage`: those accounts can grant access to everything else, so their code is always required. rwatch keys this on role names; inventory keys it on permissions, per the no-role-name-checks rule.
- **Trust window is a setting**, `trusted_device_days` (0-30, default 30, 0 disables the option), edited at `/settings` (`security_settings.manage`).
- **Recovery codes are case-insensitive.** Input is upper-cased before `Hash::check`; the Fortify migration upper-cased the old mixed-case codes before hashing so they kept working.

## Open follow-ups

- Expired `trusted_devices` rows are pruned only for the user who trusts a new device; they're inert (filtered by `expires_at`), so a scheduled prune is optional.
