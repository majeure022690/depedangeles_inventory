<?php

namespace App\Http\Resources\Management;

use App\Models\Office;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the cross-office admin list: the office plus whether its
 * one-per-office form has saved answers. Eager-load `stakeholderProfile`.
 *
 * @mixin Office
 */
class StakeholderProfileOfficeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $answered = $this->stakeholderProfile?->hasAnswers() ?? false;

        return [
            'id' => $this->id,
            'office_name' => $this->office_name,
            'office_type' => $this->office_type,
            'school_id' => $this->school_id,
            'has_profile' => $answered,
            'updated_at' => $answered ? $this->stakeholderProfile->updated_at : null,
        ];
    }
}
