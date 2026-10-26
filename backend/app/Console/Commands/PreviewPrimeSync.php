<?php

namespace App\Console\Commands;

use App\Services\PrimeDirectory;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * @phpstan-import-type OfficePlan from PrimeDirectory
 */
class PreviewPrimeSync extends Command
{
    protected $signature = 'prime:sync-preview {--section= : Print one section only: users, personnel, offices, user-offices, personnel-offices}';

    protected $description = 'Read-only: preview the employee numbers, office links and office assignments the PRIME sync would apply';

    private const SECTIONS = ['users', 'personnel', 'offices', 'user-offices', 'personnel-offices'];

    private PrimeDirectory $prime;

    /** @var OfficePlan|null */
    private ?array $plan = null;

    public function handle(PrimeDirectory $prime): int
    {
        $this->prime = $prime;
        $section = $this->option('section');

        if ($section !== null && ! in_array($section, self::SECTIONS, true)) {
            $this->error('Unknown section. Use one of: '.implode(', ', self::SECTIONS).'.');

            return self::FAILURE;
        }

        $this->line('Read-only preview. Nothing is written to IRIS or PRIME.');
        $this->line('users.employee_no: '.(PrimeDirectory::hasEmployeeNoColumn() ? 'present' : 'absent (current numbers read from personnel.user_id links)')
            .'; offices.prime_department_id: '.(PrimeDirectory::hasLinkColumn() ? 'present' : 'absent (no office is linked yet)'));

        try {
            foreach (self::SECTIONS as $name) {
                if ($section === null || $section === $name) {
                    $this->newLine();
                    match ($name) {
                        'users' => $this->previewUsers(),
                        'personnel' => $this->previewPersonnel(),
                        'offices' => $this->previewOffices(),
                        'user-offices' => $this->previewUserOffices(),
                        'personnel-offices' => $this->previewPersonnelOffices(),
                    };
                }
            }
        } catch (QueryException $e) {
            $this->error('Could not read PRIME or IRIS: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function previewUsers(): void
    {
        $this->info('== (a) IRIS users: employee number from PRIME ==');

        $results = collect($this->prime->userNumbers());
        $changes = $results
            ->filter(fn (array $r) => $r['touched'] && $r['current'] !== $r['proposed'])
            ->map(fn (array $r) => [
                $r['user']->id, $r['user']->name, $r['user']->email,
                $r['current'] ?? '-', $r['proposed'] ?? '-',
                match (true) {
                    $r['current'] === null => 'gets',
                    $r['proposed'] === null => 'loses',
                    default => 'changes',
                },
                $r['proposed'] === null ? ($r['reason'] ?? '') : '',
            ]);

        $this->section(
            $changes->count().' user(s) would get, change or lose an employee number:',
            ['User ID', 'User', 'Email', 'Current', 'New', 'Change', 'Reason'],
            $changes,
        );

        $ambiguous = $results->where('ambiguous', true)
            ->map(fn (array $r) => [$r['user']->id, $r['user']->name, $r['user']->email, $r['prime_user_id'] ?? '-', $r['reason']]);

        $this->section(
            $ambiguous->count().' user(s) left without a number because PRIME is ambiguous:',
            ['User ID', 'User', 'Email', 'PRIME user_id', 'Reason'],
            $ambiguous,
        );

        $this->line($results->where('touched', true)->where('ambiguous', false)->whereNotNull('proposed')->count().' user(s) resolve to a number; '
            .$results->where('touched', false)->count().' user(s) have no PRIME account and are not touched.');
    }

    private function previewPersonnel(): void
    {
        $this->info('== (b) Personnel and PRIME employees by employee number ==');

        $byNumber = $this->prime->employeesByNumber();
        $primeUsers = $this->prime->primeUsers();
        $matched = [];
        $ambiguous = [];
        $unmatched = [];

        foreach ($this->personnel() as $person) {
            $number = PrimeDirectory::number($person->employee_id);
            $holders = $number === null ? collect() : $byNumber->get($number, collect());

            if ($holders->isEmpty()) {
                $unmatched[] = [$person->id, $person->employee_id, $this->personnelName($person)];

                continue;
            }

            if ($holders->count() > 1) {
                $ambiguous[] = [$person->id, $person->employee_id, $this->personnelName($person), $holders->pluck('employee_id')->implode(', ')];

                continue;
            }

            $employee = $holders->first();
            $account = $employee->user_id === null ? null : $primeUsers->get((int) $employee->user_id);

            $matched[] = [
                $person->employee_id,
                $this->personnelName($person),
                $this->employeeName($employee),
                $account ? $account->email_address : ($employee->deped_email ?? '-').' (no PRIME account)',
                $this->departmentLabel($employee->department_school_id),
            ];
        }

        $this->section(count($matched).' personnel matched to exactly one PRIME employee:', ['Employee ID', 'IRIS name', 'PRIME name', 'PRIME email', 'PRIME department'], $matched);
        $this->section(count($ambiguous).' personnel whose number is on more than one PRIME employee row:', ['Personnel ID', 'Employee ID', 'IRIS name', 'PRIME employee_ids'], $ambiguous);
        $this->section(count($unmatched).' personnel with no PRIME match:', ['Personnel ID', 'Employee ID', 'IRIS name'], $unmatched);
    }

    private function previewOffices(): void
    {
        $this->info('== (c) Offices and PRIME departments ==');

        $plan = $this->plan();

        $this->section(count($plan['links']).' office(s) would be linked to a PRIME department by name:', ['Office ID', 'Office', 'Dept ID', 'Department'],
            array_map(fn (array $link) => [$link['office']->id, $link['office']->office_name, $link['department']->dept_id, $link['department']->department_school], $plan['links']));

        $this->section(count($plan['creates']).' office(s) would be created for unmatched PRIME departments:', ['Dept ID', 'Department', 'PRIME office_id', 'office_type', 'office_school_id'],
            array_map(fn (array $create) => [
                $create['department']->dept_id,
                trim((string) $create['department']->department_school),
                $create['department']->office_id,
                $create['office_type'] ?? '(none, set by hand)',
                $create['department']->office_school_id ?? '-',
            ], $plan['creates']));

        $this->section(count($plan['create_collisions']).' unmatched PRIME department(s) that cannot become an office:', ['Dept ID', 'Department', 'Reason'],
            array_map(fn (array $c) => [$c['department']->dept_id, $c['department']->department_school ?? '-', $c['reason']], $plan['create_collisions']));

        $this->section(count($plan['renames']).' linked office(s) would be renamed to the PRIME name:', ['Office ID', 'Current name', 'New name', 'Dept ID'],
            array_map(fn (array $r) => [$r['office']->id, $r['office']->office_name, $r['to'], $r['department']->dept_id], $plan['renames']));

        $this->section(count($plan['rename_collisions']).' rename(s) skipped because office_name must be unique:', ['Office ID', 'Current name', 'PRIME name', 'Dept ID', 'Reason'],
            array_map(fn (array $r) => [$r['office']->id, $r['office']->office_name, $r['to'], $r['department']->dept_id, $r['reason']], $plan['rename_collisions']));

        $this->section(count($plan['ambiguous']).' ambiguous name(s), left unlinked:', ['Name', 'Office IDs', 'Dept IDs'],
            array_map(fn (array $a) => [$a['name'], collect($a['offices'])->pluck('id')->implode(', '), collect($a['departments'])->pluck('dept_id')->implode(', ')], $plan['ambiguous']));

        $this->section(count($plan['gone']).' linked office(s) whose PRIME department no longer exists:', ['Office ID', 'Office', 'prime_department_id'],
            array_map(fn (stdClass $office) => [$office->id, $office->office_name, $office->prime_department_id], $plan['gone']));

        $this->section(count($plan['iris_only']).' IRIS-only office(s) with no PRIME department:', ['Office ID', 'Office', 'office_type', 'Active'],
            array_map(fn (stdClass $office) => [$office->id, $office->office_name, $office->office_type ?? '-', $office->is_active ? 'yes' : 'no'], $plan['iris_only']));
    }

    private function previewUserOffices(): void
    {
        $this->info('== (d) IRIS users whose office would change or be cleared ==');

        $offices = $this->officeNames();
        $rows = [];

        foreach ($this->prime->userNumbers() as $result) {
            if (! $result['touched']) {
                continue;
            }

            $user = $result['user'];
            $current = $user->office_id === null ? null : (int) $user->office_id;
            $target = $result['department_id'] === null ? null : ($this->plan()['targets'][$result['department_id']] ?? null);

            if ($target !== null && $target['office_id'] !== null && $target['office_id'] === $current) {
                continue;
            }

            if ($target === null && $current === null) {
                continue;
            }

            $rows[] = [
                $user->id,
                $user->name,
                $user->email,
                $current === null ? '-' : ($offices[$current] ?? '#'.$current),
                $result['department_id'] === null ? ($result['ambiguous'] ? '(ambiguous)' : '-') : $this->departmentLabel($result['department_id']),
                $target === null ? '-' : $this->targetLabel($target),
                match (true) {
                    $target === null => 'cleared',
                    $current === null => 'set',
                    default => 'changed',
                },
            ];
        }

        $this->section(count($rows).' user(s) whose office would change or be cleared:', ['User ID', 'User', 'Email', 'Current office', 'PRIME department', 'New office', 'Change'], $rows);
    }

    private function previewPersonnelOffices(): void
    {
        $this->info('== (e) Personnel whose empty office would be filled ==');

        $byNumber = $this->prime->employeesByNumber();
        $rows = [];
        $unfilled = 0;

        foreach ($this->personnel()->whereNull('office_id') as $person) {
            $number = PrimeDirectory::number($person->employee_id);
            $holders = $number === null ? collect() : $byNumber->get($number, collect());
            $department = $holders->count() === 1 ? $holders->first()->department_school_id : null;
            $target = $department === null ? null : ($this->plan()['targets'][(int) $department] ?? null);

            if ($target === null) {
                $unfilled++;

                continue;
            }

            $rows[] = [$person->id, $person->employee_id, $this->personnelName($person), $this->departmentLabel($department), $this->targetLabel($target)];
        }

        $this->section(count($rows).' personnel with no office would get one:', ['Personnel ID', 'Employee ID', 'IRIS name', 'PRIME department', 'Office'], $rows);
        $this->line("{$unfilled} personnel with no office stay empty (no single PRIME match, or no office for the department).");
    }

    /**
     * @return OfficePlan
     */
    private function plan(): array
    {
        return $this->plan ??= $this->prime->officePlan();
    }

    /**
     * @param  iterable<array-key, array<int, mixed>>  $rows
     * @param  list<string>  $headers
     */
    private function section(string $title, array $headers, iterable $rows): void
    {
        $rows = collect($rows)->values()->all();

        $this->line($title);
        if ($rows !== []) {
            $this->table($headers, $rows);
        }
        $this->newLine();
    }

    /**
     * @return Collection<int, stdClass>
     */
    private function personnel(): Collection
    {
        return DB::table('personnel')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'employee_id', 'last_name', 'first_name', 'middle_name', 'suffix', 'office_id']);
    }

    /**
     * @return array<int, string>
     */
    private function officeNames(): array
    {
        return $this->prime->offices()->mapWithKeys(fn (stdClass $office) => [(int) $office->id => "{$office->office_name} (#{$office->id})"])->all();
    }

    private function personnelName(stdClass $person): string
    {
        return trim("{$person->last_name}, ".implode(' ', array_filter([$person->first_name, $person->middle_name, $person->suffix])));
    }

    private function employeeName(stdClass $employee): string
    {
        return trim(trim((string) $employee->surname).', '.implode(' ', array_filter(array_map(
            fn ($part) => trim((string) $part),
            [$employee->first_name, $employee->middle_name, $employee->name_extension],
        ))));
    }

    private function departmentLabel(mixed $id): string
    {
        if ($id === null || $id === '') {
            return '-';
        }

        $department = $this->prime->departments()->get((int) $id);

        return $department ? trim((string) $department->department_school)." (#{$id})" : "#{$id} (not in PRIME)";
    }

    /**
     * @param  array{office_id: ?int, office_name: string}  $target
     */
    private function targetLabel(array $target): string
    {
        return $target['office_id'] === null ? "new office: {$target['office_name']}" : "{$target['office_name']} (#{$target['office_id']})";
    }
}
