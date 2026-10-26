<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Enums\ConnectivityLibraryType;
use App\Enums\DataScope;
use App\Enums\EquipmentLibraryType;
use App\Http\Controllers\Api\Management\Concerns\HasLookupOptions;
use App\Http\Controllers\Api\Management\Concerns\ResolvesListParameters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Management\StoreIspAccountRequest;
use App\Http\Requests\Management\UpdateIspAccountRequest;
use App\Http\Resources\Management\IspAccountResource;
use App\Models\ConnectivityLibrary;
use App\Models\EquipmentLibrary;
use App\Models\IspAccount;
use App\Models\IspProvider;
use App\Models\Office;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Audit: create/update/delete rows come from IspAccount's LogsActivity;
 * speed tests and subscription costs are logged by their own controllers.
 */
class IspAccountController extends Controller
{
    use HasLookupOptions, ResolvesListParameters;

    /**
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
     * Shared with Equipment's library (a deliberate cross-domain read).
     *
     * @var array<string, EquipmentLibraryType>
     */
    private const EQUIPMENT_FORM_TYPES = [
        'mode_of_acquisition' => EquipmentLibraryType::ModeOfAcquisition,
        'source_of_acquisition' => EquipmentLibraryType::SourceOfAcquisition,
        'source_of_funds' => EquipmentLibraryType::SourceOfFunds,
    ];

    /**
     * @var array<string, string>
     */
    private const SORTABLE = [
        'isp_billing_account_no' => 'isp_billing_account_no',
        'cost_per_month' => 'cost_per_month',
        'contract_start_date' => 'contract_start_date',
        'contract_end_date' => 'contract_end_date',
        'created_at' => 'created_at',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', IspAccount::class);

        $query = IspAccount::query()
            ->visibleTo($request->user())
            ->search($request->string('search')->toString() ?: null)
            ->provider($request->filled('isp') ? $request->integer('isp') : null)
            ->status($request->string('status')->toString() ?: null)
            ->with(['ispProvider:id,name', 'office:id,office_name']);

        if (! $this->applySort($query, $request, self::SORTABLE)) {
            // Keeps each provider's accounts together.
            $query->orderBy('isp_provider_id')->orderBy('isp_billing_account_no');
        }

        return IspAccountResource::collection($query->paginate($this->perPage($request))->withQueryString())
            ->additional(['message' => 'Success'])
            ->response();
    }

    public function options(): JsonResponse
    {
        $providers = IspProvider::activeOptions();

        return response()->json([
            'data' => [
                'form' => [
                    'isp_provider' => $providers,
                    'office' => Office::activeOptions(),
                    ...$this->libraryOptions(ConnectivityLibrary::class, self::CONNECTIVITY_FORM_TYPES),
                    ...$this->libraryOptions(EquipmentLibrary::class, self::EQUIPMENT_FORM_TYPES),
                ],
                'filters' => ['isp_provider' => $providers],
                'speed_test' => $this->libraryOptions(ConnectivityLibrary::class, ['signal_quality' => ConnectivityLibraryType::SignalQuality]),
            ],
            'message' => 'Success',
        ]);
    }

    public function store(StoreIspAccountRequest $request): JsonResponse
    {
        $attributes = $request->validated();

        if ($request->user()->dataScope() !== DataScope::All) {
            $attributes['office_id'] = $request->user()->office_id;
        }

        $ispAccount = IspAccount::create($attributes);

        return new IspAccountResource($ispAccount->load(['ispProvider:id,name', 'office:id,office_name']))
            ->additional(['message' => 'ISP account created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(IspAccount $ispAccount): JsonResponse
    {
        $this->authorize('view', $ispAccount);

        // History is listed in the order rows were logged, not by their
        // own (backdatable) tested_at/contract dates.
        $ispAccount->load([
            'ispProvider:id,name',
            'office:id,office_name',
            'speedTests' => fn ($query) => $query->latest('created_at')->latest('id'),
            'subscriptionCosts' => fn ($query) => $query->latest('created_at')->latest('id'),
        ]);

        return new IspAccountResource($ispAccount)
            ->additional(['message' => 'Success'])
            ->response();
    }

    public function update(UpdateIspAccountRequest $request, IspAccount $ispAccount): JsonResponse
    {
        $this->authorize('update', $ispAccount);

        $ispAccount->update($request->validated());

        return new IspAccountResource($ispAccount->load(['ispProvider:id,name', 'office:id,office_name']))
            ->additional(['message' => 'ISP account updated.'])
            ->response();
    }

    public function destroy(IspAccount $ispAccount): Response
    {
        $this->authorize('delete', $ispAccount);

        $ispAccount->delete();

        return response()->noContent();
    }
}
