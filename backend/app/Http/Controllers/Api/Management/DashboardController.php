<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Enums\DashboardEquipmentGroup;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\IspAccount;
use App\Models\ItemType;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Each section is included only when the user holds its `dashboard.*`
 * permission and left out otherwise (never a 403). Checked with
 * hasPermissionTo() rather than a Policy so a missing section doesn't
 * write an `authorization.denied` row on every visit.
 */
class DashboardController extends Controller
{
    private const DEPED_PURCHASE = 'DepEd Purchase';

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $can = fn (Permission $permission): bool => $user->hasPermissionTo($permission);
        $data = [];

        if ($can(Permission::DashboardPpe) || $can(Permission::DashboardSemiExpendable)) {
            $values = $this->propertyValues($user);

            if ($can(Permission::DashboardPpe)) {
                $data['ppe'] = $values['ppe'];
            }

            if ($can(Permission::DashboardSemiExpendable)) {
                $data['semi_expendable'] = $values['semi_expendable'];
            }
        }

        if ($can(Permission::DashboardEquipmentOverview) || $can(Permission::DashboardEquipmentDistribution)) {
            $counts = $this->countsByItemType($user);

            if ($can(Permission::DashboardEquipmentOverview)) {
                $data['equipment_overview'] = $this->equipmentOverview($counts);
            }

            if ($can(Permission::DashboardEquipmentDistribution)) {
                $data['equipment_distribution'] = $this->equipmentDistribution($counts);
            }
        }

        if ($can(Permission::DashboardInternetConnectivity)) {
            $data['internet_connectivity'] = $this->internetConnectivity($user);
        }

