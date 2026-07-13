<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ConnectivityLibraryType;
use App\Enums\StakeholderLibraryType;
use App\Http\Controllers\Concerns\HasLookupOptions;
use App\Http\Requests\InternetConnectivitySurveyUpdateRequest;
use App\Models\AuditLog;
use App\Models\ConnectivityLibrary;
use App\Models\InternetConnectivitySurvey;
use App\Models\IspAccount;
use App\Models\IspProvider;
use App\Models\IspSubscriptionCost;
use App\Models\StakeholderLibrary;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SINGLETON controller (see App\Models\InternetConnectivitySurvey) — same
 * treatment as StakeholderProfileController: only edit()/update(), one
 * row for the whole application via `firstOrCreate([])`, no index/create/
 * store/destroy.
 *
 * edit() additionally computes the survey's "protected"/derived aggregate
 * fields (Total ISPs, Total Cost/month, Total Amount Spent, Total
 * Projected Expenditure, Rooms Covered Admin/Classroom, Total Access
 * Points) live from IspAccount/IspSubscriptionCost and passes them as a
 * separate read-only `aggregates` prop — see
 * InternetConnectivitySurvey's doc-comment for why these are never
 * columns on this table and never part of the writable Form Request
 * payload.
 */
class InternetConnectivitySurveyController extends Controller
{
    use HasLookupOptions;

    public function edit(): Response
    {
        // Authorize against a transient (unsaved) instance BEFORE touching
        // the DB — InternetConnectivitySurveyPolicy never inspects the
        // model instance (only hasPermissionTo()), so this is
        // authorization-equivalent to the persisted row, but a
        // `pending`-role user's GET no longer creates the singleton row
        // before being 403'd.
        $this->authorize('view', new InternetConnectivitySurvey);

        $internetConnectivitySurvey = InternetConnectivitySurvey::firstOrCreate([]);

        return Inertia::render('internet-connectivity-survey/Edit', [
            'internetConnectivitySurvey' => $this->surveyProps($internetConnectivitySurvey),
            'aggregates' => $this->computeAggregates(),
            'options' => $this->formOptions(),
        ]);
    }

