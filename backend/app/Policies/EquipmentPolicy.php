<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class EquipmentPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermissionTo(Permission::EquipmentView) ? Response::allow() : Response::deny();
    }

    public function view(User $user, Equipment $equipment): Response
    {
        return $this->permittedAndVisible($user, $equipment, Permission::EquipmentView);
    }

    public function create(User $user): Response
    {
        return $user->hasPermissionTo(Permission::EquipmentCreate) ? Response::allow() : Response::deny();
    }

    public function update(User $user, Equipment $equipment): Response
    {
        return $this->permittedAndVisible($user, $equipment, Permission::EquipmentEdit);
    }

    public function delete(User $user, Equipment $equipment): Response
    {
        return $this->auditedDenial($user, $equipment, Permission::EquipmentDelete);
    }

    public function restore(User $user, Equipment $equipment): bool
    {
        return false;
    }

    public function forceDelete(User $user, Equipment $equipment): bool
    {
        return false;
    }

    public function createTransaction(User $user, Equipment $equipment): Response
    {
        return $this->auditedDenial($user, $equipment, Permission::EquipmentTransactionsCreate);
    }

    private function permittedAndVisible(User $user, Equipment $equipment, Permission $permission): Response
    {
        if (! $user->hasPermissionTo($permission)) {
            return Response::deny();
        }

        return $equipment->isVisibleTo($user) ? Response::allow() : Response::denyAsNotFound();
    }

    private function auditedDenial(User $user, Equipment $equipment, Permission $permission): Response
    {
        $response = $this->permittedAndVisible($user, $equipment, $permission);

        if ($response->denied()) {
            activity()
                ->causedBy($user)
                ->performedOn($equipment)
                ->event('authorization.denied')
                ->withProperties(['permission' => $permission->value])
                ->log('authorization.denied');
        }

        return $response;
    }
}
