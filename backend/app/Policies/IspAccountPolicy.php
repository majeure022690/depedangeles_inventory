<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\IspAccount;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class IspAccountPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermissionTo(Permission::IspAccountsView) ? Response::allow() : Response::deny();
    }

    public function view(User $user, IspAccount $ispAccount): Response
    {
        return $this->permittedAndVisible($user, $ispAccount, Permission::IspAccountsView);
    }

    public function create(User $user): Response
    {
        return $user->hasPermissionTo(Permission::IspAccountsCreate) ? Response::allow() : Response::deny();
    }

    /**
     * Also gates logging speed tests and subscription costs against the account.
     */
    public function update(User $user, IspAccount $ispAccount): Response
    {
        return $this->permittedAndVisible($user, $ispAccount, Permission::IspAccountsEdit);
    }

    public function delete(User $user, IspAccount $ispAccount): Response
    {
        $response = $this->permittedAndVisible($user, $ispAccount, Permission::IspAccountsDelete);

        if ($response->denied()) {
            activity()
                ->causedBy($user)
                ->performedOn($ispAccount)
                ->event('authorization.denied')
                ->withProperties(['permission' => Permission::IspAccountsDelete->value])
                ->log('authorization.denied');
        }

        return $response;
    }

    public function restore(User $user, IspAccount $ispAccount): bool
    {
        return false;
    }

    public function forceDelete(User $user, IspAccount $ispAccount): bool
    {
        return false;
    }

    private function permittedAndVisible(User $user, IspAccount $ispAccount, Permission $permission): Response
    {
        if (! $user->hasPermissionTo($permission)) {
            return Response::deny();
        }

        return $ispAccount->isVisibleTo($user) ? Response::allow() : Response::denyAsNotFound();
    }
}
