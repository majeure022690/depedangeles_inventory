<?php

namespace App\Policies;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\StakeholderProfile;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class StakeholderProfilePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermissionTo(Permission::StakeholderProfileView) && $user->dataScope() === DataScope::All
            ? Response::allow()
            : Response::deny();
    }

    public function view(User $user, StakeholderProfile $stakeholderProfile): Response
    {
        return $this->permittedAndReached($user, $stakeholderProfile, Permission::StakeholderProfileView);
    }

    public function update(User $user, StakeholderProfile $stakeholderProfile): Response
    {
        return $this->permittedAndReached($user, $stakeholderProfile, Permission::StakeholderProfileEdit);
    }

    private function permittedAndReached(User $user, StakeholderProfile $stakeholderProfile, Permission $permission): Response
    {
        if (! $user->hasPermissionTo($permission)) {
            return Response::deny();
        }

        return $user->reachesOffice($stakeholderProfile->office_id) ? Response::allow() : Response::denyAsNotFound();
    }
}
