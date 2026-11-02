<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EquipmentLibraryType;
use App\Models\Brand;
use App\Models\Equipment;
use App\Models\EquipmentCondition;
use App\Models\EquipmentLibrary;
use App\Models\EquipmentTransaction;
use App\Models\ItemType;
use App\Models\Personnel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dropdown options for the equipment filters shared by the Equipment list and School Property.
 * People and useful-life choices come from the equipment the user can see.
 */
final class EquipmentFilterOptions
{
    /**
     * @return array<string, list<array{value: int|string, label: string}>>
     */
    public function forUser(User $user): array
    {
        $visible = Equipment::query()->visibleTo($user)->inCustody();
        $people = fn (Builder $ids) => Personnel::query()
            ->visibleTo($user)
            ->whereIn('id', $ids)
            ->orderBy('full_name')
            ->get(['id', 'full_name'])
            ->map(fn (Personnel $personnel) => ['value' => $personnel->id, 'label' => (string) $personnel->full_name])
            ->values()
            ->all();

        return [
            'item_type' => ItemType::activeOptions(),
            'brand' => Brand::activeOptions(),
            'equipment_condition' => EquipmentCondition::activeOptions(),
            'disposition_status' => EquipmentLibrary::activeOptions(EquipmentLibraryType::DispositionStatus),
            'transaction_type' => EquipmentLibrary::activeOptions(EquipmentLibraryType::TransactionType),
            'estimated_useful_life' => (clone $visible)->whereNotNull('estimated_useful_life')->distinct()->orderBy('estimated_useful_life')->pluck('estimated_useful_life')
                ->map(fn ($years) => ['value' => (int) $years, 'label' => "{$years} ".((int) $years === 1 ? 'year' : 'years')])
                ->values()
                ->all(),
            'accountable_officer' => $people((clone $visible)->select('current_accountable_officer_id')->whereNotNull('current_accountable_officer_id')),
            'received_by' => $people(EquipmentTransaction::query()->select('received_by_id')->whereNotNull('received_by_id')->whereIn('equipment_id', (clone $visible)->select('id'))),
        ];
    }
}
