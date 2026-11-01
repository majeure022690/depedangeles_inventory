<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\GspIctProfile;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class GspIctProfilePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermissionTo(Permission::GspProfilesView) ? Response::allow() : Response::deny();
    }

    public function view(User $user, GspIctProfile $profile): Response
    {
        return $this->permittedAndVisible($user, $profile, Permission::GspProfilesView);
    }

    public function create(User $user): Response
    {
        return $user->hasPermissionTo(Permission::GspProfilesCreate) ? Response::allow() : Response::deny();
    }

    public function update(User $user, GspIctProfile $profile): Response
    {
        return $this->permittedAndVisible($user, $profile, Permission::GspProfilesEdit);
    }

    public function delete(User $user, GspIctProfile $profile): Response
    {
        return $this->permittedAndVisible($user, $profile, Permission::GspProfilesDelete);
    }

    /**
     * Downloading the workbook exposes the office's equipment and connectivity data.
     */
    public function download(User $user, GspIctProfile $profile): Response
    {
        return $this->permittedAndVisible($user, $profile, Permission::ReportsGspIct);
    }

    private function permittedAndVisible(User $user, GspIctProfile $profile, Permission $permission): Response
    {
        if (! $user->hasPermissionTo($permission)) {
            return Response::deny();
        }

        return $profile->isVisibleTo($user) ? Response::allow() : Response::denyAsNotFound();
    }
}
