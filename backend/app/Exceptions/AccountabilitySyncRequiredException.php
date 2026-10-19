<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when code attempts to update Equipment::current_accountable_officer_id
 * or current_end_user_id on an existing row outside of
 * App\Services\EquipmentAccountabilityService::recordTransaction(). Those
 * columns must always mirror the latest equipment_transactions row —
 * changing them any other way would let the "current holder" pointers
 * drift out of sync with the audit log.
 */
class AccountabilitySyncRequiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Equipment::current_accountable_officer_id and current_end_user_id can only be '.
            'changed via EquipmentAccountabilityService::recordTransaction(), which keeps them '.
            'in sync with an equipment_transactions row. Direct updates are not allowed.'
        );
    }
}
