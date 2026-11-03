<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Equipment;
use App\Models\InventoryCount;
use App\Models\InventoryCountFoundItem;
use App\Models\InventoryCountItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class InventoryCountService
{
    /**
     * Copies every in-custody item at the count's offices, as the record stands now, into the expected list.
     */
    public function open(InventoryCount $count, User $user): void
    {
        DB::transaction(function () use ($count, $user) {
            $now = now();

            Equipment::query()
                ->visibleTo($user)
                ->atOffices($count->office_ids)
                ->inCustody()
                ->select(['equipment.id', 'equipment.equipment_condition_id', 'equipment.current_accountable_officer_id', 'equipment.equipment_location'])
                ->selectRaw(Equipment::OFFICE_SQL.' as office_id')
                ->orderBy('equipment.id')
                ->chunk(500, function ($items) use ($count, $now) {
                    InventoryCountItem::query()->insert($items->map(fn (Equipment $item) => [
                        'inventory_count_id' => $count->id,
                        'equipment_id' => $item->id,
                        'office_id' => $item->getAttribute('office_id'),
                        'condition_id' => $item->equipment_condition_id,
                        'accountable_officer_id' => $item->current_accountable_officer_id,
                        'location' => $item->equipment_location,
                        'result' => InventoryCountItem::PENDING,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all());
                });

            $count->forceFill(['status' => InventoryCount::OPEN, 'opened_by_user_id' => $user->id, 'opened_at' => $now])->save();
        });
    }

    public function close(InventoryCount $count, User $user): void
    {
        $count->forceFill(['status' => InventoryCount::CLOSED, 'closed_by_user_id' => $user->id, 'closed_at' => now()])->save();
    }

    public function cancel(InventoryCount $count, User $user): void
    {
        $count->forceFill(['status' => InventoryCount::CANCELLED, 'closed_by_user_id' => $user->id, 'closed_at' => now()])->save();
    }

    /**
     * A found item left without a condition, holder or location keeps the value on record.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(InventoryCountItem $item, array $attributes, User $user): void
    {
        $item->fill($attributes);

        if ($item->result === InventoryCountItem::FOUND) {
            foreach (['found_condition_id' => 'condition_id', 'found_holder_id' => 'accountable_officer_id', 'found_location' => 'location'] as $found => $recorded) {
                if (! array_key_exists($found, $attributes) || $attributes[$found] === null) {
                    $item->{$found} = $item->{$recorded};
                }
            }
        } else {
            $item->fill(['found_condition_id' => null, 'found_holder_id' => null, 'found_location' => null]);
        }

        $item->forceFill([
            'counted_by_user_id' => $item->result === InventoryCountItem::PENDING ? null : $user->id,
            'counted_at' => $item->result === InventoryCountItem::PENDING ? null : now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addFoundItem(InventoryCount $count, array $attributes, User $user): InventoryCountFoundItem
    {
        $found = new InventoryCountFoundItem($attributes);
        $found->forceFill(['inventory_count_id' => $count->id, 'counted_by_user_id' => $user->id, 'counted_at' => now()])->save();

        return $found;
    }

    /**
     * Expected items of this count the user may see: offices their scope reaches, equipment their scope reaches.
     *
     * @return Builder<InventoryCountItem>
     */
    public function itemsFor(InventoryCount $count, User $user): Builder
    {
        return InventoryCountItem::query()
            ->where('inventory_count_id', $count->id)
            ->whereIn('office_id', $count->officeIdsFor($user))
            ->whereIn('equipment_id', Equipment::withTrashed()->visibleTo($user)->select('equipment.id'));
    }

    /**
     * Progress per office and the size of each comparison bucket.
     *
     * @return array{offices: list<array{office_id: int, total: int, found: int, not_found: int, pending: int, found_at_station: int}>, buckets: array<string, int>, found_at_station: int}
     */
    public function summary(InventoryCount $count, User $user): array
    {
        $officeIds = $count->officeIdsFor($user);

        $rows = $this->itemsFor($count, $user)
            ->select('office_id', 'result', DB::raw('COUNT(*) as total'))
            ->groupBy('office_id', 'result')
            ->get();

        $foundAtStation = InventoryCountFoundItem::query()
            ->where('inventory_count_id', $count->id)
            ->whereIn('office_id', $officeIds)
            ->select('office_id', DB::raw('COUNT(*) as total'))
            ->groupBy('office_id')
            ->pluck('total', 'office_id');

        $offices = array_map(function (int $officeId) use ($rows, $foundAtStation) {
            $byResult = $rows->where('office_id', $officeId)->pluck('total', 'result');

            return [
                'office_id' => $officeId,
                'total' => (int) $byResult->sum(),
                'found' => (int) ($byResult[InventoryCountItem::FOUND] ?? 0),
                'not_found' => (int) ($byResult[InventoryCountItem::NOT_FOUND] ?? 0),
                'pending' => (int) ($byResult[InventoryCountItem::PENDING] ?? 0),
                'found_at_station' => (int) ($foundAtStation[$officeId] ?? 0),
            ];
        }, $officeIds);

        $buckets = [];
        foreach (InventoryCountItem::BUCKETS as $bucket) {
            $buckets[$bucket] = $this->itemsFor($count, $user)->bucket($bucket)->count();
        }

        return ['offices' => $offices, 'buckets' => $buckets, 'found_at_station' => (int) $foundAtStation->sum()];
    }

    /**
     * Resolves a scanned QR label (the equipment URL printed on it), a property number or a serial number
     * to an item on this count, or to a visible equipment record that is not on it.
     *
     * @return array{item: ?InventoryCountItem, equipment: ?Equipment}
     */
    public function lookup(InventoryCount $count, User $user, string $code): array
    {
        $code = trim($code);
        $equipmentId = preg_match('#/equipment/(\d+)(?:/edit)?/?(?:[?\#].*)?$#', $code, $match) === 1 ? (int) $match[1] : null;

        $equipment = Equipment::query()
            ->visibleTo($user)
            ->when(
                $equipmentId !== null,
                fn (Builder $query) => $query->whereKey($equipmentId),
                fn (Builder $query) => $query->where(fn (Builder $query) => $query
                    ->where('property_no', $code)
                    ->orWhere('old_property_no', $code)
                    ->orWhere('serial_number', $code)),
            )
            ->orderBy('id')
            ->first();

        if ($equipment === null) {
            return ['item' => null, 'equipment' => null];
        }

        $item = $this->itemsFor($count, $user)->where('equipment_id', $equipment->id)->first();

        return ['item' => $item, 'equipment' => $item === null ? $equipment : null];
    }
}
