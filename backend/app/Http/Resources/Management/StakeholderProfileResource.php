<?php

namespace App\Http\Resources\Management;

use App\Models\StakeholderProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The four checkbox-group fields (`nearby_institutions`, `access_paths`,
 * `transportation_options`, `community_engagement`) are arrays of
 * stakeholder_libraries ids. `complete_address` is read-only, built from
 * the PSGC relations (load province/municipality/barangay first).
 *
 * @mixin StakeholderProfile
 */
class StakeholderProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'governance_level' => $this->governance_level,
            'ro' => $this->ro,
            'sdo' => $this->sdo,
            'school_district' => $this->school_district,
            'school_name' => $this->school_name,
            'school_id' => $this->school_id,
            'province_id' => $this->province_id,
            'municipality_id' => $this->municipality_id,
            'legislative_district' => $this->legislative_district,
            'barangay_id' => $this->barangay_id,
            'street' => $this->street,
            'complete_address' => $this->complete_address,
            'notes_corrections' => $this->notes_corrections,
            'notes_recent_development' => $this->notes_recent_development,
            'mobile_1' => $this->mobile_1,
            'mobile_2' => $this->mobile_2,
            'landline' => $this->landline,
            'chief_name' => $this->chief_name,
            'chief_position' => $this->chief_position,
            'chief_email' => $this->chief_email,
            'chief_mobile' => $this->chief_mobile,
            'admin_staff_name' => $this->admin_staff_name,
            'admin_staff_position' => $this->admin_staff_position,
            'admin_staff_email' => $this->admin_staff_email,
            'admin_staff_mobile' => $this->admin_staff_mobile,
            'network_administrator_name' => $this->network_administrator_name,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'nearby_institutions' => $this->nearby_institutions,
            'nearby_institutions_other' => $this->nearby_institutions_other,
            'travel_time_to_center_minutes' => $this->travel_time_to_center_minutes,
            'access_paths' => $this->access_paths,
            'transportation_options' => $this->transportation_options,
            'transportation_other' => $this->transportation_other,
            'transportation_difficult' => $this->transportation_difficult,
            'considered_very_remote' => $this->considered_very_remote,
            'remote_context_notes' => $this->remote_context_notes,
            'gidca' => $this->gidca,
            'lms' => $this->lms,
            'community_engagement' => $this->community_engagement,
            'community_context_remarks' => $this->community_context_remarks,
            'submitted_at' => $this->submitted_at,
            'transaction_type' => $this->transaction_type,
            'updated_at' => $this->updated_at,
        ];
    }
}
