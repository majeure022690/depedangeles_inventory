<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\IspAccount;
use App\Models\User;

class IspAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::IspAccountsView);
    }

    public function view(User $user, IspAccount $ispAccount): bool
    {
        return $user->hasPermissionTo(Permission::IspAccountsView);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permission::IspAccountsCreate);
    }

    /**
     * Also the authorization gate for logging an IspSpeedTest or
     * IspSubscriptionCost row against this account — see the doc-comment
     * on Permission::IspAccountsEdit for why those nested logs don't get
     * their own permission. Backend calls
     * `$user->can('update', $ispAccount)` from the speed-test/
     * subscription-cost store actions, the same way equipment
     * transactions authorize against the parent Equipment.
     */
    public function update(User $user, IspAccount $ispAccount): bool
    {
        return $user->hasPermissionTo(Permission::IspAccountsEdit);
    }

    /**
     * Deletion is the most consequential IspAccount action (a government
     * budget/procurement record), so a denial here is logged, not just
     * silently 403'd.
     */
    public function delete(User $user, IspAccount $ispAccount): bool
    {
        $allowed = $user->hasPermissionTo(Permission::IspAccountsDelete);

        if (! $allowed) {
            AuditLog::record('authorization.denied', $ispAccount, [
                'permission' => Permission::IspAccountsDelete->value,
                'user_id' => $user->id,
            ]);
        }

        return $allowed;
    }

    public function restore(User $user, IspAccount $ispAccount): bool
    {
        return false;
    }

    public function forceDelete(User $user, IspAccount $ispAccount): bool
    {
        return false;
    }
}
