<?php

namespace App\Http\Resources\Management;

use App\Models\InventoryCountFoundItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InventoryCountFoundItem
 */
class InventoryCountFoundItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'office' => $this->office ? ['id' => $this->office->id, 'office_name' => $this->office->office_name] : null,
            'equipment' => $this->equipment ? [
                'id' => $this->equipment->id,
                'property_no' => $this->equipment->property_no,
                'description' => $this->equipment->propertyDescription(),
            ] : null,
            'description' => $this->description,
            'property_no' => $this->property_no,
            'serial_no' => $this->serial_no,
            'condition' => $this->condition?->name,
            'location' => $this->location,
            'remarks' => $this->remarks,
            'counted_by' => $this->countedBy?->name,
            'counted_at' => $this->counted_at,
        ];
    }
}
