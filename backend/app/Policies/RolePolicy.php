<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AuditLog;
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
     */
    public function viewAny(User $user): bool
    {
        $allowed = $user->hasPermissionTo(Permission::RolesManage);

        if (! $allowed) {
            AuditLog::record('authorization.denied', null, [
                'permission' => Permission::RolesManage->value,
                'user_id' => $user->id,
            ]);
        }

        return $allowed;
    }

    public function view(User $user, Role $role): bool
    {
        $allowed = $user->hasPermissionTo(Permission::RolesManage);

        if (! $allowed) {
            AuditLog::record('authorization.denied', $role, [
                'permission' => Permission::RolesManage->value,
                'user_id' => $user->id,
            ]);
        }

        return $allowed;
    }

    public function create(User $user): bool
    {
        $allowed = $user->hasPermissionTo(Permission::RolesManage);

        if (! $allowed) {
            AuditLog::record('authorization.denied', null, [
                'permission' => Permission::RolesManage->value,
                'user_id' => $user->id,
            ]);
        }

        return $allowed;
    }

    public function update(User $user, Role $role): bool
    {
        $allowed = $user->hasPermissionTo(Permission::RolesManage);

        if (! $allowed) {
            AuditLog::record('authorization.denied', $role, [
                'permission' => Permission::RolesManage->value,
                'user_id' => $user->id,
            ]);
        }

        return $allowed;
    }

    public function delete(User $user, Role $role): bool
    {
        $allowed = $user->hasPermissionTo(Permission::RolesManage);

        if (! $allowed) {
            AuditLog::record('authorization.denied', $role, [
                'permission' => Permission::RolesManage->value,
                'user_id' => $user->id,
            ]);
        }

        return $allowed;
    }
}
