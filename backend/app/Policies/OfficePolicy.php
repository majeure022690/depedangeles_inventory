<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Office;
use App\Models\User;

class OfficePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::OfficeView);
    }

    public function view(User $user, Office $office): bool
    {
        return $user->hasPermissionTo(Permission::OfficeView);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permission::OfficeCreate);
    }

    public function update(User $user, Office $office): bool
    {
        return $user->hasPermissionTo(Permission::OfficeEdit);
    }

    /**
     * Deletion is the most consequential Office action (it's the FK
     * backbone for users/stakeholder_profiles/internet_connectivity_surveys),
     * so a denial here is logged, not just silently 403'd.
     */
    public function delete(User $user, Office $office): bool
    {
        $allowed = $user->hasPermissionTo(Permission::OfficeDelete);

        if (! $allowed) {
            activity()
                ->causedBy($user)
                ->performedOn($office)
                ->event('authorization.denied')
                ->withProperties(['permission' => Permission::OfficeDelete->value])
                ->log('authorization.denied');
        }

        return $allowed;
    }

    public function restore(User $user, Office $office): bool
    {
        return false;
    }

    public function forceDelete(User $user, Office $office): bool
    {
        return false;
    }
}
