<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\StakeholderProfile;
use App\Models\User;

/**
 * ONE ROW PER OFFICE (see App\Models\StakeholderProfile) — no create/delete
 * action to authorize (no `store()` making additional rows, no
 * `destroy()`), so this Policy has view/update plus viewAny for the
 * cross-office admin list.
 *
 * `.view`/`.edit` are scoped to the acting user's OWN office_id — an
 * encoder/viewer can only read/write the profile matching the school they
 * belong to, never another school's, even though the permission name
 * itself isn't office-specific. `.view_all` bypasses that scoping entirely
 * (division-level oversight, e.g. the admin role) and additionally gates
 * the admin list (viewAny()).
 */
class StakeholderProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::StakeholderProfileViewAll);
    }

    public function view(User $user, StakeholderProfile $stakeholderProfile): bool
    {
        if ($user->hasPermissionTo(Permission::StakeholderProfileViewAll)) {
            return true;
        }

        return $user->hasPermissionTo(Permission::StakeholderProfileView)
            && $user->office_id !== null
            && $user->office_id === $stakeholderProfile->office_id;
    }

    public function update(User $user, StakeholderProfile $stakeholderProfile): bool
    {
        if ($user->hasPermissionTo(Permission::StakeholderProfileViewAll)) {
            return true;
        }

        return $user->hasPermissionTo(Permission::StakeholderProfileEdit)
            && $user->office_id !== null
            && $user->office_id === $stakeholderProfile->office_id;
    }
}
