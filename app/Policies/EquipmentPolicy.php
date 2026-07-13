<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\User;

class EquipmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::EquipmentView);
    }

    public function view(User $user, Equipment $equipment): bool
    {
        return $user->hasPermissionTo(Permission::EquipmentView);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permission::EquipmentCreate);
    }

    public function update(User $user, Equipment $equipment): bool
    {
        return $user->hasPermissionTo(Permission::EquipmentEdit);
    }

    /**
     * Deletion is the most consequential Equipment action, so a denial
     * here is logged, not just silently 403'd.
     */
    public function delete(User $user, Equipment $equipment): bool
    {
        $allowed = $user->hasPermissionTo(Permission::EquipmentDelete);

        if (! $allowed) {
            AuditLog::record('authorization.denied', $equipment, [
                'permission' => Permission::EquipmentDelete->value,
                'user_id' => $user->id,
            ]);
        }

        return $allowed;
    }

    public function restore(User $user, Equipment $equipment): bool
    {
        return false;
    }

    public function forceDelete(User $user, Equipment $equipment): bool
    {
        return false;
    }

    /**
     * Explicit custom ability for EquipmentTransactionController::store.
     * Recording a transaction reassigns accountability for a physical
     * asset — a distinct, more sensitive action than editing Equipment's
     * own descriptive fields (see App\Enums\Permission doc-comment), so
     * it's checked against its own permission rather than
     * Permission::EquipmentEdit.
     */
    public function createTransaction(User $user, Equipment $equipment): bool
    {
        $allowed = $user->hasPermissionTo(Permission::EquipmentTransactionsCreate);

        if (! $allowed) {
            AuditLog::record('authorization.denied', $equipment, [
                'permission' => Permission::EquipmentTransactionsCreate->value,
                'user_id' => $user->id,
            ]);
        }

        return $allowed;
    }
}
