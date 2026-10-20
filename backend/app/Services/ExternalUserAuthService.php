<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class ExternalUserAuthService
{
    public const ACTIVE_STATUS = 'Active';

    // Throws when PRIME knows the user but refuses them, so the caller never falls back to the local password copy.
    public function authenticate(string $email, string $password): ?User
    {
        $primeUser = self::users()->where('email_address', $email)->first();

        if (! $primeUser) {
            return null;
        }

        $localUser = User::where('email', $primeUser->email_address)->first();

        if ($primeUser->user_status !== self::ACTIVE_STATUS) {
            $localUser?->update(['is_active' => false]);

            throw new InvalidPrimeCredentials;
        }

        if (! Hash::check($password, $primeUser->password)) {
            throw new InvalidPrimeCredentials;
        }

        $profile = [
            'name' => $primeUser->full_name,
            'password' => $primeUser->password,
        ];

        if (! $localUser) {
            return User::create([
                ...$profile,
                'email' => $primeUser->email_address,
                'is_active' => true,
            ]);
        }

        $localUser->update($profile);

        return $localUser;
    }

    public static function users(): Builder
    {
        return DB::connection('external_users')->table('users');
    }

    public function testConnection(): bool
    {
        try {
            DB::connection('external_users')->getPdo();

            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
