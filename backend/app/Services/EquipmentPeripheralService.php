<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Equipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monitors, keyboards and mice linked to a desktop bundle as child
 * equipment. A peripheral created here takes the desktop's property
 * number plus a 3-digit sequence; a linked existing one keeps its own.
 */
final class EquipmentPeripheralService
{
    private const INHERITED_FIELDS = [
        'non_dcp', 'dcp_package', 'dcp_year', 'equipment_classification_id',
        'received_date', 'mode_acquisition', 'source_acquisition', 'donor', 'source_fund',
        'allotment_class', 'equipment_location', 'disposition_status',
        'end_warranty_date', 'supplier',
        'current_accountable_officer_id', 'current_end_user_id',
    ];

    public function __construct(
        private readonly EquipmentAccountabilityService $accountability,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Equipment $parent, array $data, User $creator): Equipment
    {
        return DB::transaction(function () use ($parent, $data, $creator) {
            $parent = Equipment::query()->lockForUpdate()->findOrFail($parent->id);

            $peripheral = new Equipment([
                ...$parent->only(self::INHERITED_FIELDS),
                ...$data,
                'brand_id' => $data['brand_id'] ?? $parent->brand_id,
                'equipment_condition_id' => $data['equipment_condition_id'] ?? $parent->equipment_condition_id,
                'property_no' => $this->nextPropertyNo($parent),
            ]);
            $peripheral->parent()->associate($parent);
            $peripheral->createdBy()->associate($creator);
            $this->saveNumbered($peripheral, 'peripherals');

            $this->accountability->mirrorLatestTransaction($parent, $peripheral);
            $this->log('equipment.peripheral.created', $parent, $peripheral);

            return $peripheral;
        });
    }

    /**
     * All rows or none.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, Equipment>
     */
    public function createMany(Equipment $parent, array $rows, User $creator): Collection
    {
        return DB::transaction(fn () => new Collection(array_map(
            fn (array $row) => $this->create($parent, $row, $creator),
            $rows,
        )));
    }

    /**
     * After the desktop's number changed from $previous, renames every row
     * within the editor's scope numbered "<$previous>-<digits>" to the same
     * suffix under the new number, including peripherals since moved to
     * another desktop.
     */
    public function renumber(Equipment $desktop, ?string $previous, User $editor): int
    {
        $current = $desktop->property_no;

        if (blank($previous) || blank($current) || $previous === $current) {
            return 0;
        }

        return DB::transaction(function () use ($desktop, $previous, $current, $editor) {
            $rows = Equipment::numberedAfter($previous, $editor, lockForUpdate: true);

            if ($rows->isEmpty()) {
                return 0;
            }

            $targets = $rows->mapWithKeys(fn (Equipment $row) => [$row->id => $current.substr((string) $row->property_no, strlen($previous))]);

            if ($targets->contains(fn (string $target) => mb_strlen($target) > 255)) {
                throw ValidationException::withMessages(['renumber_peripherals' => __('Cannot renumber the peripherals: the new property number is too long to add their suffixes.')]);
            }

            $taken = fn () => Equipment::withTrashed()
                ->whereIn('property_no', $targets->values()->all())
                ->whereKeyNot([$desktop->id, ...$rows->modelKeys()]);

            $takenCount = $taken()->count();

            if ($takenCount > 0) {
                $visible = array_values(array_filter($taken()->visibleTo($editor)->orderBy('property_no')->pluck('property_no')->all(), is_string(...)));

                throw ValidationException::withMessages([
                    'renumber_peripherals' => $this->takenMessage($visible, $takenCount - count($visible)),
                ]);
            }

            $rows->each(function (Equipment $row) use ($desktop, $targets) {
                $previousNo = (string) $row->property_no;
                $row->property_no = $targets[$row->id];

                if (blank($row->old_property_no)) {
                    $row->old_property_no = $previousNo;
                }

                $this->saveNumbered($row, 'renumber_peripherals');

                activity()
                    ->performedOn($row)
                    ->event('equipment.peripheral.renumbered')
                    ->withProperties(['old' => $previousNo, 'new' => $row->property_no, 'renumbered_from_equipment_id' => $desktop->id])
                    ->log('equipment.peripheral.renumbered');
            });

            activity()
                ->performedOn($desktop)
                ->event('equipment.peripheral.renumbered')
                ->withProperties(['old' => $previous, 'new' => $current, 'renumbered_equipment_ids' => $rows->modelKeys()])
                ->log('equipment.peripheral.renumbered');

            return $rows->count();
        });
    }

