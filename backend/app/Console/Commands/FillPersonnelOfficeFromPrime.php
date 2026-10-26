<?php

namespace App\Console\Commands;

use App\Models\Personnel;
use App\Services\PrimeDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class FillPersonnelOfficeFromPrime extends Command
{
    protected $signature = 'personnel:fill-office-from-prime {--force : Skip the confirmation prompt}';

    protected $description = "Fill empty personnel offices from the matched PRIME employee's department, via the office linked to it. Never overwrites an office.";

    public function handle(PrimeDirectory $prime): int
    {
        try {
            $plan = $this->plan($prime);
        } catch (Throwable $e) {
            $this->error('Could not read PRIME or IRIS: '.$e->getMessage());

            return self::FAILURE;
        }

        $fills = $plan['fills'];
        $this->line(count($fills).' personnel with no office can be filled from PRIME.');
        $this->printSkipped($plan['skipped']);

        if ($fills === []) {
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Fill the office of '.count($fills).' personnel?')) {
            $this->line('Nothing changed.');

            return self::SUCCESS;
        }

        $filled = 0;
        $taken = 0;

        DB::transaction(function () use ($fills, &$filled, &$taken): void {
            $people = Personnel::query()->whereKey(array_keys($fills))->whereNull('office_id')->get();

            foreach ($people as $person) {
                $person->fill(['office_id' => $fills[$person->id]]);
                $person->disableLogging()->save();

                activity()
                    ->performedOn($person)
                    ->byAnonymous()
                    ->event('updated')
                    ->withChanges(['attributes' => ['office_id' => $fills[$person->id]], 'old' => ['office_id' => null]])
                    ->log('Synced from PRIME');

                $filled++;
            }

            $taken = count($fills) - $filled;
        });

        $this->info("Filled {$filled} personnel office(s).");

        if ($taken > 0) {
            $this->line("{$taken} personnel got an office from someone else in the meantime and were left as they are.");
        }

        return self::SUCCESS;
    }

    /**
     * @return array{fills: array<int, int>, skipped: array<string, int>}
     */
    private function plan(PrimeDirectory $prime): array
    {
        $offices = $prime->liveDepartmentOffices();
        $fills = [];
        $skipped = [];

        foreach (Personnel::query()->whereNull('office_id')->orderBy('id')->get(['id', 'employee_id']) as $person) {
            $number = PrimeDirectory::number($person->employee_id);
            $holders = $prime->employeesWithNumber($number);
            $department = $holders->count() === 1 ? $holders->first()->department_school_id : null;
            $department = $department === null || $department === '' ? null : (int) $department;

            $reason = match (true) {
                $number === null => 'no employee number',
                $holders->isEmpty() => 'no PRIME employee with this number',
                $holders->count() > 1 => 'number is on several PRIME employees',
                $department === null => 'PRIME employee has no department',
                ! isset($offices[$department]) => 'no office linked to the PRIME department',
                default => null,
            };

            if ($reason === null) {
                $fills[(int) $person->id] = $offices[$department];
            } else {
                $skipped[(string) $reason] = ($skipped[(string) $reason] ?? 0) + 1;
            }
        }

        return ['fills' => $fills, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, int>  $skipped
     */
    private function printSkipped(array $skipped): void
    {
        $this->line(array_sum($skipped).' personnel with no office are skipped.');

        if ($skipped !== []) {
            $this->table(['Reason', 'Personnel'], collect($skipped)->map(fn (int $count, string $reason) => [$reason, $count])->values()->all());
        }
    }
}
