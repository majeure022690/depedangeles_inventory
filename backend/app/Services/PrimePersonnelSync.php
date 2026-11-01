<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Personnel;
use App\Models\Position;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * Makes Personnel a copy of PRIME's employees: matched by PRIME employee id,
 * else by DepEd employee number, then PRIME's values overwrite the
 * PRIME-owned fields. Records keep their id so equipment stays attached.
 *
 * @phpstan-type Attributes array<string, mixed>
 * @phpstan-type Plan array{
 *     creates: list<array{prime_employee_id: int, attributes: Attributes, position: ?string}>,
 *     updates: list<array{id: int, prime_employee_id: int, attributes: Attributes, position: ?string, changes: list<string>}>,
 *     unchanged: int,
 *     deactivations: list<int>,
 *     legacy_removable: list<int>,
 *     legacy_referenced: array<int, int>,
 *     skipped: array<string, int>
 * }
 */
class PrimePersonnelSync
{
    private const APPLICANT = 'inactive - applicant';

    /** Places that point at a personnel row; a row any of them uses can't be removed. */
    private const REFERENCES = [
        ['equipment', 'current_accountable_officer_id'],
        ['equipment', 'current_end_user_id'],
        ['equipment_transactions', 'accountable_officer_id'],
        ['equipment_transactions', 'end_user_id'],
        ['equipment_transactions', 'received_by_id'],
        ['property_items', 'accountable_officer_id'],
        ['property_items', 'end_user_id'],
    ];

    public function __construct(private readonly PrimeDirectory $directory) {}

    /**
     * What a sync would do, without writing anything.
     *
     * @return Plan
     */
    public function plan(): array
    {
        $rows = $this->primeRows();
        $offices = $this->directory->liveDepartmentOffices();
        $positions = Position::query()->get(['id', 'name'])->mapWithKeys(fn (Position $position) => [PrimeDirectory::nameKey($position->name) => $position->id]);
        $local = Personnel::withTrashed()->get();
        $byPrimeId = $local->whereNotNull('prime_employee_id')->keyBy(fn (Personnel $person) => (int) $person->prime_employee_id);
        $byNumber = $local->whereNull('prime_employee_id')->groupBy(fn (Personnel $person) => PrimeDirectory::numberKey((string) PrimeDirectory::number($person->employee_id)));

        $plan = ['creates' => [], 'updates' => [], 'unchanged' => 0, 'deactivations' => [], 'legacy_removable' => [], 'legacy_referenced' => [], 'skipped' => []];
        $skip = function (string $reason) use (&$plan): void {
            $plan['skipped'][$reason] = ($plan['skipped'][$reason] ?? 0) + 1;
        };

        $numberCounts = $rows->filter(fn (stdClass $row) => $row->number !== null)->countBy(fn (stdClass $row) => PrimeDirectory::numberKey($row->number));
        $matchedIds = [];
        $seenPrimeIds = [];

        foreach ($rows as $row) {
            if ($row->number === null) {
                $skip('no employee number in PRIME');

                continue;
            }

            if (mb_strtolower(trim((string) $row->employee_status)) === self::APPLICANT) {
                $skip('applicant, never hired');

                continue;
            }

            if ($numberCounts[PrimeDirectory::numberKey($row->number)] > 1) {
                $skip('employee number shared by several PRIME employees');

                continue;
            }

            $primeId = (int) $row->employee_id;
            $seenPrimeIds[$primeId] = true;
            $attributes = $this->attributes($row, $offices);
            $positionName = PrimeDirectory::displayName($row->position_title);
            $positionName = $positionName === '' ? null : $positionName;
            $attributes['position_id'] = $positionName === null ? null : ($positions[PrimeDirectory::nameKey($positionName)] ?? null);

            $person = $byPrimeId->get($primeId) ?? $byNumber->get(PrimeDirectory::numberKey($row->number))?->first();

            if ($person === null) {
                $plan['creates'][] = ['prime_employee_id' => $primeId, 'attributes' => $attributes, 'position' => $positionName];

                continue;
            }

            $matchedIds[$person->id] = true;
            $changes = $this->changedFields($person, $attributes, $positionName !== null && $attributes['position_id'] === null);

            if ($changes === [] && $person->prime_employee_id !== null && ! $person->trashed()) {
                $plan['unchanged']++;

                continue;
            }

            $plan['updates'][] = ['id' => $person->id, 'prime_employee_id' => $primeId, 'attributes' => $attributes, 'position' => $positionName, 'changes' => $changes];
        }

        foreach ($local->whereNotNull('prime_employee_id') as $person) {
            if (! isset($seenPrimeIds[(int) $person->prime_employee_id]) && ! isset($matchedIds[$person->id]) && ! $person->inactive && ! $person->trashed()) {
                $plan['deactivations'][] = $person->id;
            }
        }

        $legacy = $local->whereNull('prime_employee_id')->reject(fn (Personnel $person) => isset($matchedIds[$person->id]) || $person->trashed());
        $references = $this->referenceCounts(array_values($legacy->map(fn (Personnel $person): int => $person->id)->all()));

        foreach ($legacy as $person) {
            if (($references[$person->id] ?? 0) > 0) {
                $plan['legacy_referenced'][$person->id] = $references[$person->id];
            } else {
                $plan['legacy_removable'][] = $person->id;
            }
        }

        return $plan;
    }

