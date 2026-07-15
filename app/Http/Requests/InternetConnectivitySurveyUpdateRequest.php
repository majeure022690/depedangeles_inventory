<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ConnectivityLibraryType;
use App\Enums\StakeholderLibraryType;
use App\Models\ConnectivityLibrary;
use App\Models\InternetConnectivitySurvey;
use App\Models\IspProvider;
use App\Models\Office;
use App\Models\StakeholderLibrary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates one office's InternetConnectivitySurvey editable fields. The
 * live-computed aggregate fields (Total ISPs, etc.) are deliberately absent
 * — never columns on this table, never writable here.
 */
class InternetConnectivitySurveyUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Transient instance scoped to the route's office, so an
        // unauthorized request never creates that office's row.
        $office = $this->route('office');

        return $this->user()->can('update', new InternetConnectivitySurvey([
            'office_id' => $office instanceof Office ? $office->id : null,
        ]));
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
