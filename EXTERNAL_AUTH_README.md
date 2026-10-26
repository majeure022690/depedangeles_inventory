# External User Authentication

Accounts and passwords come from PRIME, the division's central user system, read over a read-only database connection (`external_users`). Full detail: [`docs/architecture.md`](docs/architecture.md#password-authority-prime) (sign-in) and [`docs/features/roles-and-permissions.md`](docs/features/roles-and-permissions.md#users) (the Users list).

## How It Works

1. **Users sign in with their PRIME email and password.** PRIME is the only password authority: IRIS checks the password against PRIME and never stores a copy of it.
2. **Name and deactivation come from PRIME**, refreshed on sign-in, on session load (`GET /api/user`), on every Users list page, and by `php artisan users:sync-external`. Office and employee number are refreshed on session load and by `users:sync-external`.
3. **Users change their password in PRIME.** IRIS has no password change or reset.
4. **Roles and permissions are managed in IRIS only.** Admins assign roles here.
5. **Profile editing is avatar-only.** Name and email are shown read-only.

## Configuration

Update `backend/.env` with the PRIME database credentials:

```env
EXTERNAL_DB_HOST=your_host
EXTERNAL_DB_PORT=3306
EXTERNAL_DB_DATABASE=your_database
EXTERNAL_DB_USERNAME=readonly_user
EXTERNAL_DB_PASSWORD=readonly_password

# Developer break-glass accounts only; keep empty in production.
LOCAL_LOGIN_EMAILS=
```

The connection has a 5 s connect timeout (it bounds connecting only, not a slow query). "PRIME unreachable" means a connection failure only. After one, IRIS stops contacting PRIME for 30 s (cache flag `prime.unreachable`); `users:sync-external` always tries anyway. Other PRIME query errors (collation, bad data, a missing column) are treated as a refusal at sign-in (no break-glass) and leave the local copy in place for syncs.

IRIS reads PRIME's `users` table (`email_address`, `full_name`, `user_status`, `password`, `user_id`, `department_school_id`) in `app/Services/ExternalUserAuthService.php` and `app/Services/PrimeUserSync.php`. Only `user_status = 'Active'` accounts can sign in. Two PRIME rows with the same email (case-insensitive) are refused at sign-in and skipped by every sync, with a security-log entry. PRIME's owners should index `users.email_address` and `users.user_id`; every sign-in and Users list load filters on them.

## Test Connection

```bash
php artisan tinker
app(\App\Services\ExternalUserAuthService::class)->testConnection()
```

## Break-glass local login

Emails listed in `LOCAL_LOGIN_EMAILS` (comma-separated) may also sign in with a local IRIS password, but only when PRIME is unreachable or doesn't know the email. A PRIME refusal (wrong password, inactive) never falls back. Every use is logged as `Local break-glass login` on the `security` log channel, and 2FA is still required. Set the password with `php artisan users:set-password <email>`; it refuses any email not on the list. Production keeps the list empty.

## Users Table

The local `users` table is a shadow of PRIME:

- Stored in IRIS: roles, 2FA data, trusted devices, avatar, and a local `is_active` (deactivate here to block someone PRIME still lists as active).
- From PRIME: name, email, deactivation, employee number, office.
- `password` is empty for every account except break-glass accounts.

Deactivation in PRIME carries over to IRIS. Reactivation doesn't: an account deactivated in IRIS stays inactive until an admin reactivates it in `/users`.

## Admin Workflow

1. A new active PRIME account appears in the Users list on its own (imported on the next list load), or when the person first signs in. With no role yet, sign-in is refused with a 403 "Your account doesn't have access to DICTIS yet" notice (see `docs/architecture.md`, "No-access refusal").
2. An admin assigns a role in `/users`. Office and employee number come from PRIME and can't be changed there.
3. The user signs in again, enrolls in 2FA, and can use the system.
