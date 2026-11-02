<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Enums\Permission;
use App\Http\Controllers\Api\Management\Concerns\ResolvesListParameters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Management\ListSchoolPropertyRequest;
use App\Http\Resources\Management\PropertyItemEquipmentResource;
use App\Models\Equipment;
use App\Models\Office;
use App\Models\User;
use App\Services\EquipmentFilterOptions;
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
    use ResolvesListParameters;

    public function options(Request $request, EquipmentFilterOptions $filterOptions): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo(Permission::PropertyItemsView), 403);

        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => [
            ...$filterOptions->forUser($user),
        ], 'message' => 'Success']);
    }

    /**
     * Items on hand (not lost or stolen) and their total acquisition cost, per office.
     */
    public function summary(ListSchoolPropertyRequest $request): JsonResponse
    {
        $missing = implode(', ', array_fill(0, count(Equipment::MISSING), '?'));
        $items = $request->equipment()
            ->tap(fn (Builder $query) => $request->narrowToOffice($query))
            ->toBase()
            ->selectRaw(Equipment::OFFICE_SQL.' AS office_id')
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
            ->tap(fn (Builder $query) => $request->narrowToOffice($query))
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
