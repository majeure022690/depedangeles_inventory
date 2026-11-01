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
    /**
     * Fallback order when no officer matches; an assistant never outranks the head.
     */
    private const array HEAD_RANKS = [
        '/^(?!.*assistant).*principal/i',
        '/head teacher|school head/i',
        '/assistant.*principal|school administrator/i',
    ];

    /** @var Collection<int, Personnel> */
    private Collection $people;

    private ?Personnel $head;

    /** @var Collection<string, Personnel>|null */
    private ?Collection $everyone = null;

    /**
     * A named head must already be personnel of this office.
     */
    public function __construct(Office $office, ?string $headEmployeeNo = null)
    {
        $this->people = Personnel::query()->where('office_id', $office->id)
            ->with('personnelPosition:id,name')
            ->orderBy('last_name')->orderBy('first_name')
            ->get(['id', 'employee_id', 'full_name', 'first_name', 'last_name', 'position_id', 'inactive', 'oic']);

        $named = $headEmployeeNo === null ? null : $this->people->first(
            fn (Personnel $person) => $person->employee_id !== null && self::numberKey((string) $person->employee_id) === self::numberKey($headEmployeeNo),
        );

        $this->head = $named ?? $this->pickHead();
    }

    public function hasHead(): bool
    {
        return $this->head !== null;
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

        $reason = $given === null ? 'no accountable officer in the file' : 'accountable officer not found at this school ('.$this->whyNotFound($given).')';

        return $this->head === null
            ? ['id' => null, 'name' => null, 'given' => $given, 'note' => "{$reason}, and no school head to fall back on: left unassigned"]
            : ['id' => $this->head->id, 'name' => $this->head->full_name, 'given' => $given, 'note' => "{$reason}: assigned to the school head"];
    }

    private function pickHead(): ?Personnel
    {
        $active = $this->people->reject(fn (Personnel $person) => (bool) $person->inactive);

        foreach (self::HEAD_RANKS as $pattern) {
            $head = $active->first(fn (Personnel $person) => preg_match($pattern, (string) $person->personnelPosition?->name) === 1);
            if ($head !== null) {
                return $head;
            }
        }

        return $active->first(fn (Personnel $person) => (bool) $person->oic);
    }

    private function byNumber(string $given): ?Personnel
    {
        $number = self::numberIn($given);
        if ($number === null) {
            return null;
        }

        $matches = $this->people->filter(fn (Personnel $person) => $person->employee_id !== null && self::numberKey((string) $person->employee_id) === $number);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * The full name, or first and last name appearing among the given words.
     */
    private function byName(string $given): ?Personnel
    {
        $words = self::words((string) preg_replace('/\s*[-–]\s*[A-Za-z0-9]+\s*$/u', '', $given));
        if ($words === []) {
            return null;
        }

        $matches = $this->people->filter(function (Personnel $person) use ($words) {
            $first = self::words((string) $person->first_name)[0] ?? null;
            $last = self::words((string) $person->last_name);

            return $first !== null && $last !== [] && in_array($first, $words, true) && array_diff($last, $words) === [];
        });

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * Why a given officer matched nobody here; names another office only in this admin-run report.
     */
    private function whyNotFound(string $given): string
    {
        $number = self::numberIn($given);
        if ($number === null) {
            return 'no employee no., and the name matched no one';
        }

        $this->everyone ??= Personnel::query()->whereNotNull('employee_id')->with('office:id,office_name')
            ->get(['id', 'employee_id', 'office_id'])
            ->keyBy(fn (Personnel $person) => self::numberKey((string) $person->employee_id));
        $person = $this->everyone->get($number);

        return match (true) {
            $person === null => 'employee no. not in IRIS personnel',
            $person->office_id === null => 'employee no. belongs to personnel with no office',
            default => 'employee no. belongs to personnel at '.$person->office?->office_name,
        };
    }

    /**
     * The employee no. after the name separator, read whole even when written as "123-4567".
     */
    private static function numberIn(string $given): ?string
    {
        if (preg_match('/\s[-–]\s+([A-Za-z0-9][A-Za-z0-9.\-–\s]*)$/u', $given, $m) !== 1
            && preg_match('/[-–]\s*([A-Za-z0-9]*\d[A-Za-z0-9]*)\s*$/u', $given, $m) !== 1) {
            return null;
        }
        $number = self::numberKey($m[1]);

        return preg_match('/\d/', $number) === 1 ? $number : null;
    }

    private static function numberKey(string $number): string
    {
        return ltrim((string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($number)), '0');
    }

    /**
     * @return list<string>
     */
    private static function words(string $name): array
    {
        $words = preg_split('/[^a-z]+/', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($words, fn (string $word) => strlen($word) > 1));
    }
}
