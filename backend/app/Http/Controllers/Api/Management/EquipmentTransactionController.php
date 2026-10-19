<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Management\StoreEquipmentTransactionRequest;
use App\Http\Resources\Management\EquipmentTransactionResource;
use App\Models\Equipment;
use App\Services\EquipmentAccountabilityService;
use Illuminate\Http\JsonResponse;

/**
 * Append-only: equipment_transactions has no update/destroy routes by
 * design. Recording one is the only way accountability changes hands.
 */
class EquipmentTransactionController extends Controller
{
    public function store(
        StoreEquipmentTransactionRequest $request,
        Equipment $equipment,
        EquipmentAccountabilityService $accountability,
    ): JsonResponse {
        $this->authorize('createTransaction', $equipment);

        $transaction = $accountability->recordTransaction($equipment, $request->validated());

        return new EquipmentTransactionResource($transaction->load([
            'accountableOfficer:id,full_name',
            'endUser:id,full_name',
            'receivedBy:id,full_name',
        ]))
            ->additional(['message' => 'Transaction recorded.'])
            ->response()
            ->setStatusCode(201);
    }
}
