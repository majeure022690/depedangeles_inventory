<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Service to provide a combined view of local and external users.
 */
final class CombinedUserService
{
    /**
     * Get all users from both local and external databases.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getAllUsers(): Collection
    {
        $localUsers = User::with(['roles:id,name,label', 'office:id,office_name,school_id'])
            ->get()
            ->map(fn (User $user) => $this->localRow($user))
            ->toBase();

        $localEmails = $localUsers->pluck('email')->all();

        // Get external users not yet synced
        $externalUsers = ExternalUserAuthService::users()
            ->whereNotIn('email_address', $localEmails)
            ->get()
            ->map(fn (stdClass $externalUser) => $this->externalRow($externalUser));

        return $localUsers->concat($externalUsers);
    }

    /**
     * Search users by name or email across both databases.
     *
     * @return Collection<int, array<string, mixed>>
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

    /**
     * @return array<string, mixed>
     */
    private function localRow(User $user): array
    {
        $role = $user->roles->first();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'office' => $user->office,
            'is_active' => $user->is_active,
            'role' => $role ? [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label,
            ] : null,
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'source' => 'local',
            'synced' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function externalRow(stdClass $externalUser): array
    {
        return [
            'id' => null,
            'name' => $externalUser->full_name,
            'email' => $externalUser->email_address,
            'office' => null,
            'is_active' => false,  // Not active until they log in
            'role' => null,
            'two_factor_enabled' => false,
            'source' => 'external',
            'synced' => false,
        ];
    }
}
