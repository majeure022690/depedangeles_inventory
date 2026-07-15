<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\InternetConnectivitySurvey;
use App\Models\User;

/**
 * One row per Office, mirroring StakeholderProfilePolicy. `.view`/`.edit`
 * are scoped to the acting user's own office_id; `.view_all` bypasses that
 * and gates the cross-office admin list (viewAny()).
 */
class InternetConnectivitySurveyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::InternetConnectivityViewAll);
    }

    public function view(User $user, InternetConnectivitySurvey $internetConnectivitySurvey): bool
    {
        if ($user->hasPermissionTo(Permission::InternetConnectivityViewAll)) {
            return true;
        }

        return $user->hasPermissionTo(Permission::InternetConnectivityView)
            && $user->office_id !== null
            && $user->office_id === $internetConnectivitySurvey->office_id;
    }

    public function update(User $user, InternetConnectivitySurvey $internetConnectivitySurvey): bool
    {
        if ($user->hasPermissionTo(Permission::InternetConnectivityViewAll)) {
            return true;
        }

        return $user->hasPermissionTo(Permission::InternetConnectivityEdit)
            && $user->office_id !== null
            && $user->office_id === $internetConnectivitySurvey->office_id;
    }
}