    /**
     * The peripheral takes over the desktop's accountability when it
     * differs, which needs a desktop transaction to copy.
     */
    public function link(Equipment $parent, Equipment $peripheral): Equipment
    {
        return DB::transaction(function () use ($parent, $peripheral) {
            $peripheral = Equipment::query()->lockForUpdate()->findOrFail($peripheral->id);

            if ($peripheral->parent_equipment_id !== null) {
                throw ValidationException::withMessages(['equipment_id' => __('This peripheral is already linked to a desktop.')]);
            }

            $peripheral->parent()->associate($parent);
            $peripheral->save();

            if ((int) $peripheral->current_accountable_officer_id !== (int) $parent->current_accountable_officer_id
                || (int) $peripheral->current_end_user_id !== (int) $parent->current_end_user_id) {
                if ($this->accountability->mirrorLatestTransaction($parent, $peripheral) === null) {
                    throw ValidationException::withMessages([
                        'equipment_id' => __('Record an issuance on the desktop first, or link a peripheral held by the same officer.'),
                    ]);
                }
            }

            $this->log('equipment.peripheral.linked', $parent, $peripheral);

            return $peripheral;
        });
    }

    public function unlink(Equipment $parent, Equipment $peripheral): void
    {
        DB::transaction(function () use ($parent, $peripheral) {
            $peripheral->parent()->dissociate();
            $peripheral->save();

            $this->log('equipment.peripheral.unlinked', $parent, $peripheral);
        });
    }

    /**
     * The highest numeric suffix among this desktop's peripherals plus one,
     * skipping any number already taken elsewhere.
     */
    private function nextPropertyNo(Equipment $parent): string
    {
        $prefix = $parent->property_no.'-';

        $sequence = $parent->children()
            ->withTrashed()
            ->pluck('property_no')
            ->filter(fn (?string $propertyNo) => $propertyNo !== null && str_starts_with($propertyNo, $prefix))
            ->map(fn (string $propertyNo) => substr($propertyNo, strlen($prefix)))
            ->filter(fn (string $suffix) => ctype_digit($suffix))
            ->map(fn (string $suffix) => (int) $suffix)
            ->max() ?? 0;

        do {
            $candidate = $prefix.str_pad((string) ++$sequence, 3, '0', STR_PAD_LEFT);
        } while (Equipment::withTrashed()->where('property_no', $candidate)->exists());

        return $candidate;
    }

    /**
     * A number taken by a concurrent request surfaces as a validation error, not a 500.
     */
    private function saveNumbered(Equipment $equipment, string $errorKey): void
    {
        try {
            $equipment->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                $errorKey => __('Property number :number was just taken by another record. Please try again.', ['number' => $equipment->property_no]),
            ]);
        }
    }

    /**
     * @param  list<string>  $visible
     */
    private function takenMessage(array $visible, int $hidden): string
    {
        if ($visible === []) {
            return trans_choice(
                'Cannot renumber the peripherals: :count of the new property numbers is already in use.|Cannot renumber the peripherals: :count of the new property numbers are already in use.',
                $hidden,
            );
        }

        $numbers = implode(', ', $visible);

        if ($hidden > 0) {
            $numbers .= ' '.trans_choice('and :count other|and :count others', $hidden);
        }

        return __('Cannot renumber the peripherals: these new property numbers are already in use: :numbers.', ['numbers' => $numbers]);
    }

    private function log(string $event, Equipment $parent, Equipment $peripheral): void
    {
        activity()
            ->performedOn($parent)
            ->event($event)
            ->withProperties(['peripheral_id' => $peripheral->id, 'peripheral_property_no' => $peripheral->property_no])
            ->log($event);

        activity()
            ->performedOn($peripheral)
            ->event($event)
            ->withProperties(['parent_equipment_id' => $parent->id, 'parent_property_no' => $parent->property_no])
            ->log($event);
    }
}
