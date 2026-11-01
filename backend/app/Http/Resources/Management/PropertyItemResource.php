<?php

namespace App\Http\Resources\Management;

use App\Models\Personnel;
use App\Models\PropertyItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PropertyItem
 */
class PropertyItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'office' => $this->whenLoaded('office', fn () => $this->office ? ['id' => $this->office->id, 'office_name' => $this->office->office_name] : null),
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'article' => $this->article,
            'description' => $this->description,
            'property_no' => $this->property_no,
            'unit_of_measure' => $this->unit_of_measure,
            'date_acquired' => $this->date_acquired?->toDateString(),
            'unit_value' => $this->unit_value === null ? null : (float) $this->unit_value,
            'quantity' => $this->quantity,
            'on_hand_count' => $this->on_hand_count,
            'total_value' => $this->totalValue(),
            'grade_level' => $this->grade_level,
            'source' => $this->source?->value,
            'condition' => $this->condition,
            'with_are' => $this->with_are,
            'accountable_officer_id' => $this->accountable_officer_id,
            'accountable_officer' => $this->whenLoaded('accountableOfficer', fn () => $this->personName($this->accountableOfficer)),
            'end_user_id' => $this->end_user_id,
            'end_user' => $this->whenLoaded('endUser', fn () => $this->personName($this->endUser)),
            'location' => $this->location,
            'remarks' => $this->remarks,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function personName(?Personnel $personnel): ?string
    {
        return $personnel === null ? null : trim("{$personnel->last_name}, {$personnel->first_name}");
    }
}
