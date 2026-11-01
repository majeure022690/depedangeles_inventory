<?php

namespace App\Console\Commands;

use App\Models\Personnel;
use App\Services\PrimePersonnelSync;
use Illuminate\Console\Command;
use Throwable;

class SyncPersonnelFromPrime extends Command
{
    protected $signature = 'personnel:sync-prime
        {--dry-run : Show what would change without writing anything}
        {--remove-legacy : Also remove IRIS-only personnel that no equipment, transaction or property item points to}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Make Personnel a copy of PRIME employees: create missing ones, overwrite PRIME-owned fields, deactivate those gone from PRIME';

    public function handle(PrimePersonnelSync $sync): int
    {
        try {
            $plan = $sync->plan();
        } catch (Throwable $e) {
            $this->error('Could not read PRIME or IRIS: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('PRIME personnel sync'.($this->option('dry-run') ? ' (dry run, nothing will be written)' : ''));
        $this->table(['', 'Records'], [
            ['Create (in PRIME, not in IRIS)', count($plan['creates'])],
            ['Update (matched, PRIME values differ)', count($plan['updates'])],
            ['Already up to date', $plan['unchanged']],
            ['Deactivate (no longer in PRIME)', count($plan['deactivations'])],
            ['IRIS-only, removable', count($plan['legacy_removable'])],
            ['IRIS-only, kept (still referenced)', count($plan['legacy_referenced'])],
        ]);

        foreach ($plan['skipped'] as $reason => $count) {
            $this->line("  Skipped {$count} PRIME row(s): {$reason}");
        }

        $fieldCounts = collect($plan['updates'])->flatMap(fn (array $update) => $update['changes'])->countBy()->sortDesc();
        if ($fieldCounts->isNotEmpty()) {
            $this->line('  Fields changing on updates: '.$fieldCounts->map(fn (int $count, string $field) => "{$field} ({$count})")->implode(', '));
        }

        if ($plan['legacy_referenced'] !== []) {
            $this->warn('  IRIS-only personnel still pointed to by equipment or property (reassign before they can be removed):');
            $people = Personnel::query()->whereKey(array_keys($plan['legacy_referenced']))->get(['id', 'employee_id', 'last_name', 'first_name']);
            foreach ($people->take(20) as $person) {
                $this->line("    #{$person->id} {$person->employee_id} {$person->last_name}, {$person->first_name}: {$plan['legacy_referenced'][$person->id]} reference(s)");
            }
            if ($people->count() > 20) {
                $this->line('    … and '.($people->count() - 20).' more.');
            }
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $removeLegacy = (bool) $this->option('remove-legacy');
        $work = count($plan['creates']) + count($plan['updates']) + count($plan['deactivations']) + ($removeLegacy ? count($plan['legacy_removable']) : 0);

        if ($work === 0) {
            $this->info('Nothing to change.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Apply {$work} change(s) to Personnel?")) {
            $this->line('Nothing changed.');

            return self::SUCCESS;
        }

        $counts = $sync->apply($plan, $removeLegacy);

        $this->info("Created {$counts['created']}, updated {$counts['updated']}, deactivated {$counts['deactivated']}, removed {$counts['removed']} IRIS-only record(s); added {$counts['positions_created']} position(s).");

        if (! $removeLegacy && $plan['legacy_removable'] !== []) {
            $this->line(count($plan['legacy_removable']).' IRIS-only record(s) were left in place; rerun with --remove-legacy to remove them.');
        }

        return self::SUCCESS;
    }
}
