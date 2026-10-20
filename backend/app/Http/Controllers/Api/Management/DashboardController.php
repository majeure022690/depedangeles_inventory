<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\EquipmentTransaction;
use App\Models\IspAccount;
use App\Models\Personnel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A personalized summary of what the user may otherwise see: each section
 * is included only when the user holds that resource's `.view`
 * permission, and simply left out otherwise (never a 403). It checks
 * hasPermissionTo() directly rather than a Policy so that merely lacking
 * a section doesn't write an `authorization.denied` row on every visit.
 */
class DashboardController extends Controller
{
    /**
     * How many days out a warranty/contract end date counts as "expiring soon".
     */
    private const EXPIRING_SOON_DAYS = 30;

    private const RECENT_TRANSACTIONS_LIMIT = 10;

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = [];

        if ($user->hasPermissionTo(Permission::EquipmentView)) {
            $data['equipment'] = $this->equipmentSummary($user);
            $data['recent_transactions'] = $this->recentTransactions($user);
        }

        if ($user->hasPermissionTo(Permission::PersonnelView)) {
            $data['personnel'] = $this->personnelSummary();
        }

        if ($user->hasPermissionTo(Permission::IspAccountsView)) {
            $data['isp_accounts'] = $this->ispAccountsSummary();
        }

        return response()->json(['data' => (object) $data, 'message' => 'Success']);
    }

    /**
     * @return array<string, mixed>
     */
    private function equipmentSummary(User $user): array
    {
        $now = now();
        $visible = fn () => Equipment::query()->visibleTo($user);

        return [
            'total' => $visible()->count(),
            'by_condition' => $this->countsByReferenceJoin($visible(), 'equipment_conditions', 'equipment_condition_id'),
            'by_category' => $this->countsByReferenceJoin($visible(), 'equipment_categories', 'equipment_category_id'),
            'by_disposition_status' => $this->countsByRawSelect($visible(), 'disposition_status as value, count(*) as aggregate', 'disposition_status'),
            'total_acquisition_cost' => (float) $visible()->sum('acquisition_cost'),
            'non_functional_count' => $visible()->where('non_functional', true)->count(),
            'warranty_expiring_soon_count' => $visible()
                ->where('under_warranty', true)
                ->whereBetween('end_warranty_date', [$now->toDateString(), $now->addDays(self::EXPIRING_SOON_DAYS)->toDateString()])
                ->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function personnelSummary(): array
    {
        return [
            'active_count' => Personnel::query()->where('inactive', false)->count(),
            'inactive_count' => Personnel::query()->where('inactive', true)->count(),
            'oic_count' => Personnel::query()->where('oic', true)->count(),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function ispAccountsSummary(): array
    {
        $now = now();

        return [
            'total_monthly_cost' => (float) IspAccount::query()->where('inactive_contract', false)->sum('cost_per_month'),
            // Only a still-active contract is worth a renewal alert.
            'contracts_expiring_soon_count' => IspAccount::query()
                ->where('inactive_contract', false)
                ->whereBetween('contract_end_date', [$now->toDateString(), $now->addDays(self::EXPIRING_SOON_DAYS)->toDateString()])
                ->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentTransactions(User $user): array
    {
        return EquipmentTransaction::query()
            ->when(
                $user->scopedPersonnelIds() !== null,
                fn ($query) => $query->whereHas('equipment', fn ($equipment) => $equipment->visibleTo($user)),
            )
            ->with([
                'equipment:id,property_no,item_type_id',
                'equipment.itemType:id,name',
                'accountableOfficer:id,full_name',
                'endUser:id,full_name',
                'recordedBy:id,name',
            ])
            ->latest('created_at')
            ->latest('id')
            ->limit(self::RECENT_TRANSACTIONS_LIMIT)
            ->get()
            ->map(fn (EquipmentTransaction $transaction) => [
                'id' => $transaction->id,
                'transaction_type' => $transaction->transaction_type,
                'equipment' => $transaction->equipment ? [
                    'id' => $transaction->equipment->id,
                    'property_no' => $transaction->equipment->property_no,
                    'item' => $transaction->equipment->itemType?->name,
                ] : null,
                'accountable_officer' => $transaction->accountableOfficer?->only(['id', 'full_name']),
                'end_user' => $transaction->endUser?->only(['id', 'full_name']),
                'recorded_by' => $transaction->recordedBy?->only(['id', 'name']),
                'created_at' => $transaction->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * {value, count} rows grouped on a plain equipment column, largest
     * first, over the caller's visible equipment. `literal-string` keeps
     * request input out of the raw SQL.
     *
     * @param  Builder<Equipment>  $visible
     * @param  literal-string  $selectRaw
     * @param  literal-string  $groupBy
     * @return array<int, array{value: string, count: int}>
     */
    private function countsByRawSelect(Builder $visible, string $selectRaw, string $groupBy): array
    {
        return $visible->toBase()
            ->selectRaw($selectRaw)
            ->groupBy($groupBy)
            ->orderByDesc('aggregate')
            ->get()
            ->map(fn (object $row) => ['value' => (string) $row->value, 'count' => (int) $row->aggregate])
            ->all();
    }

    /**
     * {value, count} rows for a Tier 1 FK, grouped by the reference row's
     * name. The inner join leaves out equipment with no value set.
     *
     * @param  Builder<Equipment>  $visible
     * @param  literal-string  $refTable
     * @param  literal-string  $fkColumn
     * @return array<int, array{value: string, count: int}>
     */
    private function countsByReferenceJoin(Builder $visible, string $refTable, string $fkColumn): array
    {
        return $visible->toBase()
            ->join($refTable, "equipment.{$fkColumn}", '=', "{$refTable}.id")
            ->selectRaw("{$refTable}.name as value, count(*) as aggregate")
            ->groupBy("{$refTable}.name")
            ->orderByDesc('aggregate')
            ->get()
            ->map(fn (object $row) => ['value' => (string) $row->value, 'count' => (int) $row->aggregate])
            ->all();
    }
}
