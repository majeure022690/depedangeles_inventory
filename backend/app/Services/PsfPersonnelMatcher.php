<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Office;
use App\Models\Personnel;
use Illuminate\Support\Collection;

/**
 * Finds a workbook's "Name - Employee No." among one office's personnel only.
 */
final class PsfPersonnelMatcher
{
    /** @var Collection<int, Personnel> */
    private Collection $people;

    private ?Personnel $head;

    public function __construct(Office $office)
    {
        $this->people = Personnel::query()->where('office_id', $office->id)
            ->schoolHeadsFirst()->with('personnelPosition:id,name')
            ->get(['id', 'employee_id', 'full_name', 'first_name', 'last_name', 'position_id', 'inactive']);

        $this->head = $this->people->first(fn (Personnel $person) => ! $person->inactive
            && preg_match('/principal|head teacher|school head/i', (string) $person->personnelPosition?->name) === 1);
    }

    /**
     * @return array{id: ?int, name: ?string, given: ?string, note: ?string}
     */
    public function resolve(?string $given, bool $fallbackToHead): array
    {
        $person = $given === null ? null : ($this->byNumber($given) ?? $this->byName($given));

        if ($person !== null) {
            return ['id' => $person->id, 'name' => $person->full_name, 'given' => $given, 'note' => null];
        }

        if (! $fallbackToHead) {
            return ['id' => null, 'name' => null, 'given' => $given, 'note' => null];
        }

        $reason = $given === null ? 'no accountable officer in the file' : 'accountable officer not found at this school';

        return $this->head === null
            ? ['id' => null, 'name' => null, 'given' => $given, 'note' => "{$reason}, and no school head to fall back on: left unassigned"]
            : ['id' => $this->head->id, 'name' => $this->head->full_name, 'given' => $given, 'note' => "{$reason}: assigned to the school head"];
    }

    private function byNumber(string $given): ?Personnel
    {
        if (preg_match('/[-–]\s*([A-Za-z0-9]+)\s*$/u', $given, $m) !== 1) {
            return null;
        }
        $number = self::numberKey($m[1]);

        $matches = $this->people->filter(fn (Personnel $person) => $person->employee_id !== null && self::numberKey((string) $person->employee_id) === $number);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function byName(string $given): ?Personnel
    {
        $name = self::nameKey((string) preg_replace('/\s*[-–]\s*[A-Za-z0-9]+\s*$/u', '', $given));
        if ($name === '') {
            return null;
        }

        $matches = $this->people->filter(fn (Personnel $person) => in_array($name, [
            self::nameKey((string) $person->full_name),
            self::nameKey("{$person->first_name} {$person->last_name}"),
            self::nameKey("{$person->last_name} {$person->first_name}"),
        ], true));

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private static function numberKey(string $number): string
    {
        return ltrim(mb_strtolower(trim($number)), '0');
    }

    private static function nameKey(string $name): string
    {
        $words = preg_split('/[^a-z]+/', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_filter($words, fn (string $word) => strlen($word) > 1));
    }
}
