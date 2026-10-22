# External User Authentication

This system uses centralized user management from an external database.

## How It Works

1. **Users login with credentials from the main system**
2. **User data (name, email, office) automatically syncs from external DB on login**
3. **Passwords are verified against external DB** - users change passwords in main system
4. **Roles & permissions managed locally** - admins assign roles in this app only
5. **Profile editing disabled** - users must update profile in main system

## Configuration

Update `.env` with external database credentials:

```env
EXTERNAL_DB_HOST=your_host
EXTERNAL_DB_PORT=3306
EXTERNAL_DB_DATABASE=your_database
EXTERNAL_DB_USERNAME=readonly_user
EXTERNAL_DB_PASSWORD=readonly_password
```

## Update External DB Table/Column Names

Edit `app/Services/ExternalUserAuthService.php` line 29-31:

```php
->table('users')  // Your table name
->where('username', $username)  // Your username column
// ->orWhere('email', $username)  // Uncomment for email login
```

Also update the field mapping at lines 40-45 to match your external DB structure:
- `$externalUser->name` 
- `$externalUser->email`
- `$externalUser->password`
- `$externalUser->office_id`

## Test Connection

```bash
php artisan tinker
app(\App\Services\ExternalUserAuthService::class)->testConnection()
```

## Users Table

The local `users` table acts as a cache/shadow:
- Stores: roles, permissions, 2FA data, local is_active override
- Auto-synced: name, email, password, office_id (from external DB)

## Admin Workflow

1. User from main system logs in for first time. With no role yet, sign-in is refused with a 403 "Your account doesn't have access to DICTIS yet" notice (see `docs/architecture.md`, "No-access refusal")
2. User appears in Users list automatically (the local record is created/synced even though sign-in was refused)
3. Admin assigns role & verifies office
4. User signs in again, enrolls in 2FA, and can now access inventory system features

## Frontend Changes Needed

Disable profile editing in the UI:
- Remove/disable name and email fields
- Show message: "Profile data is managed by PRIME"
- Keep avatar upload (local only)
- Keep 2FA management (local only)
