<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\PropertyItem;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PropertyItemPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermissionTo(Permission::PropertyItemsView) ? Response::allow() : Response::deny();
    }

    public function view(User $user, PropertyItem $propertyItem): Response
    {
        return $this->permittedAndVisible($user, $propertyItem, Permission::PropertyItemsView);
    }

    public function create(User $user): Response
    {
        return $user->hasPermissionTo(Permission::PropertyItemsCreate) ? Response::allow() : Response::deny();
    }

    public function update(User $user, PropertyItem $propertyItem): Response
    {
        return $this->permittedAndVisible($user, $propertyItem, Permission::PropertyItemsEdit);
    }

    public function delete(User $user, PropertyItem $propertyItem): Response
    {
        $response = $this->permittedAndVisible($user, $propertyItem, Permission::PropertyItemsDelete);

        if ($response->denied()) {
            activity()
                ->causedBy($user)
                ->performedOn($propertyItem)
                ->event('authorization.denied')
                ->withProperties(['permission' => Permission::PropertyItemsDelete->value])
                ->log('authorization.denied');
        }

        return $response;
    }

    public function restore(User $user, PropertyItem $propertyItem): bool
    {
        return false;
    }

    public function forceDelete(User $user, PropertyItem $propertyItem): bool
    {
        return false;
    }

    private function permittedAndVisible(User $user, PropertyItem $propertyItem, Permission $permission): Response
    {
        if (! $user->hasPermissionTo($permission)) {
            return Response::deny();
        }

        return $propertyItem->isVisibleTo($user) ? Response::allow() : Response::denyAsNotFound();
    }
}
