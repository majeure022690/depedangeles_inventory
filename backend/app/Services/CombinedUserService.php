<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service to provide a combined view of local and external users.
 */
final class CombinedUserService
{
    /**
     * Get all users from both local and external databases.
     * 
     * @return Collection Combined collection with local and external users
     */
    public function getAllUsers(): Collection
    {
        // Get local users
        $localUsers = User::with(['roles:id,name,label', 'office:id,office_name,school_id'])
            ->get()
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'office' => $user->office,
                    'is_active' => $user->is_active,
                    'role' => $user->roles->first() ? [
                        'id' => $user->roles->first()->id,
                        'name' => $user->roles->first()->name,
                        'label' => $user->roles->first()->label,
                    ] : null,
                    'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                    'source' => 'local',
                    'synced' => true,
                ];
            });

        $localEmails = $localUsers->pluck('email')->all();

        // Get external users not yet synced
        $externalUsers = DB::connection('external_users')
            ->table('users')  // Change to your table name
            ->whereNotIn('email', $localEmails)
            ->get()
            ->map(function ($externalUser) {
                return [
                    'id' => null,
                    'name' => $externalUser->name ?? $externalUser->username,
                    'email' => $externalUser->email,
                    'office' => null,
                    'is_active' => false,  // Not active until they log in
                    'role' => null,
                    'two_factor_enabled' => false,
                    'source' => 'external',
                    'synced' => false,
                ];
            });

        return $localUsers->concat($externalUsers);
    }

    /**
     * Search users by name or email across both databases.
     */
    public function searchUsers(string $search): Collection
    {
        $search = trim($search);
        if ($search === '') {
            return $this->getAllUsers();
        }

        $allUsers = $this->getAllUsers();

        return $allUsers->filter(function ($user) use ($search) {
            return stripos($user['name'], $search) !== false 
                || stripos($user['email'], $search) !== false;
        })->values();
    }
}
