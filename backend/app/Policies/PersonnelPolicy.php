<?php

namespace App\Policies;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Personnel;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PersonnelPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermissionTo(Permission::PersonnelView) ? Response::allow() : Response::deny();
    }

    public function view(User $user, Personnel $personnel): Response
    {
        return $this->permittedAndVisible($user, $personnel, Permission::PersonnelView);
    }

    public function create(User $user): Response
    {
        return $user->hasPermissionTo(Permission::PersonnelCreate) && $user->dataScope() !== DataScope::Own
            ? Response::allow()
            : Response::deny();
    }

    public function update(User $user, Personnel $personnel): Response
    {
        return $this->permittedAndVisible($user, $personnel, Permission::PersonnelEdit);
    }

    public function delete(User $user, Personnel $personnel): Response
    {
        $response = $this->permittedAndVisible($user, $personnel, Permission::PersonnelDelete);

        if ($response->denied()) {
            activity()
                ->causedBy($user)
                ->performedOn($personnel)
                ->event('authorization.denied')
                ->withProperties(['permission' => Permission::PersonnelDelete->value])
                ->log('authorization.denied');
        }

        return $response;
    }

    public function restore(User $user, Personnel $personnel): bool
    {
        return false;
    }

    public function forceDelete(User $user, Personnel $personnel): bool
    {
        return false;
    }

    private function permittedAndVisible(User $user, Personnel $personnel, Permission $permission): Response
    {
        if (! $user->hasPermissionTo($permission)) {
            return Response::deny();
        }

        return $personnel->isVisibleTo($user) ? Response::allow() : Response::denyAsNotFound();
    }
}
