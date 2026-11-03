<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Enums\DataScope;
use App\Http\Controllers\Api\Management\Concerns\ResolvesListParameters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Management\StoreInventoryCountFoundItemRequest;
use App\Http\Requests\Management\StoreInventoryCountRequest;
use App\Http\Requests\Management\UpdateInventoryCountItemRequest;
use App\Http\Requests\Management\UpdateInventoryCountRequest;
use App\Http\Resources\Management\InventoryCountFoundItemResource;
use App\Http\Resources\Management\InventoryCountItemResource;
use App\Http\Resources\Management\InventoryCountResource;
use App\Models\EquipmentCondition;
use App\Models\InventoryCount;
use App\Models\InventoryCountFoundItem;
use App\Models\InventoryCountItem;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\User;
use App\Services\InventoryCountService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Audit: counts, item results and found-at-station items come from their models' LogsActivity;
 * opening, closing and cancelling are logged as `inventory_count.<action>`.
 */
class InventoryCountController extends Controller
{
    use ResolvesListParameters;

    private const ITEM_RELATIONS = [
        'equipment:id,property_no,old_property_no,serial_number,model,specifications,brand_id,brand_other,item_type_id,item_type_other',
        'equipment.brand:id,name', 'equipment.itemType:id,name',
        'office:id,office_name', 'condition:id,name', 'foundCondition:id,name',
        'accountableOfficer:id,full_name', 'foundHolder:id,full_name', 'countedBy:id,name',
    ];

    /**
     * @var array<string, string>
     */
    private const SORTABLE = ['name' => 'name', 'as_of_date' => 'as_of_date', 'status' => 'status', 'created_at' => 'created_at'];

