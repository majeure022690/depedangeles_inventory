<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * "Can this actor manage accounts at all". The account-specific
 * invariants (self-escalation, last users.manage holder, permission-tier
 * separation) live in UserRoleService, the same Policy/Service split
 * RolePolicy/RoleService use.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::UsersManage);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permission::UsersManage);
    }

    public function update(User $user, User $target): bool
    {
        return $user->hasPermissionTo(Permission::UsersManage);
    }

    public function delete(User $user, User $target): bool
    {
        return $user->hasPermissionTo(Permission::UsersManage) && ! $user->is($target);
    }

    public function resetTwoFactor(User $user, User $target): bool
    {
        return $user->hasPermissionTo(Permission::UsersManage) && ! $user->is($target);
    }
}
