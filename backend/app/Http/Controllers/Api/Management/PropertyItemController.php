<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Enums\DataScope;
use App\Enums\EquipmentLibraryType;
use App\Enums\PropertyCategory;
use App\Enums\PropertySource;
use App\Http\Controllers\Api\Management\Concerns\HasLookupOptions;
use App\Http\Controllers\Api\Management\Concerns\ResolvesListParameters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Management\StorePropertyItemRequest;
use App\Http\Requests\Management\UpdatePropertyItemRequest;
use App\Http\Resources\Management\PropertyItemEquipmentResource;
use App\Http\Resources\Management\PropertyItemResource;
use App\Models\Equipment;
use App\Models\EquipmentLibrary;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\PropertyItem;
use App\Models\User;
use App\Services\PropertyItemExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Audit: create/update/delete rows come from PropertyItem's LogsActivity;
 * exports are logged as `property_item.exported`.
 */
class PropertyItemController extends Controller
{
    use HasLookupOptions, ResolvesListParameters;

    private const RELATIONS = ['office:id,office_name', 'accountableOfficer:id,first_name,last_name', 'endUser:id,first_name,last_name'];

    /**
     * @var array<string, string>
     */
    private const SORTABLE = [
        'article' => 'article',
        'property_no' => 'property_no',
        'date_acquired' => 'date_acquired',
        'unit_value' => 'unit_value',
        'quantity' => 'quantity',
        'created_at' => 'created_at',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PropertyItem::class);
        $request->validate($this->filterRules());

        $query = $this->filtered($request)->with(self::RELATIONS);

        if (! $this->applySort($query, $request, self::SORTABLE)) {
            $query->orderBy('category')->orderBy('article')->orderBy('id');
        }

        return PropertyItemResource::collection($query->paginate($this->perPage($request))->withQueryString())
            ->additional(['message' => 'Success'])
            ->response();
    }

    /**
     * Form and filter options; `?office_id=` adds that office's personnel for the officer and end-user pickers.
     */
    public function options(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $officeId = $request->integer('office_id');

        $data = [
            'category' => array_map(fn (PropertyCategory $category) => [
                'value' => $category->value,
                'label' => $category->label(),
                'articles' => $category->articles(),
            ], PropertyCategory::cases()),
            'source' => PropertySource::options(),
            'condition' => [['value' => 'functional', 'label' => 'Functional'], ['value' => 'unserviceable', 'label' => 'Unserviceable']],
            'office' => Office::query()
                ->active()
                ->when($user->dataScope() !== DataScope::All, fn (Builder $query) => $query->whereKey($user->office_id ?? 0))
                ->get(['id', 'office_name', 'school_id'])
                ->map(fn (Office $office) => ['value' => $office->id, 'label' => $office->school_id ? "{$office->office_name} ({$office->school_id})" : $office->office_name])
                ->all(),
            ...$this->libraryOptions(EquipmentLibrary::class, ['unit_of_measure' => EquipmentLibraryType::UnitOfMeasure]),
        ];

        if ($officeId > 0 && $user->reachesOffice($officeId)) {
            $data['personnel'] = Personnel::query()
                ->where('office_id', $officeId)
                ->active()
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->with('personnelPosition:id,name')
                ->get(['id', 'full_name', 'position_id'])
                ->map(fn (Personnel $personnel) => $personnel->toOption())
                ->all();
        }

        return response()->json(['data' => $data, 'message' => 'Success']);
    }

    public function store(StorePropertyItemRequest $request): JsonResponse
    {
        $item = new PropertyItem($request->validated());
        $item->forceFill(['office_id' => $request->targetOfficeId(), 'created_by_user_id' => $request->user()->id])->save();

        return new PropertyItemResource($item->load(self::RELATIONS))
            ->additional(['message' => 'Property item created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(PropertyItem $propertyItem): JsonResponse
    {
        $this->authorize('view', $propertyItem);

        return new PropertyItemResource($propertyItem->load(self::RELATIONS))
            ->additional(['message' => 'Success'])
            ->response();
    }

    public function update(UpdatePropertyItemRequest $request, PropertyItem $propertyItem): JsonResponse
    {
        $propertyItem->update($request->validated());

        return new PropertyItemResource($propertyItem->load(self::RELATIONS))
            ->additional(['message' => 'Property item updated.'])
            ->response();
    }

    public function destroy(PropertyItem $propertyItem): Response
    {
        $this->authorize('delete', $propertyItem);

        $propertyItem->delete();

        return response()->noContent();
    }

    /**
     * Equipment-module items the RPCPPE adds to the IT sheets, listed beside School Property.
     */
    public function equipment(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PropertyItem::class);
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'office_id' => ['nullable', 'integer']]);

        $query = Equipment::query()
            ->visibleTo($request->user())
            ->inCustody()
            ->search($request->string('search')->toString() ?: null)
            ->when($request->filled('office_id'), fn (Builder $query) => $query->atOffice($request->integer('office_id')))
            ->with([
                'itemType:id,name', 'brand:id,name', 'equipmentCondition:id,name',
                'currentAccountableOfficer:id,full_name,office_id', 'currentAccountableOfficer.office:id,office_name',
                'currentEndUser:id,full_name,office_id', 'currentEndUser.office:id,office_name',
            ])
            ->orderBy('received_date')
            ->orderBy('property_no');

        return PropertyItemEquipmentResource::collection($query->paginate($this->perPage($request))->withQueryString())
            ->additional(['message' => 'Success'])
            ->response();
    }

    /**
     * The current filtered list as an Excel file.
     */
    public function export(Request $request, PropertyItemExport $export): BinaryFileResponse
    {
        $this->authorize('viewAny', PropertyItem::class);
        $filters = $request->validate($this->filterRules());

        $items = $this->filtered($request)
            ->with(self::RELATIONS)
            ->orderBy('office_id')
            ->orderBy('category')
            ->orderBy('article')
            ->get();

        activity()
            ->event('property_item.exported')
            ->withProperties(['filters' => $filters, 'count' => $items->count()])
            ->log('property_item.exported');

        return response()
            ->download($export->generate($items), 'School_Property_'.now()->format('Y-m-d').'.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    /**
     * @return array<string, mixed>
     */
    private function filterRules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::enum(PropertyCategory::class)],
            'office_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return Builder<PropertyItem>
     */
    private function filtered(Request $request): Builder
    {
        return PropertyItem::query()
            ->visibleTo($request->user())
            ->search($request->string('search')->toString() ?: null)
            ->when($request->filled('category'), fn (Builder $query) => $query->where('category', $request->string('category')->toString()))
            ->when($request->filled('office_id'), fn (Builder $query) => $query->where('office_id', $request->integer('office_id')));
    }
}
