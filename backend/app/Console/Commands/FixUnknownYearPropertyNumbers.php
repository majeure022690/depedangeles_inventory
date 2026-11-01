<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use App\Services\EquipmentPropertyNumberService;
use Illuminate\Console\Command;

class FixUnknownYearPropertyNumbers extends Command
{
    protected $signature = 'equipment:fix-unknown-year
        {--dry-run : Count what would be renumbered without changing anything}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Give property numbers issued as AC-0000-… their year, from the acquisition date or else the DCP year';

    public function handle(EquipmentPropertyNumberService $numbers): int
    {
        $candidates = Equipment::query()
            ->where('property_no', 'like', EquipmentPropertyNumberService::UNKNOWN_YEAR_PREFIX.'%')
            ->orderBy('id')
            ->get();

        $yearOf = fn (Equipment $equipment): ?int => $equipment->received_date !== null
            ? (int) $equipment->received_date->format('Y')
            : ($equipment->non_dcp ? null : $equipment->dcp_year);

        $known = $candidates->filter(fn (Equipment $equipment) => $yearOf($equipment) !== null);
        $this->table(['', 'Records'], [
            ['Property numbers without a year', $candidates->count()],
            ['Year known (acquisition date or DCP year)', $known->count()],
            ['Still unknown, left as is', $candidates->count() - $known->count()],
        ]);

        if ($this->option('dry-run') || $known->isEmpty()) {
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Renumber these records now?')) {
            return self::SUCCESS;
        }

        $renumbered = $known->filter(fn (Equipment $equipment) => $numbers->renumberUnknownYear($equipment, $yearOf($equipment)))->count();
        $this->info("{$renumbered} record(s) renumbered; bundled desktops and peripherals keep their numbers.");

        return self::SUCCESS;
    }
}
