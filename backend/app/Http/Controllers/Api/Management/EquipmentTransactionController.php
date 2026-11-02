<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Management\BulkStoreEquipmentTransactionRequest;
use App\Http\Requests\Management\ConfirmEquipmentReceiptRequest;
use App\Http\Requests\Management\StoreEquipmentTransactionRequest;
use App\Http\Requests\Management\StoreTransactionDocumentUploadRequest;
use App\Http\Resources\Management\EquipmentTransactionResource;
use App\Models\Equipment;
use App\Models\EquipmentTransaction;
use App\Models\EquipmentTransactionDocument;
use App\Services\EquipmentAccountabilityService;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            'documents',
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

    /**
     * Clears the awaiting-receipt flag on the selected items (and their peripherals).
     */
    public function confirmReceipt(ConfirmEquipmentReceiptRequest $request, EquipmentAccountabilityService $accountability): JsonResponse
    {
        $count = $accountability->confirmReceipt($request->equipment());

        return response()->json([
            'data' => ['count' => $count],
            'message' => trans_choice('Receipt confirmed for :count item.|Receipt confirmed for :count items.', $count),
        ]);
    }

    /**
     * Stores a supporting document as a pending upload, claimed later by the transaction that names it.
     */
    public function uploadDocument(StoreTransactionDocumentUploadRequest $request, FileUploadService $uploads): JsonResponse
    {
        $stored = $uploads->storeDocument($request->file('file'), 'local', 'equipment-transaction-documents');

        $document = EquipmentTransactionDocument::create([
            'original_filename' => mb_substr((string) $stored['original_filename'], 0, 255),
            'disk' => $stored['disk'],
            'path' => $stored['path'],
            'mime_type' => $stored['mime_type'],
            'file_size' => $stored['file_size'],
            'file_hash' => $stored['file_hash'],
            'uploaded_by_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'data' => ['id' => $document->id, 'original_filename' => $document->original_filename, 'file_size' => $document->file_size],
            'message' => 'File uploaded.',
        ], 201);
    }

    public function downloadDocument(Equipment $equipment, EquipmentTransaction $transaction, EquipmentTransactionDocument $document): StreamedResponse
    {
        $this->authorize('view', $equipment);
        abort_unless($document->hasFile() && Storage::disk((string) $document->disk)->exists((string) $document->path), 404);

        return Storage::disk((string) $document->disk)->download((string) $document->path, $document->original_filename, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
