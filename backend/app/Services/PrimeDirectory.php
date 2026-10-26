<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;

/**
 * @phpstan-type NumberResult array{employee_no: ?string, department_id: ?int, ambiguous: bool, reason: ?string}
 * @phpstan-type UserResult array{user: stdClass, prime_user_id: ?int, touched: bool, current: ?string, proposed: ?string, department_id: ?int, ambiguous: bool, reason: ?string}
 * @phpstan-type Target array{office_id: ?int, office_name: string}
 * @phpstan-type Leftover array{department: stdClass, kept: stdClass, reason: string}
 * @phpstan-type OfficePlan array{
 *     links: list<array{office: stdClass, department: stdClass}>,
 *     ambiguous: list<array{name: string, offices: list<stdClass>, departments: list<stdClass>}>,
 *     leftovers: list<Leftover>,
 *     creates: list<array{department: stdClass, office_type: ?string}>,
 *     create_collisions: list<array{department: stdClass, reason: string}>,
 *     renames: list<array{office: stdClass, department: stdClass, to: string}>,
 *     rename_collisions: list<array{office: stdClass, department: stdClass, to: string, reason: string}>,
 *     gone: list<stdClass>,
 *     iris_only: list<stdClass>,
 *     targets: array<int, Target>
 * }
 */
final class PrimeDirectory
{
    public const DIVISION_OFFICE = 1;

    public const SCHOOL = 2;

    public const MAX_NUMBER_LENGTH = 50;

    private const PLACEHOLDERS = ['N/A', 'NA', 'NONE', 'NEW', 'NULL'];

    private const SCHOOL_TYPES = [
        'Senior High School' => '/(?i:senior high school)|\bSHS\b/',
        'Integrated School' => '/(?i:integrated school)|\bIS\b/',
        'High School' => '/(?i:high school)|\bN?HS\b/',
        'Elementary School' => '/(?i:elementary school|elem\. school)|\bC?ES\b/',
    ];

    /** @var Collection<int, stdClass>|null */
    private ?Collection $departments = null;

    /** @var Collection<int, stdClass>|null */
    private ?Collection $employees = null;

    /** @var Collection<int, stdClass>|null */
    private ?Collection $primeUsers = null;

    /** @var Collection<int, stdClass>|null */
    private ?Collection $offices = null;

    /** @var array<int, int>|null */
    private ?array $employeeCounts = null;

    public static function number(mixed $value): ?string
    {
        $value = trim((string) $value);

        return self::isPlaceholder($value) ? null : $value;
    }

    public static function isPlaceholder(string $value): bool
    {
        return in_array(mb_strtoupper(trim($value)), self::PLACEHOLDERS, true)
            || preg_match('/^[\s0\-\/.]*$/u', $value) === 1;
    }

    public static function numberKey(string $number): string
    {
        return mb_strtolower($number);
    }

