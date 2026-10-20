<?php

namespace App\Http\Resources\Management;

use App\Models\Personnel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tier 1 fields ship both the FK id the form binds to (`position_id`)
 * and the related row's display name (`position`), read through the
 * relationship. `fund_source` is an array of personnel_libraries ids.
 *
 * @mixin Personnel
 */
class PersonnelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'full_name' => $this->full_name,
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'suffix' => $this->suffix,
            'position_id' => $this->position_id,
            'position' => $this->whenLoaded('personnelPosition', fn () => $this->personnelPosition?->name),
            'ro_office_id' => $this->ro_office_id,
            'ro_division' => $this->whenLoaded('roOffice', fn () => $this->roOffice?->name),
            'sdo_office_id' => $this->sdo_office_id,
            'division_unit' => $this->whenLoaded('sdoOffice', fn () => $this->sdoOffice?->name),
            'office_id' => $this->office_id,
            'office' => $this->whenLoaded('office', fn () => $this->office?->office_name),
            'oic' => $this->oic,
            'oic_office' => $this->oic_office,
            'mobile_1' => $this->mobile_1,
            'mobile_2' => $this->mobile_2,
            'deped_email' => $this->deped_email,
            'personal_email' => $this->personal_email,
            'date_hired' => $this->date_hired?->toDateString(),
            'non_deped_funded' => $this->non_deped_funded,
            'fund_source' => $this->fund_source,
            'inactive' => $this->inactive,
            'separation_date' => $this->separation_date?->toDateString(),
            'separation_cause' => $this->separation_cause,
            'transferred_from' => $this->transferred_from,
            'transferred_to' => $this->transferred_to,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
