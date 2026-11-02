<?php

declare(strict_types=1);

namespace App\Http\Resources\Management;

use App\Models\Equipment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Equipment
 */
class PropertyItemEquipmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $cost = $this->acquisition_cost === null ? null : (float) $this->acquisition_cost;

        return [
            'id' => $this->id,
            'article' => $this->specified('item_type_other', $this->itemType?->name),
            'description' => $this->propertyDescription(),
            'property_no' => $this->property_no,
            'office' => $this->currentAccountableOfficer?->office?->office_name ?? $this->currentEndUser?->office?->office_name,
            'unit_value' => $cost,
            'semi_expendable' => $cost === null || $cost < Equipment::PPE_THRESHOLD,
            'on_hand' => in_array($this->disposition_status, Equipment::MISSING, true) ? 0 : 1,
            'condition' => $this->equipmentCondition?->name,
            'disposition_status' => $this->disposition_status,
            'accountable_officer' => $this->currentAccountableOfficer?->full_name,
            'end_user' => $this->currentEndUser?->full_name,
        ];
    }
}
