<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Equipment;
use App\Models\ItemType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Numbers new equipment AC-{YYYY}-IT-{ABV}-{NNNNNN}: the year it was
 * acquired, its item type's abbreviation, and a series per abbreviation
 * that runs across all years and never resets.
 */
final class EquipmentPropertyNumberService
{
    private const int MAX_ATTEMPTS = 3;

    private const int MAX_SEQUENCE = 999999;

    public const string UNKNOWN_YEAR_PREFIX = 'AC-0000-';

    /**
     * Saves a new record under the next free number. The item type row is
     * locked so concurrent creates of the same type queue up, the series is
     * read with a locking read, and a collision on the unique index moves
     * on to the following number.
     */
    /**
     * Gives a number issued without a year (AC-0000-…) its real year, once known. Numbers that
     * already carry a year, and bundled desktops and peripherals, keep theirs.
     */
    public function renumberUnknownYear(Equipment $equipment, ?int $year = null): bool
    {
        $year ??= $equipment->received_date === null ? null : (int) $equipment->received_date->format('Y');

        if ($year === null || $year < 1 || ! str_starts_with((string) $equipment->property_no, self::UNKNOWN_YEAR_PREFIX)
            || $equipment->parent_equipment_id !== null || $equipment->children()->exists()) {
            return false;
        }

        $this->saveNumbered($equipment, $year);

        return true;
    }

    public function saveNumbered(Equipment $equipment, ?int $year = null): void
    {
        DB::transaction(function () use ($equipment, $year) {
            $abbreviation = ItemType::query()->lockForUpdate()->whereKey($equipment->item_type_id)->value('abbreviation');

            if (! $this->isValidAbbreviation($abbreviation)) {
                throw ValidationException::withMessages(['item_type_id' => __('This item type has no abbreviation yet, so no property number can be generated.')]);
            }

            $year ??= (int) $equipment->received_date?->format('Y');
            $attempted = 0;

            for ($attempt = 1; ; $attempt++) {
                $sequence = max($this->lastSequence($abbreviation), $attempted) + 1;

                if ($sequence > self::MAX_SEQUENCE) {
                    throw ValidationException::withMessages([
                        'property_no' => __('The :abbreviation property number series is full; no more numbers can be generated.', ['abbreviation' => $abbreviation]),
                    ]);
                }

                $equipment->property_no = $this->format($year, $abbreviation, $sequence);

                try {
                    DB::transaction(fn () => $equipment->save());

                    return;
                } catch (UniqueConstraintViolationException) {
                    if ($attempt >= self::MAX_ATTEMPTS) {
                        throw ValidationException::withMessages([
                            'property_no' => __('Property number :number was just taken by another record. Please try again.', ['number' => $equipment->property_no]),
                        ]);
                    }

                    $attempted = $sequence;
                }
            }
        });
    }

    /**
     * The number the next create of this item type would receive, without
     * reserving it; null when none can be generated.
     */
    public function preview(ItemType $itemType, int $year): ?string
    {
        $abbreviation = $itemType->abbreviation;

        if (! $this->isValidAbbreviation($abbreviation)) {
            return null;
        }

        $sequence = $this->lastSequence($abbreviation, lock: false) + 1;

        return $sequence > self::MAX_SEQUENCE ? null : $this->format($year, $abbreviation, $sequence);
    }

    /**
     * @phpstan-assert-if-true string $abbreviation
     */
    private function isValidAbbreviation(mixed $abbreviation): bool
    {
        return is_string($abbreviation) && preg_match('/^[A-Z0-9]{3}\z/', $abbreviation) === 1;
    }

    private function format(int $year, string $abbreviation, int $sequence): string
    {
        return sprintf('AC-%04d-IT-%s-%06d', $year, $abbreviation, $sequence);
    }

    private function lastSequence(string $abbreviation, bool $lock = true): int
    {
        $pattern = '/^AC-\d{4}-IT-'.preg_quote($abbreviation, '/').'-(\d{6})\z/';

        return Equipment::withTrashed()
            ->where('property_no', 'like', "AC-____-IT-{$abbreviation}-______")
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->pluck('property_no')
            ->map(fn (?string $number) => $number !== null && preg_match($pattern, $number, $matches) === 1 ? (int) $matches[1] : 0)
            ->max() ?? 0;
    }
}
