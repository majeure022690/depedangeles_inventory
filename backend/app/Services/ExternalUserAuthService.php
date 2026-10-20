<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Authenticates users against an external database's users table while
 * managing roles and permissions locally in this application's database.
 */
final class ExternalUserAuthService
{
    /**
     * Check if credentials match in the external database and sync to local.
     * Auto-syncs user data from external DB to keep local copy up-to-date.
     * 
     * @param string $username Username/email from login form
     * @param string $password Plain text password to verify
     * @return User|null Local user if credentials valid, null otherwise
     */
    public function authenticate(string $username, string $password): ?User
    {
        // Query the external database for the user
        $externalUser = DB::connection('external_users')
            ->table('users')  // Change 'users' to your actual table name
            ->where('username', $username)  // Change 'username' to your actual column
            // ->orWhere('email', $username)  // Uncomment if you support email login
            ->first();

        if (!$externalUser) {
            return null;
        }

        // Verify the password against the external database's hash
        if (!Hash::check($password, $externalUser->password)) {
            return null;
        }

        // Sync to local database: always update from external source of truth
        $localUser = User::where('email', $externalUser->email)->first();

        if (!$localUser) {
            // Create new local user (admin assigns role later)
            $localUser = User::create([
                'name' => $externalUser->name ?? $externalUser->username,
                'email' => $externalUser->email,
                'password' => $externalUser->password,
                'is_active' => true,
                'office_id' => $externalUser->office_id ?? null,
            ]);
        } else {
            // ALWAYS sync from external DB (source of truth for profile data)
            $localUser->update([
                'name' => $externalUser->name ?? $externalUser->username,
                'password' => $externalUser->password, // Keep password in sync
                'office_id' => $externalUser->office_id ?? null,
            ]);
        }

        return $localUser;
    }

    /**
     * Test the external database connection.
     * 
     * @return bool True if connection successful
     */
    public function testConnection(): bool
    {
        try {
            DB::connection('external_users')->getPdo();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