    /**
     * @param  Plan  $plan
     * @return array{created: int, updated: int, deactivated: int, removed: int, positions_created: int}
     */
    public function apply(array $plan, bool $removeLegacy): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'deactivated' => 0, 'removed' => 0, 'positions_created' => 0];
        $now = now();

        DB::transaction(function () use ($plan, $removeLegacy, $now, &$counts): void {
            $positionIds = [];
            $positionId = function (?string $name, mixed $known) use (&$positionIds, &$counts): ?int {
                if ($known !== null || $name === null) {
                    return $known === null ? null : (int) $known;
                }

                $key = PrimeDirectory::nameKey($name);

                if (! isset($positionIds[$key])) {
                    $position = Position::query()->whereRaw('LOWER(name) = ?', [$key])->first();

                    if ($position === null) {
                        $position = Position::query()->create(['name' => $name, 'is_active' => true, 'sort_order' => 0]);
                        $counts['positions_created']++;
                    }

                    $positionIds[$key] = $position->id;
                }

                return $positionIds[$key];
            };

            foreach ($plan['updates'] as $update) {
                $person = Personnel::withTrashed()->findOrFail($update['id']);
                $attributes = [...$update['attributes'], 'position_id' => $positionId($update['position'], $update['attributes']['position_id'])];

                if ($person->trashed()) {
                    $person->restore();
                }

                $person->forceFill(['prime_employee_id' => $update['prime_employee_id'], 'prime_synced_at' => $now, ...$attributes])->save();
                $counts['updated']++;
            }

            foreach ($plan['creates'] as $create) {
                $attributes = [...$create['attributes'], 'position_id' => $positionId($create['position'], $create['attributes']['position_id'])];
                $person = new Personnel;
                $person->forceFill(['prime_employee_id' => $create['prime_employee_id'], 'prime_synced_at' => $now, ...$attributes])->save();
                $counts['created']++;
            }

            foreach (Personnel::query()->whereKey($plan['deactivations'])->get() as $person) {
                $person->forceFill(['inactive' => true, 'separation_cause' => $person->separation_cause ?? 'No longer in PRIME', 'prime_synced_at' => $now])->save();
                $counts['deactivated']++;
            }

            if ($removeLegacy && $plan['legacy_removable'] !== []) {
                $stillFree = array_diff($plan['legacy_removable'], array_keys($this->referenceCounts($plan['legacy_removable'])));

                foreach (Personnel::query()->whereKey($stillFree)->whereNull('prime_employee_id')->get() as $person) {
                    $person->delete();
                    $counts['removed']++;
                }
            }
        });

        activity()
            ->event('personnel.prime_synced')
            ->withProperties([...$counts, 'legacy_kept_referenced' => count($plan['legacy_referenced']), 'skipped' => $plan['skipped']])
            ->log('personnel.prime_synced');

        return $counts;
    }

    /**
     * @return Collection<int, stdClass>
     */
    private function primeRows(): Collection
    {
        $prime = DB::connection('external_users');

        $separations = $prime->table('service_records')
            ->whereNotNull('separation_date')
            ->orderBy('separation_date')
            ->get(['user_id', 'separation_date', 'separation_cause'])
            ->keyBy(fn (stdClass $row) => (string) $row->user_id);

        return $prime->table('employees')
            ->leftJoin('personnelposition', 'personnelposition.personnel_position_id', '=', 'employees.personnel_position_id')
            ->orderBy('employees.employee_id')
            ->get([
                'employees.employee_id', 'employees.user_id', 'employees.surname', 'employees.first_name', 'employees.middle_name',
                'employees.name_extension', 'employees.deped_id_no', 'employees.deped_email', 'employees.employee_status',
                'employees.appointment_status', 'employees.department_school_id', 'employees.curr_psipop_item_number',
                'employees.psipop_item_level', 'employees.actual_workstation', 'employees.date_original_appointment',
                'employees.date_last_promotion', 'personnelposition.position_title',
            ])
            ->each(function (stdClass $row) use ($separations): void {
                $row->number = PrimeDirectory::number($row->deped_id_no);
                $row->separation = $row->user_id === null ? null : $separations->get((string) $row->user_id);
            });
    }

    /**
     * PRIME's values for the fields it owns, except position (resolved separately).
     *
     * @param  array<int, int>  $offices  PRIME department id => IRIS office id
     * @return Attributes
     */
    private function attributes(stdClass $row, array $offices): array
    {
        $status = PrimeDirectory::displayName($row->employee_status);
        $inactive = $status !== '' && mb_strtolower($status) !== 'active';
        $department = $row->department_school_id === null || $row->department_school_id === '' ? null : (int) $row->department_school_id;

        return [
            'employee_id' => (string) $row->number,
            'last_name' => PrimeDirectory::displayName($row->surname),
            'first_name' => PrimeDirectory::displayName($row->first_name),
            'middle_name' => self::text($row->middle_name),
            'suffix' => self::text($row->name_extension, 20),
            'deped_email' => self::text(mb_strtolower((string) $row->deped_email)),
            'office_id' => $department === null ? null : ($offices[$department] ?? null),
            'date_hired' => self::date($row->date_original_appointment),
            'inactive' => $inactive,
            'separation_date' => $inactive ? self::date($row->separation?->separation_date) : null,
            'separation_cause' => $inactive ? (self::text($row->separation?->separation_cause) ?? self::text(preg_replace('/^inactive\s*-\s*/i', '', $status))) : null,
            'appointment_status' => self::text($row->appointment_status, 50),
            'plantilla_item_no' => self::text($row->curr_psipop_item_number, 50),
            'plantilla_item_level' => self::text($row->psipop_item_level),
            'workstation' => self::text($row->actual_workstation),
            'date_last_promotion' => self::date($row->date_last_promotion),
        ];
    }

    /**
     * @param  Attributes  $attributes
     * @return list<string>
     */
    private function changedFields(Personnel $person, array $attributes, bool $newPosition): array
    {
        $changes = [];

        foreach ($attributes as $field => $value) {
            $current = $person->getAttribute($field);
            $current = $current instanceof Carbon ? $current->toDateString() : $current;

            if ($field === 'position_id' && $newPosition) {
                $changes[] = $field;
            } elseif ((is_bool($value) ? (bool) $current : ($current === null ? null : (string) $current)) !== (is_bool($value) ? $value : ($value === null ? null : (string) $value))) {
                $changes[] = $field;
            }
        }

        return $changes;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int> personnel id => number of rows pointing at it
     */
    private function referenceCounts(array $ids): array
    {
        $counts = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (self::REFERENCES as [$table, $column]) {
                try {
                    $rows = DB::table($table)->whereIn($column, $chunk)->selectRaw("{$column} as id, count(*) as n")->groupBy($column)->get();
                } catch (Throwable) {
                    continue;
                }

                foreach ($rows as $row) {
                    $counts[(int) $row->id] = ($counts[(int) $row->id] ?? 0) + (int) $row->n;
                }
            }
        }

        return $counts;
    }

    private static function text(mixed $value, int $max = 255): ?string
    {
        $text = PrimeDirectory::displayName($value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    private static function date(mixed $value): ?string
    {
        $text = trim((string) $value);

        if ($text === '' || preg_match('/^\d{4}-\d{2}-\d{2}/', $text) !== 1) {
            return null;
        }

        try {
            return Carbon::parse(substr($text, 0, 10))->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
