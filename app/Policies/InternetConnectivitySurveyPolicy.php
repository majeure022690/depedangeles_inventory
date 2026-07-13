<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\InternetConnectivitySurvey;
use App\Models\User;

/**
 * SINGLETON resource (see App\Models\InternetConnectivitySurvey) —
 * exactly one row ever exists, fetched/created by Backend via
 * `InternetConnectivitySurvey::firstOrCreate([])`. There is no
 * create/delete action to authorize, so this Policy deliberately has only
 * `view`/`update`, unlike the multi-record Policies that also authorize
 * create/delete/restore/forceDelete.
 *
 * A separate small Policy rather than folding this into
 * StakeholderProfilePolicy: the two singletons are semantically distinct
 * resources (division profile/contact data vs. connectivity/ISP survey
 * data) with independently assignable permissions
 * (InternetConnectivityView/Edit vs. StakeholderProfileView/Edit) — a
 * shared Policy would blur which permission gates which model.
 */
class InternetConnectivitySurveyPolicy
{
    public function view(User $user, InternetConnectivitySurvey $internetConnectivitySurvey): bool
    {
        return $user->hasPermissionTo(Permission::InternetConnectivityView);
    }

    public function update(User $user, InternetConnectivitySurvey $internetConnectivitySurvey): bool
    {
        return $user->hasPermissionTo(Permission::InternetConnectivityEdit);
    }
}
