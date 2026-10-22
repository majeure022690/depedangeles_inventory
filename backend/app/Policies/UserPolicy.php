<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Services\UserRoleService;

/**
 * "Can this actor manage this account". Account-specific actions also
 * require the target-tier rule (the target's effective permissions are a
 * subset of the actor's own), which UserRoleService re-checks as a
 * backstop alongside its other invariants (self-lockout, last users.edit
 * holder, permission-tier separation on role assignment).
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::UsersView);
    }

    public function create(User $user): bool
    {
        // User creation is disabled - all users are synced from PRIME
        return false;
    }

    public function update(User $user, User $target): bool
    {
        return $user->hasPermissionTo(Permission::UsersEdit) && $this->withinTier($user, $target);
    }

    public function delete(User $user, User $target): bool
    {
        // User deletion is disabled - all users are synced from PRIME
        return false;
    }

    public function resetTwoFactor(User $user, User $target): bool
    {
        return $user->hasPermissionTo(Permission::UsersResetTwoFactor) && ! $user->is($target) && $this->withinTier($user, $target);
    }

    private function withinTier(User $user, User $target): bool
    {
        return app(UserRoleService::class)->canManage($user, $target);
    }
}
