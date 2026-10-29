<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Enums\ConnectivityLibraryType;
use App\Enums\EquipmentLibraryType;
use App\Http\Requests\Management\Concerns\RestrictsScopedAssignments;
use App\Models\ConnectivityLibrary;
use App\Models\EquipmentLibrary;
use App\Models\IspAccount;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreIspAccountRequest extends FormRequest
{
    use RestrictsScopedAssignments;

    public function authorize(): bool
    {
        return $this->user()->can('create', IspAccount::class);
    }

    /**
     * TIER 1/TIER 2 SPLIT (lookup-normalization ADR, Step 2+3 — IspAccount
     * only): `isp_provider_id` is a real foreign key now — validated by
     * existence against its dedicated reference table, restricted to
     * active rows the same way Rule::in(activeValues()) used to for the
     * old `isp` string column. The remaining lookup-backed fields are
     * Tier 2 — still validated strings, repointed at
     * ConnectivityLibrary/ConnectivityLibraryType (this resource's own
     * domain table) except mode_of_acquisition/source_of_acquisition/
     * fund_source, which reuse the already-migrated
     * EquipmentLibrary/EquipmentLibraryType rows Equipment validates
     * against — a deliberate cross-domain read per the ADR, not a
     * duplicate table.
     *
     * REQUIRED FIELDS: Aligned with spreadsheet validation requirements.
     * Now required: min_speed, max_speed, contract_start_date,
     * contract_end_date, number_access_points_linked,
     * number_admin_area_covered, number_classrooms_covered,
     * rate_connectivity_classroom, rate_isp_service.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'isp_provider_id' => ['required', 'integer', Rule::exists('isp_providers', 'id')->where('is_active', true)],
            'office_id' => ['nullable', 'integer', $this->activeOfficeRule()],
            'cost_per_month' => ['required', 'numeric', 'min:0'],
            'isp_billing_account_no' => ['required', 'string', 'max:255'],
            'package_purchased_inclusion' => ['required', 'string'],

            'school_area_coverage' => ['required', 'string', Rule::in(ConnectivityLibrary::activeValues(ConnectivityLibraryType::SchoolLevelCoverage))],
            'min_speed' => ['required', 'numeric', 'min:0'],
            'max_speed' => ['required', 'numeric', 'min:0', 'gte:min_speed'],

            'subscription_type' => ['required', 'string', Rule::in(ConnectivityLibrary::activeValues(ConnectivityLibraryType::SubscriptionType))],
            'isp_connection_type' => ['required', 'string', Rule::in(ConnectivityLibrary::activeValues(ConnectivityLibraryType::IspConnectionType))],

            'contract_start_date' => ['required', 'date'],
            'contract_end_date' => ['required', 'date', 'after_or_equal:contract_start_date'],
            'inactive_contract' => ['boolean'],

            'purpose_of_subscription' => ['required', 'string', Rule::in(ConnectivityLibrary::activeValues(ConnectivityLibraryType::PurposeOfSubscription))],

            'mode_of_acquisition' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::ModeOfAcquisition))],
            'source_of_acquisition' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::SourceOfAcquisition))],
            'donor' => ['nullable', 'string', 'max:255'],
            'fund_source' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::SourceOfFunds))],

            'number_access_points_linked' => ['required', 'integer', 'min:0'],
            'locations_access_points' => ['nullable', 'string'],

            'number_admin_area_covered' => ['required', 'integer', 'min:0'],
            'rate_connectivity_admin_areas' => ['nullable', 'string', Rule::in(ConnectivityLibrary::activeValues(ConnectivityLibraryType::SignalQuality))],
            'number_classrooms_covered' => ['required', 'integer', 'min:0'],
            'rate_connectivity_classroom' => ['required', 'string', Rule::in(ConnectivityLibrary::activeValues(ConnectivityLibraryType::SignalQuality))],

            'overall_signal_quality' => ['required', 'string', Rule::in(ConnectivityLibrary::activeValues(ConnectivityLibraryType::SignalQuality))],
            'rate_isp_service' => ['required', 'integer', 'min:1', 'max:5'],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            $this->requireActorOffice(),
            $this->lockForScopedActors(['office_id' => [$this->user()->office_id]], allowBlank: true),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'office_id' => 'office',
            'isp_provider_id' => 'ISP',
            'isp_billing_account_no' => 'billing account number',
            'package_purchased_inclusion' => 'package inclusions',
            'school_area_coverage' => 'school level coverage',
            'min_speed' => 'minimum speed',
            'max_speed' => 'maximum speed',
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