    public function __construct(private readonly InventoryCountService $counts) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryCount::class);
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in([InventoryCount::DRAFT, InventoryCount::OPEN, InventoryCount::CLOSED, InventoryCount::CANCELLED])],
        ]);

        $query = InventoryCount::query()
            ->visibleTo($request->user())
            ->withCount(['items', 'items as counted_count' => fn (Builder $query) => $query->where('result', '<>', InventoryCountItem::PENDING)])
            ->when($request->filled('search'), fn (Builder $query) => $query->where('name', 'like', '%'.$request->string('search')->toString().'%'))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')->toString()));

        if (! $this->applySort($query, $request, self::SORTABLE)) {
            $query->orderByDesc('as_of_date')->orderByDesc('id');
        }

        return InventoryCountResource::collection($query->paginate($this->perPage($request))->withQueryString())
            ->additional(['message' => 'Success'])
            ->response();
    }

    /**
     * Form options; `?office_ids[]=` adds the active personnel at those offices for the holder picker.
     */
    public function options(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $request->validate(['office_ids' => ['nullable', 'array', 'max:500'], 'office_ids.*' => ['integer']]);
        $officeIds = array_values(array_filter(
            array_map('intval', (array) $request->input('office_ids', [])),
            fn (int $id) => $user->reachesOffice($id),
        ));

        return response()->json([
            'data' => [
                'office' => Office::query()
                    ->active()
                    ->when($user->dataScope() !== DataScope::All, fn (Builder $query) => $query->whereKey($user->office_id ?? 0))
                    ->orderBy('office_name')
                    ->get(['id', 'office_name', 'school_id'])
                    ->map(fn (Office $office) => [
                        'value' => $office->id,
                        'label' => $office->school_id ? "{$office->office_name} ({$office->school_id})" : $office->office_name,
                    ])
                    ->all(),
                'condition' => EquipmentCondition::activeOptions(),
                'name_suggestions' => InventoryCount::query()->visibleTo($user)->distinct()->orderBy('name')->limit(30)->pluck('name')->all(),
                'people' => $officeIds === [] ? [] : array_values(Personnel::query()
                    ->active()
                    ->whereIn('office_id', $officeIds)
                    ->with('personnelPosition:id,name')
                    ->orderBy('last_name')
                    ->orderBy('first_name')
                    ->get(['id', 'full_name', 'position_id'])
                    ->map(fn (Personnel $personnel) => $personnel->toOption())
                    ->all()),
            ],
            'message' => 'Success',
        ]);
    }

    public function store(StoreInventoryCountRequest $request): JsonResponse
    {
        $count = new InventoryCount($request->validated());
        $count->forceFill(['status' => InventoryCount::DRAFT, 'created_by_user_id' => $request->user()->id])->save();

        return new InventoryCountResource($count)
            ->additional(['message' => 'Inventory count created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, InventoryCount $inventoryCount): JsonResponse
    {
        $this->authorize('view', $inventoryCount);
        /** @var User $user */
        $user = $request->user();

        return $this->detail($inventoryCount, $user, 'Success');
    }

    public function update(UpdateInventoryCountRequest $request, InventoryCount $inventoryCount): JsonResponse
    {
        $inventoryCount->update($request->validated());

        return $this->detail($inventoryCount, $request->user(), 'Inventory count updated.');
    }

    public function destroy(InventoryCount $inventoryCount): Response
    {
        $this->authorize('delete', $inventoryCount);

        $inventoryCount->delete();

        return response()->noContent();
    }

    public function open(Request $request, InventoryCount $inventoryCount): JsonResponse
    {
        $this->authorize('update', $inventoryCount);
        abort_unless($inventoryCount->isDraft(), 422, 'Only a draft count can be opened.');

        $this->counts->open($inventoryCount, $request->user());
        $this->logEvent($request->user(), $inventoryCount, 'opened', ['items' => $inventoryCount->items()->count()]);

        return $this->detail($inventoryCount, $request->user(), 'Inventory count opened.');
    }

    public function close(Request $request, InventoryCount $inventoryCount): JsonResponse
    {
        $this->authorize('update', $inventoryCount);
        abort_unless($inventoryCount->isOpen(), 422, 'Only an open count can be closed.');

        $this->counts->close($inventoryCount, $request->user());
        $this->logEvent($request->user(), $inventoryCount, 'closed', [
            'pending' => $inventoryCount->items()->where('result', InventoryCountItem::PENDING)->count(),
        ]);

        return $this->detail($inventoryCount, $request->user(), 'Inventory count closed.');
    }

    public function cancel(Request $request, InventoryCount $inventoryCount): JsonResponse
    {
        $this->authorize('update', $inventoryCount);
        abort_unless($inventoryCount->isOpen(), 422, 'Only an open count can be cancelled.');

        $this->counts->cancel($inventoryCount, $request->user());
        $this->logEvent($request->user(), $inventoryCount, 'cancelled', []);

        return $this->detail($inventoryCount, $request->user(), 'Inventory count cancelled.');
    }

    public function items(Request $request, InventoryCount $inventoryCount): JsonResponse
    {
        $this->authorize('view', $inventoryCount);
        /** @var User $user */
        $user = $request->user();
        $request->validate([
            'office_id' => ['nullable', 'integer'],
            'bucket' => ['nullable', 'string', Rule::in([...InventoryCountItem::BUCKETS, 'discrepancies'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $search = $request->string('search')->toString();

        $query = $this->counts->itemsFor($inventoryCount, $user)
            ->with(self::ITEM_RELATIONS)
            ->when($request->filled('office_id'), fn (Builder $query) => $query->where('office_id', $request->integer('office_id')))
            ->bucket($request->string('bucket')->toString() ?: null)
            ->when($search !== '', fn (Builder $query) => $query->whereHas('equipment', fn (Builder $query) => $query
                ->where('property_no', 'like', "%{$search}%")
                ->orWhere('old_property_no', 'like', "%{$search}%")
                ->orWhere('serial_number', 'like', "%{$search}%")
                ->orWhere('model', 'like', "%{$search}%")))
            ->orderByRaw('CASE WHEN result = ? THEN 0 ELSE 1 END', [InventoryCountItem::PENDING])
            ->orderBy('id');

        return InventoryCountItemResource::collection($query->paginate($this->perPage($request))->withQueryString())
            ->additional(['message' => 'Success'])
            ->response();
    }

    public function lookup(Request $request, InventoryCount $inventoryCount): JsonResponse
    {
        $this->authorize('conduct', $inventoryCount);
        $code = $request->validate(['code' => ['required', 'string', 'max:500']])['code'];

        $found = $this->counts->lookup($inventoryCount, $request->user(), $code);

        return response()->json([
            'data' => [
                'item' => $found['item'] ? new InventoryCountItemResource($found['item']->load(self::ITEM_RELATIONS)) : null,
                'equipment' => $found['equipment'] ? [
                    'id' => $found['equipment']->id,
                    'property_no' => $found['equipment']->property_no,
                    'serial_number' => $found['equipment']->serial_number,
                    'description' => $found['equipment']->propertyDescription(),
                ] : null,
            ],
            'message' => $found['item'] || $found['equipment'] ? 'Success' : 'No matching equipment found.',
        ]);
    }

    public function updateItem(UpdateInventoryCountItemRequest $request, InventoryCount $inventoryCount, InventoryCountItem $item): JsonResponse
    {
        abort_unless($this->counts->itemsFor($inventoryCount, $request->user())->whereKey($item->id)->exists(), 404);

        $this->counts->record($item, $request->validated(), $request->user());

        return new InventoryCountItemResource($item->load(self::ITEM_RELATIONS))
            ->additional(['message' => 'Saved.'])
            ->response();
    }

    public function foundItems(Request $request, InventoryCount $inventoryCount): JsonResponse
    {
        $this->authorize('view', $inventoryCount);
        $request->validate(['office_id' => ['nullable', 'integer']]);

        $query = InventoryCountFoundItem::query()
            ->where('inventory_count_id', $inventoryCount->id)
            ->whereIn('office_id', $inventoryCount->officeIdsFor($request->user()))
            ->when($request->filled('office_id'), fn (Builder $query) => $query->where('office_id', $request->integer('office_id')))
            ->with([
                'office:id,office_name', 'condition:id,name', 'countedBy:id,name',
                'equipment:id,property_no,serial_number,model,specifications,brand_id,brand_other,item_type_id,item_type_other',
                'equipment.brand:id,name', 'equipment.itemType:id,name',
            ])
            ->latest('id');

        return InventoryCountFoundItemResource::collection($query->paginate($this->perPage($request))->withQueryString())
            ->additional(['message' => 'Success'])
            ->response();
    }

    public function storeFoundItem(StoreInventoryCountFoundItemRequest $request, InventoryCount $inventoryCount): JsonResponse
    {
        $found = $this->counts->addFoundItem($inventoryCount, $request->validated(), $request->user());

        return new InventoryCountFoundItemResource($found->load(['office:id,office_name', 'condition:id,name', 'countedBy:id,name', 'equipment']))
            ->additional(['message' => 'Found-at-station item recorded.'])
            ->response()
            ->setStatusCode(201);
    }

    private function detail(InventoryCount $count, User $user, string $message): JsonResponse
    {
        $officeIds = $count->officeIdsFor($user);

        $count->setAttribute('offices_list', Office::query()
            ->whereIn('id', $officeIds)
            ->orderBy('office_name')
            ->get(['id', 'office_name', 'school_id'])
            ->map(fn (Office $office) => ['id' => $office->id, 'office_name' => $office->office_name, 'school_id' => $office->school_id])
            ->all());

        if (! $count->isDraft()) {
            $count->setAttribute('summary', $this->counts->summary($count, $user));
        }

        return new InventoryCountResource($count)
            ->additional(['message' => $message])
            ->response();
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logEvent(User $user, InventoryCount $count, string $action, array $properties): void
    {
        activity()
            ->causedBy($user)
            ->performedOn($count)
            ->event("inventory_count.{$action}")
            ->withProperties($properties)
            ->log("inventory_count.{$action}");
    }
}
