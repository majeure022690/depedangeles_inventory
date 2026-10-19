<?php

namespace App\Http\Resources\Management;

use App\Models\Office;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the cross-office admin list: the office plus whether its
 * one-per-office form has saved answers. Eager-load `internetConnectivitySurvey`.
 *
 * @mixin Office
 */
class ConnectivitySurveyOfficeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $answered = $this->internetConnectivitySurvey?->hasAnswers() ?? false;

        return [
            'id' => $this->id,
            'office_name' => $this->office_name,
            'office_type' => $this->office_type,
            'school_id' => $this->school_id,
            'has_survey' => $answered,
            'updated_at' => $answered ? $this->internetConnectivitySurvey->updated_at : null,
        ];
    }
}
