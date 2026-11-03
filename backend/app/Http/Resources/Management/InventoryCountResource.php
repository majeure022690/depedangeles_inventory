<?php

namespace App\Http\Resources\Management;

use App\Models\InventoryCount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `offices` lists only the offices the viewer's data scope reaches. `summary` only on the detail endpoint.
 *
 * @mixin InventoryCount
 */
class InventoryCountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'as_of_date' => $this->as_of_date->toDateString(),
            'status' => $this->status,
            'office_ids' => $this->office_ids,
            'offices' => $this->whenHas('offices_list'),
            'committee_chair_id' => $this->committee_chair_id,
            'committee_chair' => $this->whenLoaded('committeeChair', fn () => $this->committeeChair?->toOption()),
            'committee_member_ids' => $this->committee_member_ids ?? [],
            'committee_members' => $this->whenHas('committee_members_list'),
            'coa_representative' => $this->coa_representative,
            'remarks' => $this->remarks,
            'items_count' => $this->whenCounted('items'),
            'counted_count' => $this->whenHas('counted_count', fn () => (int) $this->getAttribute('counted_count')),
            'summary' => $this->whenHas('summary'),
            'opened_at' => $this->opened_at,
            'closed_at' => $this->closed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
