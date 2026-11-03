<?php

namespace App\Http\Resources\Management;

use App\Models\InventoryCountItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `recorded` is the Equipment record as it stood when the count opened; `found` is what the counter saw.
 *
 * @mixin InventoryCountItem
 */
class InventoryCountItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $equipment = $this->equipment;

        return [
            'id' => $this->id,
            'equipment' => [
                'id' => $equipment->id,
                'property_no' => $equipment->property_no,
                'old_property_no' => $equipment->old_property_no,
                'serial_number' => $equipment->serial_number,
                'item_type' => $equipment->itemType?->name,
                'description' => $equipment->propertyDescription(),
            ],
            'office' => $this->office ? ['id' => $this->office->id, 'office_name' => $this->office->office_name] : null,
            'recorded' => [
                'condition_id' => $this->condition_id,
                'condition' => $this->condition?->name,
                'accountable_officer_id' => $this->accountable_officer_id,
                'accountable_officer' => $this->accountableOfficer?->full_name,
                'location' => $this->location,
            ],
            'result' => $this->result,
            'found' => [
                'condition_id' => $this->found_condition_id,
                'condition' => $this->foundCondition?->name,
                'holder_id' => $this->found_holder_id,
                'holder' => $this->foundHolder?->full_name,
                'location' => $this->found_location,
            ],
            'remarks' => $this->remarks,
            'bucket' => $this->bucket(),
            'condition_changed' => $this->conditionChanged(),
            'holder_changed' => $this->holderChanged(),
            'unserviceable' => $this->result === InventoryCountItem::FOUND
                && in_array($this->foundCondition?->name, InventoryCountItem::UNSERVICEABLE_CONDITIONS, true),
            'counted_by' => $this->countedBy?->name,
            'counted_at' => $this->counted_at,
        ];
    }
}
