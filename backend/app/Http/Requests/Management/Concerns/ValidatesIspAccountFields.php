<?php

declare(strict_types=1);

namespace App\Http\Requests\Management\Concerns;

use App\Enums\ConnectivityLibraryType;
use App\Enums\EquipmentLibraryType;
use App\Models\ConnectivityLibrary;
use App\Models\EquipmentLibrary;
use Illuminate\Validation\Rule;

/**
 * Fields shared by create and update. isp_billing_account_no is
 * deliberately not unique, so update needs no ignore() rule.
 */
trait ValidatesIspAccountFields
{
    /**
     * @return array<string, mixed>
     */
    protected function ispAccountRules(): array
    {
        $signalQualities = ConnectivityLibrary::activeValues(ConnectivityLibraryType::SignalQuality);

        return [
            'isp_provider_id' => ['required', 'integer', Rule::exists('isp_providers', 'id')->where('is_active', true)],
            'cost_per_month' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'isp_billing_account_no' => ['required', 'string', 'max:255'],
            'package_purchased_inclusion' => ['required', 'string'],

            'school_area_coverage' => ['required', 'string', Rule::in(ConnectivityLibrary::activeValues(ConnectivityLibraryType::SchoolLevelCoverage))],
            'min_speed' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'max_speed' => ['nullable', 'numeric', 'min:0', 'max:999999.99', Rule::when($this->filled('min_speed'), 'gte:min_speed')],
            'subscriber_speed_upload' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'subscriber_speed_download' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],

            'subscription_type' => ['required', 'string', Rule::in(ConnectivityLibrary::activeValues(ConnectivityLibraryType::SubscriptionType))],
            'isp_connection_type' => ['required', 'string', Rule::in(ConnectivityLibrary::activeValues(ConnectivityLibraryType::IspConnectionType))],

            'contract_start_date' => ['nullable', 'date'],
            'contract_end_date' => ['nullable', 'date', 'after_or_equal:contract_start_date'],
            'inactive_contract' => ['boolean'],

            'purpose_of_subscription' => ['required', 'string', Rule::in(ConnectivityLibrary::activeValues(ConnectivityLibraryType::PurposeOfSubscription))],

            'mode_of_acquisition' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::ModeOfAcquisition))],
            'source_of_acquisition' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::SourceOfAcquisition))],
            'donor' => ['nullable', 'string', 'max:255'],
            'fund_source' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::SourceOfFunds))],

            'number_access_points_linked' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'locations_access_points' => ['nullable', 'string'],

            'number_admin_area_covered' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'rate_connectivity_admin_areas' => ['nullable', 'string', Rule::in($signalQualities)],
            'number_classrooms_covered' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'rate_connectivity_classroom' => ['nullable', 'string', Rule::in($signalQualities)],

            'overall_signal_quality' => ['required', 'string', Rule::in($signalQualities)],
            'rate_isp_service' => ['nullable', 'integer', 'min:1', 'max:5'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function ispAccountAttributes(): array
    {
        return [
            'office_id' => 'office',
            'isp_provider_id' => 'ISP',
            'isp_billing_account_no' => 'billing account number',
            'package_purchased_inclusion' => 'package inclusions',
            'school_area_coverage' => 'school level coverage',
            'min_speed' => 'minimum speed',
            'max_speed' => 'maximum speed',
            'subscriber_speed_upload' => 'subscriber upload speed',
            'subscriber_speed_download' => 'subscriber download speed',
            'isp_connection_type' => 'connection type',
            'fund_source' => 'source of funds',
            'number_access_points_linked' => 'number of access points linked',
            'locations_access_points' => 'access point locations',
            'number_admin_area_covered' => 'number of admin areas covered',
            'rate_connectivity_admin_areas' => 'admin area connectivity rating',
            'number_classrooms_covered' => 'number of classrooms covered',
            'rate_connectivity_classroom' => 'classroom connectivity rating',
            'rate_isp_service' => 'ISP service rating',
        ];
    }
}
