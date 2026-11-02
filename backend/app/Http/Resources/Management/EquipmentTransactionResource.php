<?php

namespace App\Http\Resources\Management;

use App\Models\EquipmentTransaction;
use App\Models\EquipmentTransactionDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EquipmentTransaction
 */
class EquipmentTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'equipment_id' => $this->equipment_id,
            'transaction_type' => $this->transaction_type,
            'accountable_officer' => $this->whenLoaded('accountableOfficer', fn () => EquipmentResource::person($this->accountableOfficer)),
            'end_user' => $this->whenLoaded('endUser', fn () => EquipmentResource::person($this->endUser)),
            'received_by' => $this->whenLoaded('receivedBy', fn () => EquipmentResource::person($this->receivedBy)),
            'date_assigned_accountable_officer' => $this->date_assigned_accountable_officer?->toDateString(),
            'date_assigned_end_user' => $this->date_assigned_end_user?->toDateString(),
            'date_received_new_accountable' => $this->date_received_new_accountable?->toDateString(),
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn (EquipmentTransactionDocument $document) => [
                'id' => $document->id,
                'document_type' => $document->document_type,
                'document_no' => $document->document_no,
                'original_filename' => $document->original_filename,
                'has_file' => $document->hasFile(),
            ])->all()),
            'created_at' => $this->created_at,
        ];
    }
}
