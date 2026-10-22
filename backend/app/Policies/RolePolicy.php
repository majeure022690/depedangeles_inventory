<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;

/**
 * Whether the actor holds the matching roles.* permission. Invariants on
 * a specific mutation (protected Super Administrator role, no deleting an
 * assigned role, lockout guards) live in RoleService.
 */
class RolePolicy
{
    /**
     * Every denial is logged: this screen controls who can grant every
     * other permission. Reading is also open to users.edit holders, who
     * need the role list to assign roles.
     */
    public function viewAny(User $user): bool
    {
        $allowed = $user->hasAnyPermission([Permission::RolesView, Permission::UsersEdit]);

        if (! $allowed) {
            activity()
                ->causedBy($user)
                ->event('authorization.denied')
                ->withProperties(['permission' => Permission::RolesView->value])
                ->log('authorization.denied');
        }

        return $allowed;
    }

    public function view(User $user, Role $role): bool
    {
        $allowed = $user->hasAnyPermission([Permission::RolesView, Permission::UsersEdit]);

        if (! $allowed) {
            activity()
                ->causedBy($user)
                ->performedOn($role)
                ->event('authorization.denied')
                ->withProperties(['permission' => Permission::RolesView->value])
                ->log('authorization.denied');
        }

        return $allowed;
    }

    public function create(User $user): bool
    {
        $allowed = $user->hasPermissionTo(Permission::RolesCreate);

        if (! $allowed) {
            activity()
                ->causedBy($user)
                ->event('authorization.denied')
                ->withProperties(['permission' => Permission::RolesCreate->value])
                ->log('authorization.denied');
        }

        return $allowed;
    }

    public function update(User $user, Role $role): bool
    {
        $allowed = $user->hasPermissionTo(Permission::RolesEdit);

        if (! $allowed) {
            activity()
                ->causedBy($user)
                ->performedOn($role)
                ->event('authorization.denied')
                ->withProperties(['permission' => Permission::RolesEdit->value])
                ->log('authorization.denied');
        }

        return $allowed;
    }

    public function delete(User $user, Role $role): bool
    {
        $allowed = $user->hasPermissionTo(Permission::RolesDelete);

        if (! $allowed) {
            activity()
                ->causedBy($user)
                ->performedOn($role)
                ->event('authorization.denied')
                ->withProperties(['permission' => Permission::RolesDelete->value])
                ->log('authorization.denied');
        }

        return $allowed;
    }
}
