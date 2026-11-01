<?php

namespace App\Http\Resources\Management;

use App\Models\GspIctProfile;
use App\Services\GspIctReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GspIctProfile
 */
class GspIctProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'office' => $this->whenLoaded('office', fn () => $this->office ? [
                'id' => $this->office->id,
                'office_name' => $this->office->office_name,
                'school_id' => $this->office->school_id,
            ] : null),
            'school_year' => $this->school_year,
            'as_of' => $this->as_of->toDateString(),
            'level' => $this->level,
            'level_label' => GspIctReport::LEVELS[$this->level] ?? $this->level,
            'radio_teachers' => (object) ($this->radio_teachers ?? []),
            'dict_free_wifi' => $this->dict_free_wifi,
            'dict_free_wifi_installed' => $this->dict_free_wifi_installed,
            'isp_reliability' => (object) ($this->isp_reliability ?? []),
            'school_head_id' => $this->school_head_id,
            'school_head' => $this->whenLoaded('schoolHead', fn () => $this->schoolHead?->toOption()),
            'ito_id' => $this->ito_id,
            'ito' => $this->whenLoaded('ito', fn () => $this->ito?->toOption()),
            'planning_officer_id' => $this->planning_officer_id,
            'planning_officer' => $this->whenLoaded('planningOfficer', fn () => $this->planningOfficer?->toOption()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
