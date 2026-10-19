<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EquipmentLibraryType;
use App\Enums\StakeholderLibraryType;
use App\Models\EquipmentLibrary;
use App\Models\Office;
use App\Models\StakeholderLibrary;
use App\Models\StakeholderProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates one office's StakeholderProfile editable fields.
 * `complete_address` is deliberately absent — it's a PHP accessor built
 * from the province/municipality/barangay relations (see
 * StakeholderProfile's doc-comment), never mass-assignable.
 *
 * `province_id`/`municipality_id`/`barangay_id` are real FKs into the
 * Region-III-only psgc_provinces/psgc_municipalities/psgc_barangays
 * tables (cascading dropdowns), each validated with `exists()` — no
 * parent-match cross-check between the three (e.g. a barangay_id whose
 * municipality_id doesn't match the submitted municipality_id): the
 * frontend cascade already prevents this, and a mismatched combination is
 * harmless stored data, not a security or integrity concern worth the
 * extra query.
 *
 * Every rule is nullable: this is a progressively-filled record (see the
 * migration's class-level note — every column is nullable by design,
 * there is no "required at insert" set the way there is for list
 * resources like Equipment/Personnel), so nothing here is `required`.
 *
 * TIER 2 CUTOVER (lookup-normalization ADR, Step 2+3 — StakeholderProfile
 * has no Tier 1 fields per the ADR's own column-mapping table, so this is
 * a pure Tier 2 repoint): `governance_level` moves to
 * StakeholderLibrary/StakeholderLibraryType. `transaction_type` is
 * DELIBERATELY validated against EquipmentLibrary/EquipmentLibraryType,
 * not StakeholderLibraryType — `transaction_type` is not one of
 * StakeholderLibraryType's 7 cases, it's one of EquipmentLibraryType's 9
 * (shared with Equipment/EquipmentTransaction), the same cross-domain-read
 * pattern IspAccount already uses for mode_of_acquisition/
 * source_of_acquisition/fund_source — see EquipmentLibraryType's own
 * doc-comment, which explicitly calls out "StakeholderProfile reusing
 * transaction_type". The four JSON-array fields (nearby_institutions,
 * access_paths, transportation_options, community_engagement) are now
 * arrays of `stakeholder_libraries` row ids (ADR Question 4), each element
 * validated via Rule::exists() scoped to its type and active rows only —
 * same pattern as Personnel.fund_source's cutover. The `max:` bound (count
 * of active rows for that type) is unchanged in spirit, just repointed
 * from Lookup to StakeholderLibrary.
 */
class StakeholderProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorize against a transient (unsaved) instance scoped to the
        // route's {office} rather than `firstOrCreate(['office_id' =>
        // ...])` — StakeholderProfilePolicy checks $stakeholderProfile-
        // >office_id against the acting user's own, so the transient
        // instance needs office_id set to be authorization-equivalent to
        // the persisted row, without creating it as a side effect of an
        // unauthorized request.
        $office = $this->route('office');

        return $this->user()->can('update', new StakeholderProfile([
            'office_id' => $office instanceof Office ? $office->id : null,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $nearbyInstitutions = StakeholderLibrary::activeValues(StakeholderLibraryType::NearbyInstitution);
        $accessPaths = StakeholderLibrary::activeValues(StakeholderLibraryType::TypeOfAccessRoad);
        $transportationOptions = StakeholderLibrary::activeValues(StakeholderLibraryType::ByTransportation);
        $communityEngagement = StakeholderLibrary::activeValues(StakeholderLibraryType::CommunityEngagement);

        return [
            'governance_level' => ['nullable', 'string', Rule::in(StakeholderLibrary::activeValues(StakeholderLibraryType::GovernanceLevel))],
            'ro' => ['nullable', 'string', 'max:255'],
            'sdo' => ['nullable', 'string', 'max:255'],

            'school_district' => ['nullable', 'string', 'max:255'],
            'school_name' => ['nullable', 'string', 'max:255'],
            'school_id' => ['nullable', 'string', 'max:255'],

            'province_id' => ['nullable', 'integer', 'exists:psgc_provinces,id'],
            'municipality_id' => ['nullable', 'integer', 'exists:psgc_municipalities,id'],
            'legislative_district' => ['nullable', 'string', 'max:255'],
            'barangay_id' => ['nullable', 'integer', 'exists:psgc_barangays,id'],
            'street' => ['nullable', 'string', 'max:255'],

            'notes_corrections' => ['nullable', 'string'],
            'notes_recent_development' => ['nullable', 'string'],

            'mobile_1' => ['nullable', 'string', 'max:20'],
            'mobile_2' => ['nullable', 'string', 'max:20'],
            'landline' => ['nullable', 'string', 'max:20'],

            'chief_name' => ['nullable', 'string', 'max:255'],
            'chief_position' => ['nullable', 'string', 'max:255'],
            'chief_email' => ['nullable', 'email', 'max:255'],
            'chief_mobile' => ['nullable', 'string', 'max:20'],

            'admin_staff_name' => ['nullable', 'string', 'max:255'],
            'admin_staff_position' => ['nullable', 'string', 'max:255'],
            'admin_staff_email' => ['nullable', 'email', 'max:255'],
            'admin_staff_mobile' => ['nullable', 'string', 'max:20'],

            'network_administrator_name' => ['nullable', 'string', 'max:255'],

            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],

            'nearby_institutions' => ['nullable', 'array', 'max:'.count($nearbyInstitutions)],
            'nearby_institutions.*' => [
                'integer',
                Rule::exists('stakeholder_libraries', 'id')
                    ->where('type', StakeholderLibraryType::NearbyInstitution->value)
                    ->where('is_active', true),
            ],
            'nearby_institutions_other' => ['nullable', 'string', 'max:255'],

            'travel_time_to_center_minutes' => ['nullable', 'integer', 'min:0'],

            'access_paths' => ['nullable', 'array', 'max:'.count($accessPaths)],
            'access_paths.*' => [
                'integer',
                Rule::exists('stakeholder_libraries', 'id')
                    ->where('type', StakeholderLibraryType::TypeOfAccessRoad->value)
                    ->where('is_active', true),
            ],

            'transportation_options' => ['nullable', 'array', 'max:'.count($transportationOptions)],
            'transportation_options.*' => [
                'integer',
                Rule::exists('stakeholder_libraries', 'id')
                    ->where('type', StakeholderLibraryType::ByTransportation->value)
                    ->where('is_active', true),
            ],
            'transportation_other' => ['nullable', 'string', 'max:255'],

            'transportation_difficult' => ['nullable', 'boolean'],
            'considered_very_remote' => ['nullable', 'boolean'],
            'remote_context_notes' => ['nullable', 'string'],

            'gidca' => ['nullable', 'boolean'],
            'lms' => ['nullable', 'boolean'],

            'community_engagement' => ['nullable', 'array', 'max:'.count($communityEngagement)],
            'community_engagement.*' => [
                'integer',
                Rule::exists('stakeholder_libraries', 'id')
                    ->where('type', StakeholderLibraryType::CommunityEngagement->value)
                    ->where('is_active', true),
            ],
            'community_context_remarks' => ['nullable', 'string'],

            'submitted_at' => ['nullable', 'date'],
            'transaction_type' => ['nullable', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::TransactionType))],
        ];
    }
}
