<?php

namespace App\Http\Resources\Management;

use App\Models\IspAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `isp_provider_id` is the FK the form binds to, `isp` the provider's
 * display name. `speed_tests`/`subscription_costs` only on the detail
 * endpoint, newest logged first.
 *
 * @mixin IspAccount
 */
class IspAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'isp_provider_id' => $this->isp_provider_id,
            'office_id' => $this->office_id,
            'office' => $this->whenLoaded('office', fn () => $this->office ? [
                'id' => $this->office->id,
                'office_name' => $this->office->office_name,
            ] : null),
            'isp' => $this->whenLoaded('ispProvider', fn () => $this->ispProvider?->name),
            'cost_per_month' => (float) $this->cost_per_month,
            'isp_billing_account_no' => $this->isp_billing_account_no,
            'package_purchased_inclusion' => $this->package_purchased_inclusion,
            'school_area_coverage' => $this->school_area_coverage,
            'min_speed' => $this->min_speed !== null ? (float) $this->min_speed : null,
            'max_speed' => $this->max_speed !== null ? (float) $this->max_speed : null,
            'subscriber_speed_upload' => $this->subscriber_speed_upload !== null ? (float) $this->subscriber_speed_upload : null,
            'subscriber_speed_download' => $this->subscriber_speed_download !== null ? (float) $this->subscriber_speed_download : null,
            'subscription_type' => $this->subscription_type,
            'isp_connection_type' => $this->isp_connection_type,
            'contract_start_date' => $this->contract_start_date?->toDateString(),
            'contract_end_date' => $this->contract_end_date?->toDateString(),
            'inactive_contract' => $this->inactive_contract,
            'purpose_of_subscription' => $this->purpose_of_subscription,
            'mode_of_acquisition' => $this->mode_of_acquisition,
            'source_of_acquisition' => $this->source_of_acquisition,
            'donor' => $this->donor,
            'fund_source' => $this->fund_source,
            'number_access_points_linked' => $this->number_access_points_linked,
            'locations_access_points' => $this->locations_access_points,
            'number_admin_area_covered' => $this->number_admin_area_covered,
            'rate_connectivity_admin_areas' => $this->rate_connectivity_admin_areas,
            'number_classrooms_covered' => $this->number_classrooms_covered,
            'rate_connectivity_classroom' => $this->rate_connectivity_classroom,
            'overall_signal_quality' => $this->overall_signal_quality,
            'rate_isp_service' => $this->rate_isp_service,
            'speed_tests' => IspSpeedTestResource::collection($this->whenLoaded('speedTests')),
            'subscription_costs' => IspSubscriptionCostResource::collection($this->whenLoaded('subscriptionCosts')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
