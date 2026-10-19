<?php

namespace App\Http\Resources\Management;

use App\Models\InternetConnectivitySurvey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `available_isps`/`subscribed_isps` are arrays of isp_providers ids;
 * `mobile_signal_types`/`coverage_areas` arrays of connectivity_libraries
 * ids; `electricity_sources` an array of stakeholder_libraries ids.
 *
 * @mixin InternetConnectivitySurvey
 */
class InternetConnectivitySurveyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'has_isp_in_area' => $this->has_isp_in_area,
            'available_isps' => $this->available_isps,
            'available_isps_other' => $this->available_isps_other,
            'mobile_signal_types' => $this->mobile_signal_types,
            'has_mobile_data_connectivity' => $this->has_mobile_data_connectivity,
            'mobile_data_quality' => $this->mobile_data_quality,
            'subscribes_to_isp' => $this->subscribes_to_isp,
            'subscribed_isps' => $this->subscribed_isps,
            'insufficient_bandwidth_explanation' => $this->insufficient_bandwidth_explanation,
            'coverage_areas' => $this->coverage_areas,
            'coverage_areas_other' => $this->coverage_areas_other,
            'dict_free_wifi_recipient' => $this->dict_free_wifi_recipient,
            'dict_free_wifi_rating' => $this->dict_free_wifi_rating,
            'has_sufficient_bandwidth' => $this->has_sufficient_bandwidth,
            'no_subscription_reason' => $this->no_subscription_reason,
            'has_electricity_source' => $this->has_electricity_source,
            'electricity_sources' => $this->electricity_sources,
            'primarily_solar_powered' => $this->primarily_solar_powered,
            'frequent_brownouts' => $this->frequent_brownouts,
            'rooms_other_use' => $this->rooms_other_use,
            'updated_at' => $this->updated_at,
        ];
    }
}
