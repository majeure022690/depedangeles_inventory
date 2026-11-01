<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PsfSchoolDataImport;
use Illuminate\Console\Command;
use Throwable;

class ImportPsfSchoolData extends Command
{
    protected $signature = 'school-data:import-psf
        {folder : The "PSF Inventory - Schools" folder}
        {--dry-run : Read and report every school without writing anything}
        {--school=* : Only these school IDs}
        {--assign=* : "file name=school ID" for a file whose own school ID is wrong}
        {--user= : Email of the IRIS user recorded as the importer (optional; otherwise a system import)}
        {--force : Skip the confirmation prompt}';

    protected $description = 'One-time import of the Stakeholder Profile, Internet Connectivity and ISP sheets of the PSF workbooks, matched to PRIME-linked offices';

    public function handle(PsfSchoolDataImport $import): int
    {
        $folder = (string) $this->argument('folder');
        if (! is_dir($folder)) {
            $this->error("Folder not found: {$folder}");

            return self::FAILURE;
        }

        $importer = null;
        if (filled($this->option('user'))) {
            $importer = User::query()->where('email', (string) $this->option('user'))->first();
            if ($importer === null) {
                $this->error('No IRIS user has the email '.$this->option('user').'. Leave out --user to record the import as a system import.');

                return self::FAILURE;
            }
        }

        $this->info('Reading workbooks…');
        try {
            $plans = $import->plan($folder, $this->assignments());
        } catch (Throwable $e) {
            $this->error('Could not plan the import: '.$e->getMessage());

            return self::FAILURE;
        }

        $only = array_map('strval', (array) $this->option('school'));
        if ($only !== []) {
            $plans = array_values(array_filter($plans, fn (array $plan) => in_array($plan['school_id'], $only, true)));
        }

        $this->table(['School ID', 'Office', 'Profile fields', 'Survey fields', 'ISP accounts', 'Speed tests', 'Costs', 'Notes', 'Status'], array_map(fn (array $plan) => [
            $plan['school_id'],
            $plan['office']?->office_name ?? '-',
            count($plan['profile']),
            count($plan['survey']),
            count($plan['accounts']),
            count($plan['speed_tests']),
            count($plan['costs']),
            count($plan['notes']),
            $plan['problem'] ?? ($plan['already_imported'] ? 'Already imported, skipped' : 'Ready'),
        ], $plans));

        foreach ($plans as $plan) {
            foreach (array_unique($plan['notes']) as $note) {
                $this->line("  {$plan['school_id']}: {$note}");
            }
        }

        $ready = array_values(array_filter($plans, fn (array $plan) => $plan['problem'] === null && ! $plan['already_imported']));
        $this->line(count($ready).' school(s) ready.');

        if ($this->option('dry-run') || $ready === []) {
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Import these schools now? This is meant to run once.')) {
            return self::SUCCESS;
        }

        foreach ($ready as $plan) {
            try {
                $counts = $import->import($plan, $importer);
                $this->info(sprintf('  %s %s: %d profile and %d survey field(s), %d ISP account(s), %d speed test(s), %d cost(s) imported',
                    $plan['school_id'], $plan['office']->office_name, $counts['profile'], $counts['survey'], $counts['accounts'], $counts['speed_tests'], $counts['costs']));
            } catch (Throwable $e) {
                $this->error("  {$plan['school_id']} {$plan['office']->office_name}: nothing imported ({$e->getMessage()})");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function assignments(): array
    {
        $assign = [];
        foreach ((array) $this->option('assign') as $pair) {
            [$file, $schoolId] = array_pad(explode('=', (string) $pair, 2), 2, '');
            if (trim($file) !== '' && preg_match('/^\d{6}$/', trim($schoolId)) === 1) {
                $assign[trim($file)] = trim($schoolId);
            }
        }

        return $assign;
    }
}
