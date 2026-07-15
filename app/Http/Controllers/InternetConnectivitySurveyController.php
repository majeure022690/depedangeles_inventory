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
use App\Models\IspProvider;
use App\Models\Office;
use App\Models\StakeholderLibrary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One row per Office (see docs/features/internet-connectivity-survey.md).
 * index() is the cross-office admin list; edit()/update() are scoped to
 * one {office}.
 */
class InternetConnectivitySurveyController extends Controller
{
    use HasLookupOptions;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', InternetConnectivitySurvey::class);

        $search = $request->string('search')->toString() ?: null;

        $offices = Office::query()
            ->with('internetConnectivitySurvey')
            ->when($search, fn ($query) => $query->where('office_name', 'like', "%{$search}%"))
            ->orderBy('office_name')
            ->paginate(20)
            ->withQueryString()
            ->through(function (Office $office) {
                $hasSurvey = $office->internetConnectivitySurvey?->hasAnswers() ?? false;

                return [
                    'id' => $office->id,
                    'office_name' => $office->office_name,
                    'office_type' => $office->office_type,
                    'school_id' => $office->school_id,
                    'has_survey' => $hasSurvey,
                    'updated_at' => $hasSurvey ? $office->internetConnectivitySurvey->updated_at?->toIso8601String() : null,
                ];
            });

        return Inertia::render('internet-connectivity-survey/Index', [
            'offices' => $offices,
            'filters' => [
                'search' => $search,
            ],
        ]);
    }

    public function edit(Office $office): Response
    {
        // Authorize a transient instance before firstOrCreate(), so a
        // wrong-office GET 403s without creating that office's row.
        $this->authorize('view', new InternetConnectivitySurvey(['office_id' => $office->id]));

        $internetConnectivitySurvey = InternetConnectivitySurvey::firstOrCreate(['office_id' => $office->id]);

        return Inertia::render('internet-connectivity-survey/Edit', [
            'office' => [
                'id' => $office->id,
                'office_name' => $office->office_name,
            ],
            'internetConnectivitySurvey' => $this->surveyProps($internetConnectivitySurvey),
            'options' => $this->formOptions(),
        ]);
    }

    public function update(InternetConnectivitySurveyUpdateRequest $request, Office $office): RedirectResponse
    {
        $this->authorize('update', new InternetConnectivitySurvey(['office_id' => $office->id]));

        $internetConnectivitySurvey = InternetConnectivitySurvey::firstOrCreate(['office_id' => $office->id]);

        $before = $internetConnectivitySurvey->getOriginal();

        $internetConnectivitySurvey->update($request->validated());

        $changes = collect($internetConnectivitySurvey->getChanges())
            ->except(['updated_at'])
            ->mapWithKeys(fn ($new, $key) => [$key => ['old' => $before[$key] ?? null, 'new' => $new]])
            ->all();

        AuditLog::record('internet_connectivity_survey.updated', $internetConnectivitySurvey, ['changes' => $changes]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Internet connectivity survey updated.')]);

        return to_route('internet-connectivity-surveys.edit', $office);
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
