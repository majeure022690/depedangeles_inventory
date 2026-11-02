<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Enums\DataScope;
use App\Enums\EquipmentLibraryType;
use App\Enums\Permission;
use App\Http\Controllers\Api\Management\Concerns\HasLookupOptions;
use App\Http\Controllers\Api\Management\Concerns\ResolvesListParameters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Management\ListSchoolPropertyRequest;
use App\Http\Resources\Management\PropertyItemEquipmentResource;
use App\Models\Brand;
use App\Models\Equipment;
use App\Models\EquipmentCondition;
use App\Models\EquipmentLibrary;
use App\Models\EquipmentTransaction;
use App\Models\ItemType;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * School Property: equipment in custody, totalled per office. An item's
 * office follows Equipment::scopeAtOffices (accountable officer, else end
 * user, else creator), the same rule the RPCPPE uses.
 */
class PropertyItemController extends Controller
{
    use HasLookupOptions, ResolvesListParameters;

    private const OFFICE_OF_ITEM = 'CASE
        WHEN equipment.current_accountable_officer_id IS NOT NULL THEN (SELECT personnel.office_id FROM personnel WHERE personnel.id = equipment.current_accountable_officer_id)
        WHEN equipment.current_end_user_id IS NOT NULL THEN (SELECT personnel.office_id FROM personnel WHERE personnel.id = equipment.current_end_user_id)
        ELSE (SELECT users.office_id FROM users WHERE users.id = equipment.created_by_user_id)
    END';

    public function options(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo(Permission::PropertyItemsView), 403);

        /** @var User $user */
        $user = $request->user();
        $visible = Equipment::query()->visibleTo($user)->inCustody();
        $people = fn (Builder $ids) => Personnel::query()
            ->visibleTo($user)
            ->whereIn('id', $ids)
            ->orderBy('full_name')
            ->get(['id', 'full_name'])
            ->map(fn (Personnel $personnel) => ['value' => $personnel->id, 'label' => (string) $personnel->full_name])
            ->all();

        return response()->json(['data' => [
            'office' => Office::query()
                ->active()
                ->when($user->dataScope() !== DataScope::All, fn (Builder $query) => $query->whereKey($user->office_id ?? 0))
                ->orderBy('office_name')
                ->get(['id', 'office_name', 'school_id'])
                ->map(fn (Office $office) => ['value' => $office->id, 'label' => $office->school_id ? "{$office->office_name} ({$office->school_id})" : $office->office_name])
                ->all(),
            ...$this->referenceOptions(['item_type' => ItemType::class, 'brand' => Brand::class, 'equipment_condition' => EquipmentCondition::class]),
            ...$this->libraryOptions(EquipmentLibrary::class, [
                'disposition_status' => EquipmentLibraryType::DispositionStatus,
                'transaction_type' => EquipmentLibraryType::TransactionType,
            ]),
            'estimated_useful_life' => (clone $visible)->whereNotNull('estimated_useful_life')->distinct()->orderBy('estimated_useful_life')->pluck('estimated_useful_life')
                ->map(fn ($years) => ['value' => (int) $years, 'label' => "{$years} ".((int) $years === 1 ? 'year' : 'years')])
                ->all(),
            'accountable_officer' => $people((clone $visible)->select('current_accountable_officer_id')->whereNotNull('current_accountable_officer_id')),
            'received_by' => $people(EquipmentTransaction::query()->select('received_by_id')->whereNotNull('received_by_id')->whereIn('equipment_id', (clone $visible)->select('id'))),
        ], 'message' => 'Success']);
    }

    /**
     * Items on hand (not lost or stolen) and their total acquisition cost, per office.
     */
    public function summary(ListSchoolPropertyRequest $request): JsonResponse
    {
        $missing = implode(', ', array_fill(0, count(Equipment::MISSING), '?'));
        $items = $request->equipment()
            ->when($request->filled('office_id'), fn (Builder $query) => $query->atOffice($request->integer('office_id')))
            ->toBase()
            ->selectRaw(self::OFFICE_OF_ITEM.' AS office_id')
            ->addSelect(['equipment.acquisition_cost', 'equipment.disposition_status']);

        $rows = DB::query()
            ->fromSub($items, 'items')
            ->groupBy('office_id')
            ->selectRaw("office_id, SUM(CASE WHEN disposition_status IN ({$missing}) THEN 0 ELSE 1 END) AS on_hand, SUM(CASE WHEN disposition_status IN ({$missing}) THEN 1 ELSE 0 END) AS missing, SUM(CASE WHEN disposition_status IN ({$missing}) THEN 0 ELSE COALESCE(acquisition_cost, 0) END) AS total_cost", [...Equipment::MISSING, ...Equipment::MISSING, ...Equipment::MISSING])
            ->get();

        $names = Office::query()->whereIn('id', $rows->pluck('office_id')->filter())->pluck('office_name', 'id');
        $offices = $rows
            ->map(fn (object $row) => [
                'office_id' => $row->office_id === null ? null : (int) $row->office_id,
                'office_name' => $row->office_id === null ? null : $names->get((int) $row->office_id),
                'on_hand' => (int) $row->on_hand,
                'missing' => (int) $row->missing,
                'total_cost' => round((float) $row->total_cost, 2),
            ])
            ->sortBy(fn (array $office) => $office['office_name'] ?? "\u{10FFFF}")
            ->values();

        return response()->json(['data' => [
            'offices' => $offices->all(),
            'totals' => [
                'on_hand' => $offices->sum('on_hand'),
                'missing' => $offices->sum('missing'),
                'total_cost' => round((float) $offices->sum('total_cost'), 2),
            ],
        ], 'message' => 'Success']);
    }

    /**
     * The items on hand behind one office's summary row.
     */
    public function equipment(ListSchoolPropertyRequest $request): JsonResponse
    {
        $query = $request->equipment()
            ->when($request->filled('office_id'), fn (Builder $query) => $query->atOffice($request->integer('office_id')))
            ->onHand()
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
}
