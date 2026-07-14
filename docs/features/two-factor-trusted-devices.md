# Two-Factor "Remember This Device"

## Purpose

Laravel Fortify's stock two-factor authentication (this app's only 2FA implementation — not custom-built) has no built-in way to skip the challenge screen on a device the user has already verified. Users with 2FA enabled were being challenged on every single login, with no way to trust their own machine for a while. This feature adds a "Remember this device for 30 days" checkbox to the challenge screen: checking it skips the 2FA prompt on that same browser/device for 30 days, via a revocable, hashed, per-user token — never a trusted-forever bypass.

There is deliberately **no** trusted-device management UI (no list of devices, no per-device revoke) — this was a scoped decision, not an oversight. If that's ever needed, it layers on top of the existing `two_factor_trusted_devices` table without changing the skip mechanism.

## Architecture

- `App\Models\TwoFactorTrustedDevice` — one row per trusted device: `user_id`, `token_hash` (SHA-256 of a random 60-character token — the raw token is never stored), `expires_at`. Two static helpers are the only entry points: `remember(User $user, Request $request)` (persist a new trusted record + queue the cookie) and `isTrusted(User $user, Request $request)` (check the request's cookie against a non-expired record scoped to that specific user).
- The plaintext token lives only in an `httpOnly`, `secure` (mirroring `config('session.secure')`), `SameSite`-matching-session cookie named `two_factor_remember` — Laravel's `EncryptCookies` middleware (already global on the `web` group) handles encryption/decryption transparently; nothing in this feature touches encryption directly.
- `App\Actions\Fortify\RedirectIfTwoFactorAuthenticatable` — extends Fortify's stock action of the same name, adding one check: if the user would normally be sent to the 2FA challenge, first check `TwoFactorTrustedDevice::isTrusted()`; if trusted, skip the challenge and let the login pipeline continue as if 2FA passed.
- `App\Actions\Fortify\TwoFactorLoginResponse` — extends Fortify's stock response, adding one side effect: if the challenge form's `remember_device` checkbox was checked, call `TwoFactorTrustedDevice::remember()` before delegating to the normal post-login redirect.
- Both are wired in via container binding in `App\Providers\FortifyServiceProvider::register()` (`RedirectsIfTwoFactorAuthenticatable`/`TwoFactorLoginResponse` contracts) — Fortify is extended, never forked. No vendor files are touched, and no Fortify controllers/routes are duplicated.
- `resources/js/pages/auth/TwoFactorChallenge.vue` — a plain `Checkbox` (`name="remember_device"`) added to both the authenticator-code and recovery-code forms, following the exact pattern the Login page already uses for "Remember me" (a native form field the Inertia `<Form>` component picks up on submit — no JS state needed).

## Design decisions

- **Why a DB-backed hashed token instead of a signed-cookie-only check**: a pure signed/encrypted cookie with no DB record would be unrevocable — there'd be no way to invalidate a trusted device early (e.g. if 2FA is disabled/re-enabled, or a device is later suspected compromised) without also touching every trusted browser. The DB record is the source of truth; the cookie is just the credential a legitimate holder presents.
- **Why scoped to `user_id`, not just a bare valid token**: defense in depth. The token itself (60 random characters, hashed) is already infeasible to guess, but scoping the lookup by user means a token can never be replayed against a different account even in a hypothetical collision/leak scenario.
- **Why 30 days**: matches common industry defaults (GitHub/Google-style trust windows) — a deliberate product tradeoff between convenience and requiring periodic re-verification, not a technical constraint.
- **Why extend `RedirectIfTwoFactorAuthenticatable`/`TwoFactorLoginResponse` via container binding instead of `Fortify::authenticateThrough()`**: binding just these two contracts changes exactly the two behaviors this feature needs, without having to reconstruct Fortify's entire login pipeline array (which is also conditional on other config like rate-limiting and username-lowercasing) — smaller, more targeted diff against vendor behavior.

## Files

- `database/migrations/2026_07_14_030156_create_two_factor_trusted_devices_table.php`
- `app/Models/TwoFactorTrustedDevice.php`, `app/Models/User.php` (`twoFactorTrustedDevices()` relation)
- `app/Actions/Fortify/RedirectIfTwoFactorAuthenticatable.php`, `app/Actions/Fortify/TwoFactorLoginResponse.php`
- `app/Providers/FortifyServiceProvider.php` (bindings)
- `resources/js/pages/auth/TwoFactorChallenge.vue`

## Open follow-ups

- No trusted-device list/revoke UI (scoped out — see Purpose).
- No scheduled cleanup of expired rows — they're excluded from `isTrusted()`'s query by the `expires_at > now()` filter regardless, so expired-and-unused rows are inert, not a correctness risk; a pruning command is an easy follow-up if the table's size ever becomes a concern.
