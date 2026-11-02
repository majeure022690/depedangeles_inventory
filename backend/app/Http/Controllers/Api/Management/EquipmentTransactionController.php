<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Management\BulkStoreEquipmentTransactionRequest;
use App\Http\Requests\Management\StoreEquipmentTransactionRequest;
use App\Http\Resources\Management\EquipmentTransactionResource;
use App\Models\Equipment;
use App\Services\EquipmentAccountabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

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

    /**
     * One transaction per selected item, all or nothing; each item's bundle moves with it.
     */
    public function bulkStore(BulkStoreEquipmentTransactionRequest $request, EquipmentAccountabilityService $accountability): JsonResponse
    {
        $data = Arr::except($request->validated(), ['equipment_ids']);
        $items = $request->equipment();

        DB::transaction(fn () => $items->each(fn (Equipment $item) => $accountability->recordTransaction($item, $data)));

        activity()
            ->causedBy($request->user())
            ->event('equipment.bulk_transaction')
            ->withProperties([
                'equipment_ids' => $items->modelKeys(),
                'transaction_type' => $data['transaction_type'],
                'accountable_officer_id' => $data['accountable_officer_id'] ?? null,
                'end_user_id' => $data['end_user_id'] ?? null,
            ])
            ->log('equipment.bulk_transaction');

        return response()->json([
            'data' => ['count' => $items->count()],
            'message' => trans_choice('Transaction recorded for :count item.|Transaction recorded for :count items.', $items->count()),
        ], 201);
    }
}
