<?php

namespace App\Policies;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\InternetConnectivitySurvey;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class InternetConnectivitySurveyPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermissionTo(Permission::InternetConnectivityView) && $user->dataScope() === DataScope::All
            ? Response::allow()
            : Response::deny();
    }

    public function view(User $user, InternetConnectivitySurvey $internetConnectivitySurvey): Response
    {
        return $this->permittedAndReached($user, $internetConnectivitySurvey, Permission::InternetConnectivityView);
    }

    public function update(User $user, InternetConnectivitySurvey $internetConnectivitySurvey): Response
    {
        return $this->permittedAndReached($user, $internetConnectivitySurvey, Permission::InternetConnectivityEdit);
    }

    private function permittedAndReached(User $user, InternetConnectivitySurvey $internetConnectivitySurvey, Permission $permission): Response
    {
        if (! $user->hasPermissionTo($permission)) {
            return Response::deny();
        }

        return $user->reachesOffice($internetConnectivitySurvey->office_id) ? Response::allow() : Response::denyAsNotFound();
    }
}
