<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use App\Models\User;
use App\Services\EquipmentAccountabilityService;
use App\Services\EquipmentPropertyNumberService;
use App\Services\PsfInventoryImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportPsfInventory extends Command
{
    protected $signature = 'equipment:import-psf
        {folder : The "PSF Inventory - Schools" folder}
        {--dry-run : Map and report every row without writing anything}
        {--school=* : Only these school IDs}
        {--assign=* : "file name=school ID" for a file whose own school ID is wrong}
        {--head=* : "school ID=employee no." of who receives officer-less rows at a school with no head on record}
        {--user= : Email of the IRIS user recorded as the importer (optional; otherwise a system import)}
        {--force : Skip the confirmation prompt}';

    protected $description = 'One-time import of school equipment from the PSF inventory workbooks, matched to PRIME-linked offices';

    public function handle(PsfInventoryImport $import, EquipmentPropertyNumberService $numbers, EquipmentAccountabilityService $accountability): int
    {
        $folder = (string) $this->argument('folder');
        if (! is_dir($folder)) {
            $this->error("Folder not found: {$folder}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
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
            $plans = $import->plan($folder, $this->assignments(), $this->heads());
        } catch (Throwable $e) {
            $this->error('Could not plan the import: '.$e->getMessage());

            return self::FAILURE;
        }

        $only = array_map('strval', (array) $this->option('school'));
        if ($only !== []) {
            $plans = array_values(array_filter($plans, fn (array $plan) => in_array($plan['school_id'], $only, true)));
        }

        $this->table(['School ID', 'Office', 'File', 'Rows', 'Head fallback', 'Unassigned', 'Status'], array_map(fn (array $plan) => [
            $plan['school_id'],
            $plan['office']?->office_name ?? '-',
            mb_strimwidth(basename($plan['file']), 0, 50, '…'),
            count($plan['rows']),
            $this->countNotes($plan, 'assigned to the school head'),
            $this->countNotes($plan, 'left unassigned'),
            $plan['problem'] ?? ($plan['already_imported'] ? 'Already imported, skipped' : 'Ready'),
        ], $plans));

        $ready = array_values(array_filter($plans, fn (array $plan) => $plan['problem'] === null && ! $plan['already_imported'] && $plan['rows'] !== []));
        $duplicates = $this->duplicateNumbers($ready);
        $report = $this->writeReport($plans, $duplicates);

        $this->line(sprintf('%d school(s) ready with %d row(s); %d old property number(s) repeat across %d row(s).',
            count($ready), array_sum(array_map(fn (array $plan) => count($plan['rows']), $ready)), count($duplicates), array_sum($duplicates)));
        $this->line("Row-by-row report: {$report}");

        if ($dryRun || $ready === []) {
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Import these schools now? This is meant to run once.')) {
            return self::SUCCESS;
        }

        foreach ($ready as $plan) {
            try {
                $count = $import->import($plan, $importer, $numbers, $accountability);
                $this->info("  {$plan['school_id']} {$plan['office']->office_name}: {$count} imported");
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

    /**
     * @return array<string, string>
     */
    private function heads(): array
    {
        $heads = [];
        foreach ((array) $this->option('head') as $pair) {
            [$schoolId, $employeeNo] = array_pad(explode('=', (string) $pair, 2), 2, '');
            if (preg_match('/^\d{6}$/', trim($schoolId)) === 1 && trim($employeeNo) !== '') {
                $heads[trim($schoolId)] = trim($employeeNo);
            }
        }

        return $heads;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function countNotes(array $plan, string $needle): int
    {
        return count(array_filter($plan['rows'], fn (array $row) => collect($row['notes'])->contains(fn (string $note) => str_contains($note, $needle))));
    }

    /**
     * Old property numbers used by more than one row, counting equipment already in IRIS.
     *
     * @param  list<array<string, mixed>>  $plans
     * @return array<string, int>
     */
    private function duplicateNumbers(array $plans): array
    {
        $counts = [];
        foreach ($plans as $plan) {
            foreach ($plan['rows'] as $row) {
                if (($number = $row['equipment']['old_property_no']) !== null) {
                    $counts[mb_strtolower($number)] = ($counts[mb_strtolower($number)] ?? 0) + 1;
                }
            }
        }

        $existing = Equipment::query()->whereNotNull('old_property_no')->pluck('old_property_no')->countBy(fn (string $number) => mb_strtolower($number));
        foreach ($existing as $number => $count) {
            if (isset($counts[$number])) {
                $counts[$number] += $count;
            }
        }

        return array_filter($counts, fn (int $count) => $count > 1);
    }

    /**
     * @param  list<array<string, mixed>>  $plans
     * @param  array<string, int>  $duplicates
     */
    private function writeReport(array $plans, array $duplicates): string
    {
        $path = 'psf-import/report-'.now()->format('Ymd-His').'.csv';
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['School ID', 'Office', 'File', 'Row', 'Old property no.', 'Duplicate', 'Item', 'Accountable officer', 'Notes', 'School status']);

        foreach ($plans as $plan) {
            $status = $plan['problem'] ?? ($plan['already_imported'] ? 'Already imported' : 'Ready');
            if ($plan['rows'] === []) {
                fputcsv($handle, [$plan['school_id'], $plan['office']?->office_name, basename($plan['file']), '', '', '', '', '', '', $status]);
            }
            foreach ($plan['rows'] as $row) {
                $number = $row['equipment']['old_property_no'];
                fputcsv($handle, [
                    $plan['school_id'], $plan['office']?->office_name, basename($plan['file']), $row['row'], $number,
                    $number !== null && isset($duplicates[mb_strtolower($number)]) ? 'Yes' : '',
                    $row['item'], $row['officer'], implode('; ', $row['notes']), $status,
                ]);
            }
        }

        rewind($handle);
        Storage::disk('local')->put($path, (string) stream_get_contents($handle));
        fclose($handle);

        return Storage::disk('local')->path($path);
    }
}