    public static function displayName(mixed $value): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim((string) $value));
    }

    public static function nameKey(mixed $value): string
    {
        return mb_strtolower(self::displayName($value));
    }

    public static function hasLinkColumn(): bool
    {
        return Schema::hasColumn('offices', 'prime_department_id');
    }

    public static function hasEmployeeNoColumn(): bool
    {
        return Schema::hasColumn('users', 'employee_no');
    }

    /**
     * @return Collection<int, stdClass>
     */
    public function departments(): Collection
    {
        return $this->departments ??= $this->prime('departments')
            ->get(['dept_id', 'office_id', 'district_id', 'department_school', 'shortened_name', 'office_school_id'])
            ->keyBy(fn (stdClass $row) => (int) $row->dept_id);
    }

    /**
     * @return Collection<int, stdClass>
     */
    public function employees(): Collection
    {
        return $this->employees ??= self::withNumbers($this->prime('employees')
            ->get(['employee_id', 'user_id', 'surname', 'first_name', 'middle_name', 'name_extension', 'deped_id_no', 'deped_email', 'employee_status', 'department_school_id']));
    }

    /**
     * @return Collection<int, stdClass>
     */
    public function primeUsers(): Collection
    {
        return $this->primeUsers ??= $this->prime('users')
            ->get(['user_id', 'full_name', 'email_address', 'user_status', 'department_school_id'])
            ->keyBy(fn (stdClass $row) => (int) $row->user_id);
    }

    /**
     * @return Collection<string, Collection<int, stdClass>>
     */
    public function primeUsersByEmail(): Collection
    {
        return $this->primeUsers()
            ->filter(fn (stdClass $row) => self::nameKey($row->email_address) !== '')
            ->groupBy(fn (stdClass $row) => self::nameKey($row->email_address));
    }

    /**
     * @return Collection<string, Collection<int, stdClass>>
     */
    public function employeesByNumber(): Collection
    {
        return $this->employees()
            ->filter(fn (stdClass $row) => $row->number !== null)
            ->groupBy(fn (stdClass $row) => self::numberKey($row->number));
    }

    /**
     * @return Collection<int, stdClass>
     */
    public function employeesWithNumber(?string $number): Collection
    {
        return $number === null ? collect() : $this->employeesByNumber()->get(self::numberKey($number), collect());
    }

    public function employeeCount(int $departmentId): int
    {
        $this->employeeCounts ??= $this->employees()
            ->filter(fn (stdClass $row) => $row->department_school_id !== null && $row->department_school_id !== '')
            ->countBy(fn (stdClass $row) => (int) $row->department_school_id)
            ->all();

        return $this->employeeCounts[$departmentId] ?? 0;
    }

    /**
     * @return array<int, NumberResult>
     */
    public function employeeNumbers(): array
    {
        $byUser = $this->employees()->whereNotNull('user_id')->groupBy(fn (stdClass $row) => (int) $row->user_id);
        $results = [];

        foreach ($this->primeUsers() as $userId => $user) {
            $results[$userId] = $this->resolveNumber($user, $byUser->get($userId, collect()), fn (string $number) => $this->employeesWithNumber($number));
        }

        return $results;
    }

    /**
     * Resolves one PRIME account without loading the whole directory.
     *
     * @return NumberResult
     */
    public function account(stdClass $primeUser): array
    {
        $rows = self::withNumbers($this->prime('employees')
            ->where('user_id', $primeUser->user_id)
            ->get(['employee_id', 'deped_id_no', 'department_school_id']));

        $result = $this->resolveNumber($primeUser, $rows, fn (string $number) => $this->prime('employees')
            ->whereRaw('LOWER(TRIM(deped_id_no)) = ?', [self::numberKey($number)])
            ->get(['employee_id']));

        if ($result['department_id'] !== null && ! $this->prime('departments')->where('dept_id', $result['department_id'])->exists()) {
            $result['department_id'] = null;
        }

        return $result;
    }

    /**
     * @return array<int, UserResult>
     */
    public function userNumbers(): array
    {
        $byEmail = $this->primeUsersByEmail();
        $numbers = $this->employeeNumbers();
        $current = $this->currentUserNumbers();
        $results = [];

        foreach (DB::table('users')->orderBy('id')->get(['id', 'name', 'email', 'office_id']) as $user) {
            $id = (int) $user->id;
            $matches = $byEmail->get(self::nameKey($user->email), collect());
            $result = ['user' => $user, 'prime_user_id' => null, 'touched' => false, 'current' => $current[$id] ?? null,
                'proposed' => $current[$id] ?? null, 'department_id' => null, 'ambiguous' => false, 'reason' => null];

            if ($matches->count() > 1) {
                $result = [...$result, 'touched' => true, 'proposed' => null, 'ambiguous' => true,
                    'reason' => $matches->count().' PRIME accounts share this email (user_id '.$matches->pluck('user_id')->implode(', ').')'];
            } elseif ($matches->count() === 1) {
                $primeId = (int) $matches->first()->user_id;
                $number = $numbers[$primeId];
                $result = [...$result, 'prime_user_id' => $primeId, 'touched' => true, 'proposed' => $number['employee_no'],
                    'department_id' => $number['department_id'], 'ambiguous' => $number['ambiguous'], 'reason' => $number['reason']];
            }

            $results[$id] = $result;
        }

        $claims = collect($results)->filter(fn (array $r) => $r['proposed'] !== null)->groupBy(fn (array $r) => self::numberKey($r['proposed']));

        foreach ($claims as $claimants) {
            if ($claimants->count() < 2) {
                continue;
            }

            foreach ($claimants->where('touched', true) as $claimant) {
                $id = (int) $claimant['user']->id;
                $others = $claimants->pluck('user.id')->reject(fn ($other) => (int) $other === $id)->implode(', ');
                $results[$id] = [...$results[$id], 'proposed' => null, 'ambiguous' => true,
                    'reason' => "employee number {$claimant['proposed']} also resolves to IRIS user {$others}"];
            }
        }

        return $results;
    }

    /**
     * @return array<int, int>
     */
    public function departmentOffices(): array
    {
        if (! self::hasLinkColumn()) {
            return [];
        }

        return $this->offices()
            ->whereNotNull('prime_department_id')
            ->mapWithKeys(fn (stdClass $office) => [(int) $office->prime_department_id => (int) $office->id])
            ->all();
    }

    /**
     * Linked offices whose department still exists in PRIME, keyed by department id.
     *
     * @return array<int, int>
     */
    public function liveDepartmentOffices(): array
    {
        $departments = $this->departments();

        return array_filter($this->departmentOffices(), fn (int $departmentId) => $departments->has($departmentId), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return array{links: list<array{office: stdClass, department: stdClass}>, ambiguous: list<array{name: string, offices: list<stdClass>, departments: list<stdClass>}>, leftovers: list<Leftover>}
     */
    public function proposedLinks(): array
    {
        $claimed = $this->departmentOffices();

        $offices = $this->offices()
            ->filter(fn (stdClass $office) => ($office->prime_department_id ?? null) === null)
            ->filter(fn (stdClass $office) => self::nameKey($office->office_name) !== '')
            ->groupBy(fn (stdClass $office) => self::nameKey($office->office_name));

        $departments = $this->departments()
            ->reject(fn (stdClass $department, int $id) => isset($claimed[$id]))
            ->filter(fn (stdClass $department) => self::nameKey($department->department_school) !== '')
            ->groupBy(fn (stdClass $department) => self::nameKey($department->department_school));

        $links = [];
        $ambiguous = [];
        $leftovers = [];

        foreach ($offices as $name => $officeMatches) {
            $departmentMatches = $departments->get($name, collect());

            if ($departmentMatches->isEmpty()) {
                continue;
            }

            $department = $departmentMatches->count() === 1 ? $departmentMatches->sole() : $this->staffed($departmentMatches);

            if ($officeMatches->count() === 1 && $department !== null) {
                $links[] = ['office' => $officeMatches->sole(), 'department' => $department];
                array_push($leftovers, ...$this->leftoversOf($departmentMatches, $department));

                continue;
            }

            $ambiguous[] = ['name' => (string) $name, 'offices' => array_values($officeMatches->all()), 'departments' => array_values($departmentMatches->all())];
        }

        return ['links' => $links, 'ambiguous' => $ambiguous, 'leftovers' => $leftovers];
    }

    /**
     * @return list<stdClass>
     */
    public function unmatchedDepartments(): array
    {
        ['links' => $links, 'ambiguous' => $ambiguous, 'leftovers' => $leftovers] = $this->proposedLinks();

        $taken = array_flip(array_keys($this->departmentOffices()));

        foreach ($links as $link) {
            $taken[(int) $link['department']->dept_id] = true;
        }

        foreach ($ambiguous as $group) {
            foreach ($group['departments'] as $department) {
                $taken[(int) $department->dept_id] = true;
            }
        }

        foreach ($leftovers as $leftover) {
            $taken[(int) $leftover['department']->dept_id] = true;
        }

        return array_values($this->departments()->reject(fn (stdClass $department, int $id) => isset($taken[$id]))->all());
    }

    public function officeTypeFor(stdClass $department): ?string
    {
        $label = match ((int) $department->office_id) {
            self::DIVISION_OFFICE => 'Division Office',
            self::SCHOOL => collect(self::SCHOOL_TYPES)
                ->filter(fn (string $pattern) => preg_match($pattern, (string) $department->department_school) === 1)
                ->keys()
                ->first(),
            default => null,
        };

        if ($label === null) {
            return null;
        }

        return $this->officeTypes()->first(fn (string $type) => self::nameKey($type) === self::nameKey($label));
    }

    /**
     * @return OfficePlan
     */
    public function officePlan(): array
    {
        $departments = $this->departments();
        $offices = $this->offices()->keyBy(fn (stdClass $office) => (int) $office->id);
        ['links' => $links, 'ambiguous' => $ambiguous, 'leftovers' => $leftovers] = $this->proposedLinks();

        $linked = [];
        foreach ($this->departmentOffices() as $departmentId => $officeId) {
            $office = $offices->get($officeId);
            $department = $departments->get($departmentId);
            if ($office && $department) {
                $linked[] = ['office' => $office, 'department' => $department];
            }
        }
        $linked = [...$linked, ...$links];

        $gone = array_values($offices
            ->filter(fn (stdClass $office) => ($office->prime_department_id ?? null) !== null && ! $departments->has((int) $office->prime_department_id))
            ->all());

        $departmentByOffice = [];
        foreach ($linked as $link) {
            $departmentByOffice[(int) $link['office']->id] = $link['department'];
        }
        $goneIds = array_flip(array_map(fn (stdClass $office) => (int) $office->id, $gone));
        $irisOnly = array_values($offices->reject(fn (stdClass $office, int $id) => isset($departmentByOffice[$id]) || isset($goneIds[$id]))->all());

        $currentNames = $offices->mapWithKeys(fn (stdClass $office, int $id) => [self::nameKey($office->office_name) => $id]);

        $renames = array_values(array_filter(
            array_map(fn (array $link) => [...$link, 'to' => self::displayName($link['department']->department_school)], $linked),
            fn (array $rename) => $rename['to'] !== '' && self::nameKey($rename['to']) !== self::nameKey($rename['office']->office_name),
        ));
        $renameTargets = collect($renames)->countBy(fn (array $rename) => self::nameKey($rename['to']));

        $unmatched = collect($this->unmatchedDepartments())->groupBy(fn (stdClass $department) => self::nameKey($department->department_school));

        $creates = [];
        $createCollisions = [];
        foreach ($unmatched as $key => $group) {
            $key = (string) $key;
            $holder = $currentNames->has($key) ? $departmentByOffice[$currentNames[$key]] ?? null : null;

            if ($holder !== null && self::nameKey($holder->department_school) === $key) {
                foreach ($group as $department) {
                    $leftovers[] = ['department' => $department, 'kept' => $holder,
                        'reason' => "same name as department #{$holder->dept_id}, linked to office #{$currentNames[$key]}"];
                }

                continue;
            }

            $kept = $group->count() > 1 ? $this->staffed($group) : $group->first();
            $reason = match (true) {
                $key === '' => 'department has no name',
                $currentNames->has($key) => 'name already used by office #'.$currentNames[$key],
                $kept === null => 'name shared by '.$group->count().' unmatched PRIME departments',
                $renameTargets->has($key) => 'name is the rename target of a linked office',
                default => null,
            };

            if ($reason !== null) {
                foreach ($group as $department) {
                    $createCollisions[] = ['department' => $department, 'reason' => $reason];
                }

                continue;
            }

            $creates[] = ['department' => $kept, 'office_type' => $this->officeTypeFor($kept)];
            array_push($leftovers, ...$this->leftoversOf($group, $kept));
        }

        $applied = [];
        $renameCollisions = [];
        foreach ($renames as $rename) {
            $key = self::nameKey($rename['to']);
            $ownId = (int) $rename['office']->id;
            $reason = match (true) {
                $currentNames->has($key) && $currentNames[$key] !== $ownId => 'name already used by office #'.$currentNames[$key],
                $renameTargets[$key] > 1 => 'name is the rename target of '.$renameTargets[$key].' offices',
                $unmatched->has($key) => 'name is also an unmatched PRIME department',
                default => null,
            };

            if ($reason !== null) {
                $renameCollisions[] = [...$rename, 'reason' => $reason];
            } else {
                $applied[] = $rename;
            }
        }

        $renamedTo = [];
        foreach ($applied as $rename) {
            $renamedTo[(int) $rename['department']->dept_id] = $rename['to'];
        }

        $targets = [];
        foreach ($linked as $link) {
            $id = (int) $link['department']->dept_id;
            $targets[$id] = ['office_id' => (int) $link['office']->id, 'office_name' => $renamedTo[$id] ?? (string) $link['office']->office_name];
        }
        foreach ($creates as $create) {
            $targets[(int) $create['department']->dept_id] = ['office_id' => null, 'office_name' => self::displayName($create['department']->department_school)];
        }

        return [
            'links' => $links,
            'ambiguous' => $ambiguous,
            'leftovers' => $leftovers,
            'creates' => $creates,
            'create_collisions' => $createCollisions,
            'renames' => $applied,
            'rename_collisions' => $renameCollisions,
            'gone' => $gone,
            'iris_only' => $irisOnly,
            'targets' => $targets,
        ];
    }

    /**
     * @return Collection<int, stdClass>
     */
    public function offices(): Collection
    {
        return $this->offices ??= DB::table('offices')
            ->orderBy('id')
            ->get(['id', 'office_name', 'office_type', 'school_id', 'is_active', ...(self::hasLinkColumn() ? ['prime_department_id'] : [])]);
    }

    public function forgetOffices(): void
    {
        $this->offices = null;
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @param  callable(string): Collection<int, stdClass>  $holders
     * @return NumberResult
     */
    private function resolveNumber(stdClass $user, Collection $rows, callable $holders): array
    {
        $numbered = $rows->whereNotNull('number')->values();
        $result = ['employee_no' => null, 'department_id' => $this->departmentOf($user, $rows, $numbered), 'ambiguous' => false, 'reason' => null];

        if ($numbered->isEmpty()) {
            return [...$result, 'reason' => match (true) {
                $rows->isEmpty() => 'no employee row',
                $rows->contains(fn (stdClass $row) => trim((string) $row->deped_id_no) !== '') => 'placeholder',
                default => 'employee row has no deped_id_no',
            }];
        }

        if ($numbered->count() > 1) {
            return [...$result, 'ambiguous' => true,
                'reason' => $numbered->count().' numbered employee rows (employee_id '.$numbered->pluck('employee_id')->implode(', ').')'];
        }

        $number = (string) $numbered->first()->number;

        if (mb_strlen($number) > self::MAX_NUMBER_LENGTH) {
            return [...$result, 'ambiguous' => true, 'reason' => 'deped_id_no is longer than '.self::MAX_NUMBER_LENGTH.' characters'];
        }

        $sharing = $holders($number);

        if ($sharing->count() > 1) {
            return [...$result, 'ambiguous' => true,
                'reason' => "deped_id_no {$number} is on ".$sharing->count().' employee rows (employee_id '.$sharing->pluck('employee_id')->implode(', ').')'];
        }

        return [...$result, 'employee_no' => $number];
    }

    /**
     * @param  Collection<int, stdClass>  $departments
     */
    private function staffed(Collection $departments): ?stdClass
    {
        $staffed = $departments->filter(fn (stdClass $department) => $this->employeeCount((int) $department->dept_id) > 0);

        return $staffed->count() === 1 ? $staffed->first() : null;
    }

    /**
     * @param  Collection<int, stdClass>  $group
     * @return list<Leftover>
     */
    private function leftoversOf(Collection $group, stdClass $kept): array
    {
        return array_values($group
            ->reject(fn (stdClass $department) => (int) $department->dept_id === (int) $kept->dept_id)
            ->map(fn (stdClass $department) => ['department' => $department, 'kept' => $kept,
                'reason' => "same name as department #{$kept->dept_id}, which has ".$this->employeeCount((int) $kept->dept_id).' employee(s)'])
            ->all());
    }

    /**
     * @return array<int, string>
     */
    private function currentUserNumbers(): array
    {
        if (! self::hasEmployeeNoColumn()) {
            return [];
        }

        return DB::table('users')->whereNotNull('employee_no')->pluck('employee_no', 'id')
            ->map(fn ($number) => (string) $number)->all();
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return Collection<int, stdClass>
     */
    private static function withNumbers(Collection $rows): Collection
    {
        return $rows->each(function (stdClass $row): void {
            $row->number = self::number($row->deped_id_no);
        });
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @param  Collection<int, stdClass>  $numbered
     */
    private function departmentOf(stdClass $user, Collection $rows, Collection $numbered): ?int
    {
        if ($numbered->count() === 1) {
            $id = $numbered->first()->department_school_id;
        } elseif ($rows->isEmpty()) {
            $id = $user->department_school_id;
        } else {
            $departments = $rows->pluck('department_school_id')->filter()->unique();
            $id = $departments->count() === 1 ? $departments->first() : null;
        }

        return $id === null || $id === '' ? null : (int) $id;
    }

    /**
     * @return Collection<int, string>
     */
    private function officeTypes(): Collection
    {
        return $this->offices()->pluck('office_type')->filter()->unique()->values();
    }

    private function prime(string $table): Builder
    {
        return DB::connection('external_users')->table($table);
    }
}
