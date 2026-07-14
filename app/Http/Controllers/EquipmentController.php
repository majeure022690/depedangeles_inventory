<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EquipmentLibraryType;
use App\Http\Controllers\Concerns\HasLookupOptions;
use App\Http\Requests\EquipmentStoreRequest;
use App\Http\Requests\EquipmentUpdateRequest;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\EquipmentClassification;
use App\Models\EquipmentCondition;
use App\Models\EquipmentLibrary;
use App\Models\EquipmentTransaction;
use App\Models\ItemType;
use App\Models\Personnel;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class EquipmentController extends Controller
{
    use HasLookupOptions;

    /**
     * Tier 1 reference tables used across Equipment's create/edit forms —
     * lookup-normalization ADR, Step 2. Keyed to match the old LookupType
     * values ('item_type', 'brand', 'category', 'classification',
     * 'condition') so the `options` prop's shape stays familiar even
     * though every entry is now id-valued rather than string-valued.
     *
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
     * Tier 2 library types used across Equipment's create/edit forms —
     * repoints the old LookupType-keyed set at EquipmentLibrary/
     * EquipmentLibraryType. Shape unchanged (string-valued options).
     *
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
     * Tier 1 reference tables used on the index page's filter bar — a
     * subset of the full form list (no need to ship item/brand/etc.
     * option lists just to render two filter dropdowns).
     *
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

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Equipment::class);

        $conditionId = $request->filled('condition') ? $request->integer('condition') : null;
        $categoryId = $request->filled('category') ? $request->integer('category') : null;
        $dispositionStatus = $request->string('disposition_status')->toString() ?: null;

        $equipment = Equipment::query()
            ->search($request->string('search')->toString() ?: null)
            ->condition($conditionId)
            ->category($categoryId)
            ->dispositionStatus($dispositionStatus)
            ->with([
                'currentAccountableOfficer:id,full_name',
                'currentEndUser:id,full_name',
                'itemType:id,name',
                'brand:id,name',
                'equipmentCategory:id,name',
                'equipmentCondition:id,name',
            ])
            // property_no is nullable now (pending-registration equipment
            // awaiting a property number). MySQL sorts NULL first on a
            // plain ASC orderBy, which would bury every already-tagged
            // item under a page of pending ones. Push nulls to the end
            // instead so the primary, fully-registered inventory listing
            // stays the default view; pending rows are still fully visible
            // further down/on later pages, just not first.
            ->orderByRaw('property_no IS NULL')
            ->orderBy('property_no')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Equipment $item) => [
                'id' => $item->id,
                'property_no' => $item->property_no,
                'item' => $item->itemType?->name,
                'brand_manufacturer' => $item->brand?->name,
                'model' => $item->model,
                'serial_number' => $item->serial_number,
                'category' => $item->equipmentCategory?->name,
                'equipment_condition' => $item->equipmentCondition?->name,
                'disposition_status' => $item->disposition_status,
                // Cast only when present: (float) null would silently
                // become 0.0, misrepresenting "pending, cost not yet on
                // file" as "acquisition cost is zero".
                'acquisition_cost' => $item->acquisition_cost !== null ? (float) $item->acquisition_cost : null,
                'current_accountable_officer' => $item->currentAccountableOfficer?->only(['id', 'full_name']),
                'current_end_user' => $item->currentEndUser?->only(['id', 'full_name']),
            ]);

        return Inertia::render('equipment/Index', [
            'equipment' => $equipment,
            'filters' => [
                'search' => $request->string('search')->toString() ?: null,
                'condition' => $conditionId,
                'category' => $categoryId,
                'disposition_status' => $dispositionStatus,
            ],
            'options' => [
                ...$this->referenceOptions(self::TIER1_FILTER_MODELS),
                ...$this->libraryOptions(EquipmentLibrary::class, self::TIER2_FILTER_TYPES),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Equipment::class);

        return Inertia::render('equipment/Create', [
            'options' => $this->formOptions(),
        ]);
    }

    public function store(EquipmentStoreRequest $request): RedirectResponse
    {
        $equipment = Equipment::create($request->validated());

        AuditLog::record('equipment.created', $equipment, ['attributes' => $request->validated()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Equipment record created.')]);

        return to_route('equipment.edit', $equipment);
    }

    public function edit(Equipment $equipment): Response
    {
        $this->authorize('update', $equipment);

        $equipment->load([
            'currentAccountableOfficer:id,full_name',
            'currentEndUser:id,full_name',
            'itemType:id,name',
            'brand:id,name',
            'equipmentCategory:id,name',
            'equipmentClassification:id,name',
            'equipmentCondition:id,name',
            'transactions' => fn ($query) => $query
                ->with([
                    'accountableOfficer:id,full_name',
                    'endUser:id,full_name',
                    'receivedBy:id,full_name',
                ])
                ->latest('created_at'),
        ]);

        return Inertia::render('equipment/Edit', [
            'equipment' => $this->equipmentProps($equipment),
            'options' => $this->formOptions(),
            'transactionOptions' => $this->libraryOptions(EquipmentLibrary::class, self::TRANSACTION_FORM_TYPES),
            'personnelOptions' => Personnel::query()
                ->active()
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(['id', 'full_name'])
                ->map(fn (Personnel $person) => ['value' => $person->id, 'label' => $person->full_name])
                ->all(),
        ]);
    }

    public function update(EquipmentUpdateRequest $request, Equipment $equipment): RedirectResponse
    {
        $before = $equipment->getOriginal();

        $equipment->update($request->validated());

        $changes = collect($equipment->getChanges())
            ->except(['updated_at'])
            ->mapWithKeys(fn ($new, $key) => [$key => ['old' => $before[$key] ?? null, 'new' => $new]])
            ->all();

        AuditLog::record('equipment.updated', $equipment, ['changes' => $changes]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Equipment record updated.')]);

        return to_route('equipment.edit', $equipment);
    }

    public function destroy(Equipment $equipment): RedirectResponse
    {
        $this->authorize('delete', $equipment);

        $equipment->loadMissing('itemType:id,name');

        AuditLog::record('equipment.deleted', $equipment, [
            'property_no' => $equipment->property_no,
            'item' => $equipment->itemType?->name,
            'serial_number' => $equipment->serial_number,
        ]);

        $equipment->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Equipment record deleted.')]);

        return to_route('equipment.index');
    }

    /**
     * Renders the record's QR code as a PNG for printing/tagging.
     * Authorized against `view` — deliberately the same permission as
     * seeing the record itself (Permission::EquipmentView), not a more
     * restrictive one: the QR image encodes nothing the equipment.view
     * holder can't already see by opening the record directly (it's just
     * a link to this same edit page), so gating it any tighter would add
     * friction without a real confidentiality benefit.
     */
    public function qrCode(Equipment $equipment): HttpResponse
    {
        $this->authorize('view', $equipment);

        // qr_code is populated by App\Observers\EquipmentObserver the
        // moment a record has an id; the route() fallback only guards
        // against the (should-be-impossible outside a bug) case of it
        // still being null here.
        $data = $equipment->qr_code ?? route('equipment.edit', $equipment);

        $result = (new Builder(
            writer: new PngWriter,
            data: $data,
            size: 300,
            margin: 10,
        ))->build();

        return response($result->getString(), 200, [
            'Content-Type' => $result->getMimeType(),
            'Content-Disposition' => sprintf('inline; filename="equipment-%s-qr.png"', $equipment->property_no ?? $equipment->id),
        ]);
    }

    /**
     * Combined Tier 1 (id-valued) + Tier 2 (string-valued) options for the
     * Create/Edit forms.
     *
     * @return array<string, array<int, array{value: mixed, label: string}>>
     */
    private function formOptions(): array
    {
        return [
            ...$this->referenceOptions(self::TIER1_FORM_MODELS),
            ...$this->libraryOptions(EquipmentLibrary::class, self::TIER2_FORM_TYPES),
        ];
    }

    /**
     * Tier 1 fields ship both the raw FK id (`item_type_id`, etc. — what
     * the Edit form's Select components bind to and what
     * EquipmentUpdateRequest expects back) and the related row's display
     * name under the pre-cutover key (`item`, etc. — read-only display
     * value, sourced via the relationship per the ADR's Step 2.4 note,
     * never the now-stale legacy string column). Tier 2 fields are
     * unchanged: a single string value under their existing key.
     *
     * @return array<string, mixed>
     */
    private function equipmentProps(Equipment $equipment): array
    {
        return [
            'id' => $equipment->id,
            'property_no' => $equipment->property_no,
            'old_property_no' => $equipment->old_property_no,
            'serial_number' => $equipment->serial_number,
            'item' => $equipment->itemType?->name,
            'item_type_id' => $equipment->item_type_id,
            'uom' => $equipment->uom,
            'brand_manufacturer' => $equipment->brand?->name,
            'brand_id' => $equipment->brand_id,
            'model' => $equipment->model,
            'specifications' => $equipment->specifications,
            'non_dcp' => $equipment->non_dcp,
            'dcp_package' => $equipment->dcp_package,
            'dcp_year' => $equipment->dcp_year,
            'category' => $equipment->equipmentCategory?->name,
            'equipment_category_id' => $equipment->equipment_category_id,
            'classification' => $equipment->equipmentClassification?->name,
            'equipment_classification_id' => $equipment->equipment_classification_id,
            'gl_sl_code' => $equipment->gl_sl_code,
            'uacs' => $equipment->uacs,
            // Same null-preserving cast as index()'s through() mapping —
            // see that comment for why (float) alone is wrong here.
            'acquisition_cost' => $equipment->acquisition_cost !== null ? (float) $equipment->acquisition_cost : null,
            'received_date' => $equipment->received_date?->toDateString(),
            'estimated_useful_life' => $equipment->estimated_useful_life,
            'mode_acquisition' => $equipment->mode_acquisition,
            'source_acquisition' => $equipment->source_acquisition,
            'donor' => $equipment->donor,
            'source_fund' => $equipment->source_fund,
            'allotment_class' => $equipment->allotment_class,
            'pm_plan' => $equipment->pm_plan,
            'equipment_location' => $equipment->equipment_location,
            'non_functional' => $equipment->non_functional,
            'equipment_condition' => $equipment->equipmentCondition?->name,
            'equipment_condition_id' => $equipment->equipment_condition_id,
            'disposition_status' => $equipment->disposition_status,
            'remarks' => $equipment->remarks,
            'qr_code' => $equipment->qr_code,
            'under_warranty' => $equipment->under_warranty,
            'end_warranty_date' => $equipment->end_warranty_date?->toDateString(),
            'supplier' => $equipment->supplier,
            'current_accountable_officer' => $equipment->currentAccountableOfficer?->only(['id', 'full_name']),
            'current_end_user' => $equipment->currentEndUser?->only(['id', 'full_name']),
            'transactions' => $equipment->transactions->map(fn (EquipmentTransaction $transaction) => [
                'id' => $transaction->id,
                'transaction_type' => $transaction->transaction_type,
                'accountable_officer' => $transaction->accountableOfficer?->only(['id', 'full_name']),
                'end_user' => $transaction->endUser?->only(['id', 'full_name']),
                'received_by' => $transaction->receivedBy?->only(['id', 'full_name']),
                'date_assigned_accountable_officer' => $transaction->date_assigned_accountable_officer?->toDateString(),
                'date_assigned_end_user' => $transaction->date_assigned_end_user?->toDateString(),
                'date_received_new_accountable' => $transaction->date_received_new_accountable?->toDateString(),
                'supporting_documents1' => $transaction->supporting_documents1,
                'or_si_dr_iar_no' => $transaction->or_si_dr_iar_no,
                'supporting_documents2' => $transaction->supporting_documents2,
                'par_ics_rrsp_rs_wmr_no' => $transaction->par_ics_rrsp_rs_wmr_no,
                'created_at' => $transaction->created_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