        return response()->json(['data' => (object) $data, 'message' => 'Success']);
    }

    /**
     * Equipment with no acquisition cost on file counts toward neither total.
     *
     * @return array{ppe: array{total_value: float, item_count: int}, semi_expendable: array{total_value: float, item_count: int}}
     */
    private function propertyValues(User $user): array
    {
        $row = Equipment::query()
            ->visibleTo($user)
            ->toBase()
            ->selectRaw(
                'coalesce(sum(case when acquisition_cost >= ? then acquisition_cost end), 0) as ppe_value,
                 sum(case when acquisition_cost >= ? then 1 else 0 end) as ppe_count,
                 coalesce(sum(case when acquisition_cost < ? then acquisition_cost end), 0) as semi_value,
                 sum(case when acquisition_cost < ? then 1 else 0 end) as semi_count',
                array_fill(0, 4, Equipment::PPE_THRESHOLD),
            )
            ->first();

        return [
            'ppe' => ['total_value' => (float) ($row->ppe_value ?? 0), 'item_count' => (int) ($row->ppe_count ?? 0)],
            'semi_expendable' => ['total_value' => (float) ($row->semi_value ?? 0), 'item_count' => (int) ($row->semi_count ?? 0)],
        ];
    }

    /**
     * Visible equipment counted per item type name, keyed by dashboard group (`other` for none).
     *
     * @return array<string, array<string, int>>
     */
    private function countsByItemType(User $user): array
    {
        $counts = [];

        Equipment::query()
            ->visibleTo($user)
            ->toBase()
            ->join('item_types', 'equipment.item_type_id', '=', 'item_types.id')
            ->selectRaw('item_types.name as name, item_types.dashboard_group as dashboard_group, count(*) as aggregate')
            ->groupBy('item_types.id', 'item_types.name', 'item_types.dashboard_group')
            ->get()
            ->each(function (object $row) use (&$counts): void {
                $counts[(string) ($row->dashboard_group ?? 'other')][(string) $row->name] = (int) $row->aggregate;
            });

        return $counts;
    }

    /**
     * @param  array<string, array<string, int>>  $counts
     * @param  list<string>|null  $names
     */
    private function sumOf(array $counts, string $group, ?array $names = null): int
    {
        $inGroup = $counts[$group] ?? [];

        return array_sum($names === null ? $inGroup : array_intersect_key($inGroup, array_flip($names)));
    }

    /**
     * @param  array<string, array<string, int>>  $counts
     * @return array{groups: list<array{key: string, label: string, count: int, breakdown: list<array{label: string, count: int, item_type_ids: list<int>}>}>, other: array{count: int}}
     */
    private function equipmentOverview(array $counts): array
    {
        $itemTypeIds = ItemType::query()->pluck('id', 'name')->all();

        $groups = array_map(function (DashboardEquipmentGroup $group) use ($counts, $itemTypeIds): array {
            $breakdown = [];

            foreach ($group->breakdown() as $label => $names) {
                $breakdown[] = [
                    'label' => $label,
                    'count' => $this->sumOf($counts, $group->value, $names),
                    'item_type_ids' => array_values(array_map('intval', array_intersect_key($itemTypeIds, array_flip($names)))),
                ];
            }

            return [
                'key' => $group->value,
                'label' => $group->label(),
                'count' => $this->sumOf($counts, $group->value, $group->headlineItemTypes()),
                'breakdown' => $breakdown,
            ];
        }, DashboardEquipmentGroup::cases());

        return ['groups' => $groups, 'other' => ['count' => $this->sumOf($counts, 'other')]];
    }

    /**
     * @param  array<string, array<string, int>>  $counts
     * @return array{total: int, slices: list<array{key: string, label: string, count: int, percentage: float}>}
     */
    private function equipmentDistribution(array $counts): array
    {
        $slices = array_map(fn (DashboardEquipmentGroup $group) => [
            'key' => $group->value,
            'label' => $group->label(),
            'count' => $this->sumOf($counts, $group->value, $group->headlineItemTypes()),
        ], DashboardEquipmentGroup::cases());

        $slices[] = ['key' => 'other', 'label' => 'Other equipment', 'count' => $this->sumOf($counts, 'other')];

        $total = array_sum(array_column($slices, 'count'));

        return [
            'total' => $total,
            'slices' => array_map(fn (array $slice) => [...$slice, 'percentage' => $this->percentage($slice['count'], $total)], $slices),
        ];
    }

    /**
     * @return array{total_accounts: int, total_cost: float, by_provider: list<array{name: string, count: int, percentage: float}>, by_connection_type: list<array{value: string, count: int, percentage: float}>}
     */
    private function internetConnectivity(User $user): array
    {
        $visible = fn () => IspAccount::query()->visibleTo($user)->toBase();

        $totals = $visible()
            ->selectRaw(
                'count(*) as total_accounts,
                 coalesce(sum(case when mode_of_acquisition = ? and inactive_contract = ? then cost_per_month end), 0) as total_cost',
                [self::DEPED_PURCHASE, false],
            )
            ->first();

        $total = (int) ($totals->total_accounts ?? 0);

        $byProvider = $visible()
            ->join('isp_providers', 'isp_accounts.isp_provider_id', '=', 'isp_providers.id')
            ->selectRaw('isp_providers.name as name, count(*) as aggregate')
            ->groupBy('isp_providers.id', 'isp_providers.name')
            ->orderByDesc('aggregate')
            ->orderBy('isp_providers.name')
            ->get()
            ->map(fn (object $row) => [
                'name' => (string) $row->name,
                'count' => (int) $row->aggregate,
                'percentage' => $this->percentage((int) $row->aggregate, $total),
            ])
            ->all();

        $byConnectionType = $visible()
            ->selectRaw('isp_connection_type as value, count(*) as aggregate')
            ->groupBy('isp_connection_type')
            ->orderByDesc('aggregate')
            ->orderBy('isp_connection_type')
            ->get()
            ->map(fn (object $row) => [
                'value' => (string) $row->value,
                'count' => (int) $row->aggregate,
                'percentage' => $this->percentage((int) $row->aggregate, $total),
            ])
            ->all();

        return [
            'total_accounts' => $total,
            'total_cost' => (float) ($totals->total_cost ?? 0),
            'by_provider' => array_values($byProvider),
            'by_connection_type' => array_values($byConnectionType),
        ];
    }

    private function percentage(int $count, int $total): float
    {
        return $total === 0 ? 0.0 : round($count / $total * 100, 1);
    }
}