    public function update(InternetConnectivitySurveyUpdateRequest $request): RedirectResponse
    {
        $this->authorize('update', new InternetConnectivitySurvey);

        $internetConnectivitySurvey = InternetConnectivitySurvey::firstOrCreate([]);

        $before = $internetConnectivitySurvey->getOriginal();

        $internetConnectivitySurvey->update($request->validated());

        $changes = collect($internetConnectivitySurvey->getChanges())
            ->except(['updated_at'])
            ->mapWithKeys(fn ($new, $key) => [$key => ['old' => $before[$key] ?? null, 'new' => $new]])
            ->all();

        AuditLog::record('internet_connectivity_survey.updated', $internetConnectivitySurvey, ['changes' => $changes]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Internet connectivity survey updated.')]);

        return to_route('internet-connectivity-survey.edit');
    }

    /**
     * @return array<string, mixed>
     */
    private function surveyProps(InternetConnectivitySurvey $internetConnectivitySurvey): array
    {
        return [
            'id' => $internetConnectivitySurvey->id,

            'has_isp_in_area' => $internetConnectivitySurvey->has_isp_in_area,

            'available_isps' => $internetConnectivitySurvey->available_isps,
            'available_isps_other' => $internetConnectivitySurvey->available_isps_other,

            'mobile_signal_types' => $internetConnectivitySurvey->mobile_signal_types,

            'has_mobile_data_connectivity' => $internetConnectivitySurvey->has_mobile_data_connectivity,
            'mobile_data_quality' => $internetConnectivitySurvey->mobile_data_quality,

            'subscribes_to_isp' => $internetConnectivitySurvey->subscribes_to_isp,
            'subscribed_isps' => $internetConnectivitySurvey->subscribed_isps,

            'insufficient_bandwidth_explanation' => $internetConnectivitySurvey->insufficient_bandwidth_explanation,

            'coverage_areas' => $internetConnectivitySurvey->coverage_areas,
            'coverage_areas_other' => $internetConnectivitySurvey->coverage_areas_other,

            'dict_free_wifi_recipient' => $internetConnectivitySurvey->dict_free_wifi_recipient,
            'dict_free_wifi_rating' => $internetConnectivitySurvey->dict_free_wifi_rating,

            'has_sufficient_bandwidth' => $internetConnectivitySurvey->has_sufficient_bandwidth,
            'no_subscription_reason' => $internetConnectivitySurvey->no_subscription_reason,

            'has_electricity_source' => $internetConnectivitySurvey->has_electricity_source,
            'electricity_sources' => $internetConnectivitySurvey->electricity_sources,
            'primarily_solar_powered' => $internetConnectivitySurvey->primarily_solar_powered,
            'frequent_brownouts' => $internetConnectivitySurvey->frequent_brownouts,

            'rooms_other_use' => $internetConnectivitySurvey->rooms_other_use,
        ];
    }

    /**
     * Live-computed "protected" fields sourced from IspAccount /
     * IspSubscriptionCost — never stored on internet_connectivity_surveys.
     * IspAccount's default query already excludes soft-deleted rows, so
     * these totals only ever reflect active accounts.
     *
     * @return array<string, int|float>
     */
    private function computeAggregates(): array
    {
        return [
            'total_isps' => IspAccount::query()->count(),
            'total_cost_per_month' => (float) IspAccount::query()->sum('cost_per_month'),
            'total_amount_spent' => (float) IspSubscriptionCost::query()->sum('total_amount_spent'),
            'total_projected_expenditure' => (float) IspSubscriptionCost::query()->sum('total_projected_expenditure'),
            'rooms_covered_admin' => (int) IspAccount::query()->sum('number_admin_area_covered'),
            'rooms_covered_classroom' => (int) IspAccount::query()->sum('number_classrooms_covered'),
            'total_access_points' => (int) IspAccount::query()->sum('number_access_points_linked'),
        ];
    }

    /**
     * Options for the edit form's selects/checkbox-groups
     * (lookup-normalization ADR, Step 2+3). `isp_provider` is Tier 1
     * (referenceOptions() — id-valued, matching IspAccount's own
     * `isp_provider_id`), shared by both the `available_isps` and
     * `subscribed_isps` checkbox groups. `signal_quality` stays a single
     * string-valued select, sourced from ConnectivityLibrary (same table/
     * type IspAccount validates its own signal-quality fields against).
     * `mobile_network_signal` and `coverage_area` ship id-valued options
     * via libraryOptionsById() against ConnectivityLibrary — these are the
     * two ConnectivityLibraryType cases IspAccount never touched; this
     * survey is their first real consumer. `source_of_electricity` also
     * ships id-valued options via libraryOptionsById(), but against
     * StakeholderLibrary — a deliberate cross-domain read per the ADR.
     * Option keys are unchanged from the pre-cutover shape so the existing
     * Vue form doesn't need re-keying, only its bound value types change.
     *
     * @return array<string, array<int, array{value: mixed, label: string}>>
     */
    private function formOptions(): array
    {
        return [
            ...$this->referenceOptions(['isp_provider' => IspProvider::class]),
            ...$this->libraryOptions(ConnectivityLibrary::class, ['signal_quality' => ConnectivityLibraryType::SignalQuality]),
            'mobile_network_signal' => $this->libraryOptionsById(ConnectivityLibrary::class, ConnectivityLibraryType::MobileNetworkSignal),
            'coverage_area' => $this->libraryOptionsById(ConnectivityLibrary::class, ConnectivityLibraryType::CoverageArea),
            'source_of_electricity' => $this->libraryOptionsById(StakeholderLibrary::class, StakeholderLibraryType::SourceOfElectricity),
        ];
    }
}
