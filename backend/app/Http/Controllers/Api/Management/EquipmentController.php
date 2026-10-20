<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Enums\EquipmentLibraryType;
use App\Enums\Permission;
use App\Http\Controllers\Api\Management\Concerns\HasLookupOptions;
use App\Http\Controllers\Api\Management\Concerns\ResolvesListParameters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Management\StoreEquipmentRequest;
use App\Http\Requests\Management\UpdateEquipmentRequest;
use App\Http\Resources\Management\EquipmentResource;
use App\Models\Brand;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\EquipmentClassification;
use App\Models\EquipmentCondition;
use App\Models\EquipmentLibrary;
use App\Models\ItemType;
use App\Models\Personnel;
use App\Models\User;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Audit: create/update/delete rows come from Equipment's LogsActivity;
 * accountability moves are logged by EquipmentAccountabilityService.
 */
class EquipmentController extends Controller
{
    use HasLookupOptions, ResolvesListParameters;

    /**
     * @var array<string, class-string>
     */
    private const TIER1_FORM_MODELS = [
        'item_type' => ItemType::class,
        'brand' => Brand::class,
        'category' => EquipmentCategory::class,
        'classification' => EquipmentClassification::class,
        'condition' => EquipmentCondition::class,
    ];

    /**
     * @var array<string, EquipmentLibraryType>
     */
    private const TIER2_FORM_TYPES = [
        'unit_of_measure' => EquipmentLibraryType::UnitOfMeasure,
        'dcp_package' => EquipmentLibraryType::DcpPackage,
        'mode_of_acquisition' => EquipmentLibraryType::ModeOfAcquisition,
        'source_of_acquisition' => EquipmentLibraryType::SourceOfAcquisition,
        'source_of_funds' => EquipmentLibraryType::SourceOfFunds,
        'allotment_class' => EquipmentLibraryType::AllotmentClass,
        'disposition_status' => EquipmentLibraryType::DispositionStatus,
    ];

    /**
     * @var array<string, class-string>
     */
    private const TIER1_FILTER_MODELS = [
        'condition' => EquipmentCondition::class,
        'category' => EquipmentCategory::class,
    ];

    /**
     * @var array<string, EquipmentLibraryType>
     */
    private const TIER2_FILTER_TYPES = [
        'disposition_status' => EquipmentLibraryType::DispositionStatus,
    ];

    /**
     * @var array<string, EquipmentLibraryType>
     */
    private const TRANSACTION_FORM_TYPES = [
        'transaction_type' => EquipmentLibraryType::TransactionType,
        'supporting_document_type' => EquipmentLibraryType::SupportingDocumentType,
    ];

    /**
     * @var array<string, string>
     */
    private const SORTABLE = [
        'property_no' => 'property_no',
        'serial_number' => 'serial_number',
        'model' => 'model',
        'disposition_status' => 'disposition_status',
        'acquisition_cost' => 'acquisition_cost',
        'received_date' => 'received_date',
        'created_at' => 'created_at',
    ];

    private const LIST_RELATIONS = [
        'currentAccountableOfficer:id,full_name',
        'currentEndUser:id,full_name',
        'itemType:id,name',
        'brand:id,name',
        'equipmentCategory:id,name',
        'equipmentClassification:id,name',
        'equipmentCondition:id,name',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Equipment::class);

        $query = Equipment::query()
            ->visibleTo($request->user())
            ->search($request->string('search')->toString() ?: null)
            ->condition($request->filled('condition') ? $request->integer('condition') : null)
            ->category($request->filled('category') ? $request->integer('category') : null)
            ->dispositionStatus($request->string('disposition_status')->toString() ?: null)
            ->with(self::LIST_RELATIONS);

        if (! $this->applySort($query, $request, self::SORTABLE)) {
            // Pending items (no property number yet) sort last, not first.
            $query->orderByRaw('property_no IS NULL')->orderBy('property_no');
        }

        return EquipmentResource::collection($query->paginate($this->perPage($request))->withQueryString())
            ->additional(['message' => 'Success'])
            ->response();
    }

    /**
     * Form, list-filter and transaction-form options in one payload.
     * `personnel` (active personnel, for the transaction form) is only
     * sent to users who may record transactions.
     */
    public function options(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = [
            'form' => [
                ...$this->referenceOptions(self::TIER1_FORM_MODELS),
                ...$this->libraryOptions(EquipmentLibrary::class, self::TIER2_FORM_TYPES),
            ],
            'filters' => [
                ...$this->referenceOptions(self::TIER1_FILTER_MODELS),
                ...$this->libraryOptions(EquipmentLibrary::class, self::TIER2_FILTER_TYPES),
            ],
            'transaction' => $this->libraryOptions(EquipmentLibrary::class, self::TRANSACTION_FORM_TYPES),
        ];

        if ($user->hasPermissionTo(Permission::EquipmentTransactionsCreate)) {
            $data['personnel'] = Personnel::query()
                ->active()
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(['id', 'full_name'])
                ->map(fn (Personnel $person) => ['value' => $person->id, 'label' => $person->full_name])
                ->all();
        }

        return response()->json(['data' => $data, 'message' => 'Success']);
    }

    public function store(StoreEquipmentRequest $request): JsonResponse
    {
        $equipment = Equipment::create($request->validated());

        return new EquipmentResource($equipment->fresh(self::LIST_RELATIONS))
            ->additional(['message' => 'Equipment record created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Equipment $equipment): JsonResponse
    {
        $this->authorize('view', $equipment);

        $equipment->load([
            ...self::LIST_RELATIONS,
            'transactions' => fn ($query) => $query
                ->with(['accountableOfficer:id,full_name', 'endUser:id,full_name', 'receivedBy:id,full_name'])
                ->latest('created_at')
                ->latest('id'),
        ]);

        return new EquipmentResource($equipment)
            ->additional(['message' => 'Success'])
            ->response();
    }

    public function update(UpdateEquipmentRequest $request, Equipment $equipment): JsonResponse
    {
        $this->authorize('update', $equipment);

        $equipment->update($request->validated());

        return new EquipmentResource($equipment->fresh(self::LIST_RELATIONS))
            ->additional(['message' => 'Equipment record updated.'])
            ->response();
    }

    public function destroy(Equipment $equipment): Response
    {
        $this->authorize('delete', $equipment);

        $equipment->delete();

        return response()->noContent();
    }

    /**
     * The record's QR label as a PNG. Same permission as viewing the
     * record: the image only encodes the record's own URL.
     */
    public function qrCode(Equipment $equipment): Response
    {
        $this->authorize('view', $equipment);

        // EquipmentObserver fills qr_code as soon as the row has an id.
        $data = $equipment->qr_code ?? route('equipment.edit', $equipment);

        $result = (new Builder(
            writer: new PngWriter,
            data: $data,
            size: 300,
            margin: 10,
        ))->build();

        // property_no is free text: strip control chars and path separators
        // (rejected by makeDisposition) and give an ASCII-only fallback.
        $filename = 'equipment-'.preg_replace('~[\x00-\x1F\x7F/\\\\]+~u', '-', (string) ($equipment->property_no ?? $equipment->id)).'-qr.png';
        $fallback = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);

        return response($result->getString(), 200, [
            'Content-Type' => $result->getMimeType(),
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $filename, $fallback),
        ]);
    }
}
