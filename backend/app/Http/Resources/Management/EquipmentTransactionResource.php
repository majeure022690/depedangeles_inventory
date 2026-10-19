<?php

namespace App\Http\Resources\Management;

use App\Models\EquipmentTransaction;
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
            'supporting_documents1' => $this->supporting_documents1,
            'or_si_dr_iar_no' => $this->or_si_dr_iar_no,
            'supporting_documents2' => $this->supporting_documents2,
            'par_ics_rrsp_rs_wmr_no' => $this->par_ics_rrsp_rs_wmr_no,
            'created_at' => $this->created_at,
        ];
    }
}
