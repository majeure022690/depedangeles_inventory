<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Personnel;
use App\Models\User;

class PersonnelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::PersonnelView);
    }

    public function view(User $user, Personnel $personnel): bool
    {
        return $user->hasPermissionTo(Permission::PersonnelView);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permission::PersonnelCreate);
    }

    public function update(User $user, Personnel $personnel): bool
    {
        return $user->hasPermissionTo(Permission::PersonnelEdit);
    }

    /**
     * Deletion is the most consequential Personnel action (it removes a
     * record other tables' nullable FKs may still reference historically)
     * so a denial here is logged, not just silently 403'd.
     */
    public function delete(User $user, Personnel $personnel): bool
    {
        $allowed = $user->hasPermissionTo(Permission::PersonnelDelete);

        if (! $allowed) {
            AuditLog::record('authorization.denied', $personnel, [
                'permission' => Permission::PersonnelDelete->value,
                'user_id' => $user->id,
            ]);
        }

        return $allowed;
    }

    public function restore(User $user, Personnel $personnel): bool
    {
        return false;
    }

    public function forceDelete(User $user, Personnel $personnel): bool
    {
        return false;
    }
}
