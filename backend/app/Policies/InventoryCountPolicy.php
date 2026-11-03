<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\InventoryCount;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class InventoryCountPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasAnyPermission([Permission::InventoryCountView, Permission::InventoryCountConduct]) ? Response::allow() : Response::deny();
    }

    public function view(User $user, InventoryCount $count): Response
    {
        if ($this->viewAny($user)->denied()) {
            return Response::deny();
        }

        return $count->isVisibleTo($user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): Response
    {
        return $user->hasPermissionTo(Permission::InventoryCountManage) ? Response::allow() : Response::deny();
    }

    /**
     * Also gates opening, closing and cancelling.
     */
    public function update(User $user, InventoryCount $count): Response
    {
        return $this->permittedAndVisible($user, $count, Permission::InventoryCountManage);
    }

    public function delete(User $user, InventoryCount $count): Response
    {
        $response = $this->permittedAndVisible($user, $count, Permission::InventoryCountManage);

        if ($response->allowed() && ! $count->isDraft()) {
            return Response::deny('Only a draft count can be deleted. Cancel an open count instead.');
        }

        return $response;
    }

    public function conduct(User $user, InventoryCount $count): Response
    {
        $response = $this->permittedAndVisible($user, $count, Permission::InventoryCountConduct);

        if ($response->allowed() && ! $count->isOpen()) {
            return Response::deny('This count is not open.');
        }

        return $response;
    }

    private function permittedAndVisible(User $user, InventoryCount $count, Permission $permission): Response
    {
        if (! $user->hasPermissionTo($permission)) {
            return Response::deny();
        }

        return $count->isVisibleTo($user) ? Response::allow() : Response::denyAsNotFound();
    }
}
