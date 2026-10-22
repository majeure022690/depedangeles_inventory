<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One shared Policy for all 13 reference-data tables (see the
 * lookup-normalization ADR), matching the single reference-data.manage
 * permission. Registered per model in
 * AppServiceProvider::configureAuthorization(), since auto-discovery only
 * finds `{Model}Policy`.
 */
class ReferenceDataPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::ReferenceDataManage);
    }

    public function view(User $user, Model $referenceRow): bool
    {
        return $user->hasPermissionTo(Permission::ReferenceDataManage);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permission::ReferenceDataManage);
    }

    public function update(User $user, Model $referenceRow): bool
    {
        return $user->hasPermissionTo(Permission::ReferenceDataManage);
    }

    public function delete(User $user, Model $referenceRow): bool
    {
        return $user->hasPermissionTo(Permission::ReferenceDataManage);
    }
}
