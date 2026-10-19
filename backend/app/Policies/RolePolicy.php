<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;

/**
 * Authorization only — "does this user hold roles.manage" — mirroring
 * EquipmentPolicy's shape exactly. The *business-rule* invariants around
 * editing/deleting a specific role (protected 'pending' role, "role still
 * in use" deletion block, self-escalation/last-holder lockout on
 * permission edits) are NOT authorization decisions in the Policy sense —
 * they don't depend on who's asking, only on what's being asked — so they
 * live in RoleService as ValidationException-raising invariants, the same
 * split UserRoleService already established for user role assignment
 * (Policy/Gate = "can this actor act at all", Service = "would this
 * specific mutation violate an invariant").
 */
class RolePolicy
{
    /**
     * Role management is the most sensitive admin surface in the app (it
     * controls who can grant/revoke every other permission), so every
     * denial — not just delete — is logged for the privilege-escalation
     * forensic trail, matching EquipmentPolicy::delete's shape.
     *
     * Reading roles is also open to users.manage holders, who assign them
     * (read-only widening for the SPA's user editor, approved by auth).
     */
    public function viewAny(User $user): bool
    {
        $allowed = $user->hasAnyPermission([Permission::RolesManage, Permission::UsersManage]);

        if (! $allowed) {
            activity()
                ->causedBy($user)
                ->event('authorization.denied')
                ->withProperties(['permission' => Permission::RolesManage->value])
                ->log('authorization.denied');
        }

        return $allowed;
    }

    public function view(User $user, Role $role): bool
    {
        $allowed = $user->hasAnyPermission([Permission::RolesManage, Permission::UsersManage]);

        if (! $allowed) {
            activity()
                ->causedBy($user)
                ->performedOn($role)
                ->event('authorization.denied')
                ->withProperties(['permission' => Permission::RolesManage->value])
                ->log('authorization.denied');
        }

        return $allowed;
    }

    public function create(User $user): bool
    {
        $allowed = $user->hasPermissionTo(Permission::RolesManage);

        if (! $allowed) {
            activity()
                ->causedBy($user)
                ->event('authorization.denied')
                ->withProperties(['permission' => Permission::RolesManage->value])
                ->log('authorization.denied');
        }

        return $allowed;
    }

    public function update(User $user, Role $role): bool
    {
        $allowed = $user->hasPermissionTo(Permission::RolesManage);

        if (! $allowed) {
            activity()
                ->causedBy($user)
                ->performedOn($role)
                ->event('authorization.denied')
                ->withProperties(['permission' => Permission::RolesManage->value])
                ->log('authorization.denied');
        }

        return $allowed;
    }

    public function delete(User $user, Role $role): bool
    {
        $allowed = $user->hasPermissionTo(Permission::RolesManage);

        if (! $allowed) {
            activity()
                ->causedBy($user)
                ->performedOn($role)
                ->event('authorization.denied')
                ->withProperties(['permission' => Permission::RolesManage->value])
                ->log('authorization.denied');
        }

        return $allowed;
    }
}
