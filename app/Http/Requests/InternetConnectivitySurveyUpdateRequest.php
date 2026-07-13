<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ConnectivityLibraryType;
use App\Enums\StakeholderLibraryType;
use App\Models\ConnectivityLibrary;
use App\Models\InternetConnectivitySurvey;
use App\Models\IspProvider;
use App\Models\StakeholderLibrary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the single InternetConnectivitySurvey singleton's editable
 * fields only. The "protected"/computed aggregate fields (Total ISPs,
 * Total Cost/month, Total Amount Spent, Total Projected Expenditure, Rooms
 * Covered Admin/Classroom, Total Access Points) are deliberately absent —
 * see InternetConnectivitySurvey's doc-comment. They are never columns on
 * this table and never part of this request's validated/writable data;
 * InternetConnectivitySurveyController computes them live from IspAccount/
 * IspSubscriptionCost and passes them as separate read-only display props.
 *
 * Every rule is nullable, same rationale as StakeholderProfileUpdateRequest
 * — a progressively-filled singleton record with no "required at insert"
 * set.
 *
 * TIER 1/TIER 2 CUTOVER (lookup-normalization ADR, Step 2+3): `available_isps`/
 * `subscribed_isps` are JSON arrays of `isp_providers` row ids (Tier 1 —
 * no type-scoping needed, it's a dedicated table, same pattern IspAccount
 * uses for its own `isp_provider_id`). `mobile_data_quality` moves to
 * ConnectivityLibrary/ConnectivityLibraryType::SignalQuality (already-live
 * table/enum, just repointed — IspAccount validates its own signal-quality
 * fields against the same type). `mobile_signal_types`/`coverage_areas`
 * are now arrays of `connectivity_libraries` row ids, type-scoped to
 * MobileNetworkSignal/CoverageArea respectively — the two
 * ConnectivityLibraryType cases IspAccount never touched; this is their
 * first real consumer. `electricity_sources` is an array of
 * `stakeholder_libraries` row ids, type-scoped to
 * StakeholderLibraryType::SourceOfElectricity — a deliberate cross-domain
 * read per the ADR (this survey shares that vocabulary with
 * StakeholderProfile, which doesn't itself consume it as a field but the
 * table is the steward). Every array element validates via Rule::exists(),
 * same shape as StakeholderProfileUpdateRequest's array fields.
 */
class InternetConnectivitySurveyUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorize against a transient (unsaved) instance rather than
        // `firstOrCreate([])` — InternetConnectivitySurveyPolicy never
        // inspects the model instance (only hasPermissionTo()), so this is
        // authorization-equivalent, but doesn't create the singleton row
        // as a side effect of an unauthorized request.
        return $this->user()->can('update', new InternetConnectivitySurvey);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $ispProviderCount = count(IspProvider::activeValues());
        $mobileSignalTypes = ConnectivityLibrary::activeValues(ConnectivityLibraryType::MobileNetworkSignal);
        $coverageAreas = ConnectivityLibrary::activeValues(ConnectivityLibraryType::CoverageArea);
        $electricitySources = StakeholderLibrary::activeValues(StakeholderLibraryType::SourceOfElectricity);

        return [
            'has_isp_in_area' => ['nullable', 'boolean'],

            'available_isps' => ['nullable', 'array', 'max:'.$ispProviderCount],
            'available_isps.*' => [
                'integer',
                Rule::exists('isp_providers', 'id')->where('is_active', true),
            ],
            'available_isps_other' => ['nullable', 'string', 'max:255'],

            'mobile_signal_types' => ['nullable', 'array', 'max:'.count($mobileSignalTypes)],
            'mobile_signal_types.*' => [
                'integer',
                Rule::exists('connectivity_libraries', 'id')
                    ->where('type', ConnectivityLibraryType::MobileNetworkSignal->value)
                    ->where('is_active', true),
            ],

            'has_mobile_data_connectivity' => ['nullable', 'boolean'],
            'mobile_data_quality' => ['nullable', 'string', Rule::in(ConnectivityLibrary::activeValues(ConnectivityLibraryType::SignalQuality))],

            'subscribes_to_isp' => ['nullable', 'boolean'],
            'subscribed_isps' => ['nullable', 'array', 'max:'.$ispProviderCount],
            'subscribed_isps.*' => [
                'integer',
                Rule::exists('isp_providers', 'id')->where('is_active', true),
            ],

            'insufficient_bandwidth_explanation' => ['nullable', 'string'],

            'coverage_areas' => ['nullable', 'array', 'max:'.count($coverageAreas)],
            'coverage_areas.*' => [
                'integer',
                Rule::exists('connectivity_libraries', 'id')
                    ->where('type', ConnectivityLibraryType::CoverageArea->value)
                    ->where('is_active', true),
            ],
            'coverage_areas_other' => ['nullable', 'string', 'max:255'],

            'dict_free_wifi_recipient' => ['nullable', 'boolean'],
            'dict_free_wifi_rating' => ['nullable', 'integer', 'min:1', 'max:5'],

            'has_sufficient_bandwidth' => ['nullable', 'boolean'],
            'no_subscription_reason' => ['nullable', 'string'],

            'has_electricity_source' => ['nullable', 'boolean'],
            'electricity_sources' => ['nullable', 'array', 'max:'.count($electricitySources)],
            'electricity_sources.*' => [
                'integer',
                Rule::exists('stakeholder_libraries', 'id')
                    ->where('type', StakeholderLibraryType::SourceOfElectricity->value)
                    ->where('is_active', true),
            ],
            'primarily_solar_powered' => ['nullable', 'boolean'],
            'frequent_brownouts' => ['nullable', 'boolean'],

            'rooms_other_use' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
