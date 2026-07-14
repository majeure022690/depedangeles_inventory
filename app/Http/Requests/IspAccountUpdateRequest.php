<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ConnectivityLibraryType;
use App\Enums\EquipmentLibraryType;
use App\Models\ConnectivityLibrary;
use App\Models\EquipmentLibrary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IspAccountUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('ispAccount'));
    }

    /**
     * Identical rule set to IspAccountStoreRequest — unlike Equipment's
     * property_no, isp_billing_account_no is deliberately not unique (see
     * the create_isp_accounts_table migration's SCHEMA DECISION comment),
     * so there's no ignore() rule to add on update.
     *
     * See IspAccountStoreRequest's doc-comment for the Tier 1/Tier 2 split
     * rationale (lookup-normalization ADR, Step 2+3 — IspAccount only).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'isp_provider_id' => ['required', 'integer', Rule::exists('isp_providers', 'id')->where('is_active', true)],
            'cost_per_month' => ['required', 'numeric', 'min:0'],
            'isp_billing_account_no' => ['required', 'string', 'max:255'],
            'package_purchased_inclusion' => ['required', 'string'],

            'school_area_coverage' => ['required', 'string', Rule::in(ConnectivityLibrary::activeValues(ConnectivityLibraryType::SchoolLevelCoverage))],
            'min_speed' => ['nullable', 'numeric', 'min:0'],
            'max_speed' => ['nullable', 'numeric', 'min:0', 'gte:min_speed'],

            'subscription_type' => ['required', 'string', Rule::in(ConnectivityLibrary::activeValues(ConnectivityLibraryType::SubscriptionType))],
            'isp_connection_type' => ['required', 'string', Rule::in(ConnectivityLibrary::activeValues(ConnectivityLibraryType::IspConnectionType))],

            'contract_start_date' => ['nullable', 'date'],
            'contract_end_date' => ['nullable', 'date'],
            'inactive_contract' => ['boolean'],

            'purpose_of_subscription' => ['required', 'string', Rule::in(ConnectivityLibrary::activeValues(ConnectivityLibraryType::PurposeOfSubscription))],

            'mode_of_acquisition' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::ModeOfAcquisition))],
            'source_of_acquisition' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::SourceOfAcquisition))],
            'donor' => ['nullable', 'string', 'max:255'],
            'fund_source' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::SourceOfFunds))],

            'number_access_points_linked' => ['nullable', 'integer', 'min:0'],
            'locations_access_points' => ['nullable', 'string'],

            'number_admin_area_covered' => ['nullable', 'integer', 'min:0'],
            'rate_connectivity_admin_areas' => ['nullable', 'string', Rule::in(ConnectivityLibrary::activeValues(ConnectivityLibraryType::SignalQuality))],
            'number_classrooms_covered' => ['nullable', 'integer', 'min:0'],
            'rate_connectivity_classroom' => ['nullable', 'string', Rule::in(ConnectivityLibrary::activeValues(ConnectivityLibraryType::SignalQuality))],

            'overall_signal_quality' => ['required', 'string', Rule::in(ConnectivityLibrary::activeValues(ConnectivityLibraryType::SignalQuality))],
            'rate_isp_service' => ['nullable', 'integer', 'min:1', 'max:5'],
        ];
    }
}
