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

    /**
     * Saves a new record under the next free number. The item type row is
     * locked so concurrent creates of the same type queue up, the series is
     * read with a locking read, and a collision on the unique index moves
     * on to the following number.
     */
    public function saveNumbered(Equipment $equipment): void
    {
        DB::transaction(function () use ($equipment) {
            $abbreviation = ItemType::query()->lockForUpdate()->whereKey($equipment->item_type_id)->value('abbreviation');

            if (! is_string($abbreviation) || preg_match('/^[A-Z0-9]{3}\z/', $abbreviation) !== 1) {
                throw ValidationException::withMessages(['item_type_id' => __('This item type has no abbreviation yet, so no property number can be generated.')]);
            }

            $year = (int) $equipment->received_date?->format('Y');
            $attempted = 0;

            for ($attempt = 1; ; $attempt++) {
                $sequence = max($this->lastSequence($abbreviation), $attempted) + 1;

                if ($sequence > self::MAX_SEQUENCE) {
                    throw ValidationException::withMessages([
                        'property_no' => __('The :abbreviation property number series is full; no more numbers can be generated.', ['abbreviation' => $abbreviation]),
                    ]);
                }

                $equipment->property_no = sprintf('AC-%04d-IT-%s-%06d', $year, $abbreviation, $sequence);

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

    private function lastSequence(string $abbreviation): int
    {
        $pattern = '/^AC-\d{4}-IT-'.preg_quote($abbreviation, '/').'-(\d{6})\z/';

        return Equipment::withTrashed()
            ->where('property_no', 'like', "AC-____-IT-{$abbreviation}-______")
            ->lockForUpdate()
            ->pluck('property_no')
            ->map(fn (?string $number) => $number !== null && preg_match($pattern, $number, $matches) === 1 ? (int) $matches[1] : 0)
            ->max() ?? 0;
    }
}
