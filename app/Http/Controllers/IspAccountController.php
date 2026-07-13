<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ConnectivityLibraryType;
use App\Enums\EquipmentLibraryType;
use App\Http\Controllers\Concerns\HasLookupOptions;
use App\Http\Requests\IspAccountStoreRequest;
use App\Http\Requests\IspAccountUpdateRequest;
use App\Models\AuditLog;
use App\Models\ConnectivityLibrary;
use App\Models\EquipmentLibrary;
use App\Models\IspAccount;
use App\Models\IspProvider;
use App\Models\IspSpeedTest;
use App\Models\IspSubscriptionCost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IspAccountController extends Controller
{
    use HasLookupOptions;

    /**
     * Tier 1 reference tables used across the ISP account create/edit
     * forms — lookup-normalization ADR, Step 2+3 (IspAccount only).
     *
     * @var array<string, class-string>
     */
    private const TIER1_FORM_MODELS = [
        'isp_provider' => IspProvider::class,
    ];

    /**
     * Tier 2 library types backed by this resource's own domain table
     * (connectivity_libraries), used across the create/edit forms.
     *
     * @var array<string, ConnectivityLibraryType>
     */
    private const CONNECTIVITY_FORM_TYPES = [
        'school_level_coverage' => ConnectivityLibraryType::SchoolLevelCoverage,
        'subscription_type' => ConnectivityLibraryType::SubscriptionType,
        'isp_connection_type' => ConnectivityLibraryType::IspConnectionType,
        'purpose_of_subscription' => ConnectivityLibraryType::PurposeOfSubscription,
        'signal_quality' => ConnectivityLibraryType::SignalQuality,
    ];

    /**
     * Tier 2 library types reused from Equipment's already-migrated
     * equipment_libraries table (mode_of_acquisition/source_of_acquisition/
     * fund_source) — a deliberate cross-domain read per the ADR, not
     * duplicated into connectivity_libraries.
     *
     * @var array<string, EquipmentLibraryType>
     */
    private const EQUIPMENT_FORM_TYPES = [
        'mode_of_acquisition' => EquipmentLibraryType::ModeOfAcquisition,
        'source_of_acquisition' => EquipmentLibraryType::SourceOfAcquisition,
        'source_of_funds' => EquipmentLibraryType::SourceOfFunds,
    ];

    /**
     * Tier 1 reference tables used on the index page's filter bar — only
     * the provider dropdown is lookup-backed; the "active/inactive" status
     * filter is a plain boolean, hardcoded client-side (see
     * IspAccount::scopeStatus()).
     *
     * @var array<string, class-string>
     */
    private const TIER1_FILTER_MODELS = [
        'isp_provider' => IspProvider::class,
    ];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', IspAccount::class);

        $ispProviderId = $request->filled('isp') ? $request->integer('isp') : null;

        $ispAccounts = IspAccount::query()
            ->search($request->string('search')->toString() ?: null)
            ->provider($ispProviderId)
            ->status($request->string('status')->toString() ?: null)
            ->with('ispProvider:id,name')
            // Groups by provider first, same as the pre-cutover
            // ->orderBy('isp') did, now via the FK id rather than a join
            // to the reference table's name — the id doesn't sort
            // alphabetically, but it does keep each provider's accounts
            // adjacent, which was the actual behavior worth preserving.
            ->orderBy('isp_provider_id')
            ->orderBy('isp_billing_account_no')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (IspAccount $account) => [
                'id' => $account->id,
                'isp' => $account->ispProvider?->name,
                'isp_billing_account_no' => $account->isp_billing_account_no,
                'subscription_type' => $account->subscription_type,
                'isp_connection_type' => $account->isp_connection_type,
                'cost_per_month' => (float) $account->cost_per_month,
                'contract_start_date' => $account->contract_start_date?->toDateString(),
                'contract_end_date' => $account->contract_end_date?->toDateString(),
                'inactive_contract' => $account->inactive_contract,
                'overall_signal_quality' => $account->overall_signal_quality,
            ]);

        return Inertia::render('isp-accounts/Index', [
            'ispAccounts' => $ispAccounts,
            'filters' => [
                'search' => $request->string('search')->toString() ?: null,
                'isp' => $ispProviderId,
                'status' => $request->string('status')->toString() ?: null,
            ],
            'options' => $this->referenceOptions(self::TIER1_FILTER_MODELS),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', IspAccount::class);

        return Inertia::render('isp-accounts/Create', [
            'options' => $this->formOptions(),
        ]);
    }

    public function store(IspAccountStoreRequest $request): RedirectResponse
    {
        $ispAccount = IspAccount::create($request->validated());

        AuditLog::record('isp_account.created', $ispAccount, ['attributes' => $request->validated()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('ISP account created.')]);

        return to_route('isp-accounts.edit', $ispAccount);
    }

    public function edit(IspAccount $ispAccount): Response
    {
        $this->authorize('update', $ispAccount);

        // Both history lists are ordered by created_at (row-insertion
        // order), the same convention EquipmentController uses for
        // transaction history — not by their own domain date columns
        // (tested_at / contract dates), which can legitimately be
        // backdated relative to when the row was actually logged.
        $ispAccount->load([
            'ispProvider:id,name',
            'speedTests' => fn ($query) => $query->latest('created_at'),
            'subscriptionCosts' => fn ($query) => $query->latest('created_at'),
        ]);

        return Inertia::render('isp-accounts/Edit', [
            'ispAccount' => $this->ispAccountProps($ispAccount),
            'options' => $this->formOptions(),
            'speedTestOptions' => $this->libraryOptions(ConnectivityLibrary::class, ['signal_quality' => ConnectivityLibraryType::SignalQuality]),
        ]);
    }

    public function update(IspAccountUpdateRequest $request, IspAccount $ispAccount): RedirectResponse
    {
        $before = $ispAccount->getOriginal();

        $ispAccount->update($request->validated());

        $changes = collect($ispAccount->getChanges())
            ->except(['updated_at'])
            ->mapWithKeys(fn ($new, $key) => [$key => ['old' => $before[$key] ?? null, 'new' => $new]])
            ->all();

        AuditLog::record('isp_account.updated', $ispAccount, ['changes' => $changes]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('ISP account updated.')]);

        return to_route('isp-accounts.edit', $ispAccount);
    }

    public function destroy(IspAccount $ispAccount): RedirectResponse
    {
        $this->authorize('delete', $ispAccount);

        $ispAccount->loadMissing('ispProvider:id,name');

        AuditLog::record('isp_account.deleted', $ispAccount, [
            'isp' => $ispAccount->ispProvider?->name,
            'isp_billing_account_no' => $ispAccount->isp_billing_account_no,
        ]);

        $ispAccount->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('ISP account deleted.')]);

        return to_route('isp-accounts.index');
    }

    /**
     * Combined Tier 1 (id-valued) + Tier 2 (string-valued, both
     * ConnectivityLibrary-backed and the reused EquipmentLibrary-backed
     * fields) options for the Create/Edit forms.
     *
     * @return array<string, array<int, array{value: mixed, label: string}>>
     */
    private function formOptions(): array
    {
        return [
            ...$this->referenceOptions(self::TIER1_FORM_MODELS),
            ...$this->libraryOptions(ConnectivityLibrary::class, self::CONNECTIVITY_FORM_TYPES),
            ...$this->libraryOptions(EquipmentLibrary::class, self::EQUIPMENT_FORM_TYPES),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ispAccountProps(IspAccount $ispAccount): array
    {
        return [
            'id' => $ispAccount->id,
            'isp' => $ispAccount->ispProvider?->name,
            'isp_provider_id' => $ispAccount->isp_provider_id,
            'cost_per_month' => (float) $ispAccount->cost_per_month,
            'isp_billing_account_no' => $ispAccount->isp_billing_account_no,
            'package_purchased_inclusion' => $ispAccount->package_purchased_inclusion,

            'school_area_coverage' => $ispAccount->school_area_coverage,
            'min_speed' => (float) $ispAccount->min_speed,
            'max_speed' => (float) $ispAccount->max_speed,

            'subscription_type' => $ispAccount->subscription_type,
            'isp_connection_type' => $ispAccount->isp_connection_type,

            'contract_start_date' => $ispAccount->contract_start_date?->toDateString(),
            'contract_end_date' => $ispAccount->contract_end_date?->toDateString(),
            'inactive_contract' => $ispAccount->inactive_contract,

            'purpose_of_subscription' => $ispAccount->purpose_of_subscription,

            'mode_of_acquisition' => $ispAccount->mode_of_acquisition,
            'source_of_acquisition' => $ispAccount->source_of_acquisition,
            'donor' => $ispAccount->donor,
            'fund_source' => $ispAccount->fund_source,

            'number_access_points_linked' => $ispAccount->number_access_points_linked,
            'locations_access_points' => $ispAccount->locations_access_points,

            'number_admin_area_covered' => $ispAccount->number_admin_area_covered,
            'rate_connectivity_admin_areas' => $ispAccount->rate_connectivity_admin_areas,
            'number_classrooms_covered' => $ispAccount->number_classrooms_covered,
            'rate_connectivity_classroom' => $ispAccount->rate_connectivity_classroom,

            'overall_signal_quality' => $ispAccount->overall_signal_quality,
            'rate_isp_service' => $ispAccount->rate_isp_service,

            'speed_tests' => $ispAccount->speedTests->map(fn (IspSpeedTest $test) => [
                'id' => $test->id,
                'download_speed' => (float) $test->download_speed,
                'upload_speed' => (float) $test->upload_speed,
                'ping' => $test->ping,
                'tested_at' => $test->tested_at->toIso8601String(),
                'signal_quality' => $test->signal_quality,
                'rate_isp_service' => $test->rate_isp_service,
                'created_at' => $test->created_at?->toIso8601String(),
            ])->all(),

            'subscription_costs' => $ispAccount->subscriptionCosts->map(fn (IspSubscriptionCost $cost) => [
                'id' => $cost->id,
                'contract_start_date' => $cost->contract_start_date?->toDateString(),
                'contract_end_date' => $cost->contract_end_date?->toDateString(),
                'total_amount_spent' => $cost->total_amount_spent !== null ? (float) $cost->total_amount_spent : null,
                'contract_projected_start_date' => $cost->contract_projected_start_date?->toDateString(),
                'contract_projected_end_date' => $cost->contract_projected_end_date?->toDateString(),
                'total_projected_expenditure' => $cost->total_projected_expenditure !== null ? (float) $cost->total_projected_expenditure : null,
                'created_at' => $cost->created_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
