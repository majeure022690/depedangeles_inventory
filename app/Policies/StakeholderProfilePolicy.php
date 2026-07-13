<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\StakeholderProfile;
use App\Models\User;

/**
 * SINGLETON resource (see App\Models\StakeholderProfile) — exactly one row
 * ever exists, fetched/created by Backend via
 * `StakeholderProfile::firstOrCreate([])`. There is no create/delete
 * action to authorize (no `store()` making additional rows, no
 * `destroy()`), so this Policy deliberately has only `view`/`update`,
 * unlike the multi-record Policies (PersonnelPolicy, EquipmentPolicy,
 * IspAccountPolicy) that also authorize create/delete/restore/
 * forceDelete.
 */
class StakeholderProfilePolicy
{
    public function view(User $user, StakeholderProfile $stakeholderProfile): bool
    {
        return $user->hasPermissionTo(Permission::StakeholderProfileView);
    }

    public function update(User $user, StakeholderProfile $stakeholderProfile): bool
    {
        return $user->hasPermissionTo(Permission::StakeholderProfileEdit);
    }
}
