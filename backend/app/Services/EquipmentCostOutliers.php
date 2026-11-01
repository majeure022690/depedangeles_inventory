<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Equipment;

/**
 * Costs far above what an item type usually costs, such as a whole DCP package's
 * price entered on each of its items. The typical cost is the median across all
 * equipment of that type, so no ceiling has to be maintained by hand.
 */
final class EquipmentCostOutliers
{
    public const int TIMES_TYPICAL = 5;

    public const int MIN_PRICED_ITEMS = 5;

    /** @var array<int, float>|null */
    private ?array $typical = null;

    /**
     * Typical (median) cost per item type, for types with enough priced items.
     *
     * @return array<int, float>
     */
    public function typicalCosts(): array
    {
        if ($this->typical !== null) {
            return $this->typical;
        }

        $costs = [];
        Equipment::query()->toBase()->whereNotNull('acquisition_cost')->where('acquisition_cost', '>', 0)->whereNull('deleted_at')
            ->orderBy('item_type_id')->orderBy('acquisition_cost')
            ->get(['item_type_id', 'acquisition_cost'])
            ->each(function (object $row) use (&$costs): void {
                $costs[(int) $row->item_type_id][] = (float) $row->acquisition_cost;
            });

        $this->typical = [];
        foreach ($costs as $itemTypeId => $sorted) {
            $count = count($sorted);
            if ($count >= self::MIN_PRICED_ITEMS) {
                $middle = intdiv($count, 2);
                $this->typical[$itemTypeId] = $count % 2 === 1 ? $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2;
            }
        }

        return $this->typical;
    }

    public function isOutlier(Equipment $equipment): bool
    {
        $typical = $this->typicalCosts()[(int) $equipment->item_type_id] ?? null;

        return $typical !== null && $equipment->acquisition_cost !== null && (float) $equipment->acquisition_cost > $typical * self::TIMES_TYPICAL;
    }

    /**
     * SQL condition matching outliers, for filtering a list.
     *
     * @return array{0: string, 1: list<float|int>}
     */
    public function condition(): array
    {
        $typical = $this->typicalCosts();
        if ($typical === []) {
            return ['1 = 0', []];
        }

        $cases = implode(' ', array_fill(0, count($typical), 'when ? then ?'));
        $bindings = [];
        foreach ($typical as $itemTypeId => $cost) {
            array_push($bindings, $itemTypeId, $cost * self::TIMES_TYPICAL);
        }

        return ["equipment.acquisition_cost > (case equipment.item_type_id {$cases} end)", $bindings];
    }
}
