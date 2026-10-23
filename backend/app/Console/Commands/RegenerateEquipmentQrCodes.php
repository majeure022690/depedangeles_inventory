<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use Illuminate\Console\Command;

class RegenerateEquipmentQrCodes extends Command
{
    protected $signature = 'equipment:regenerate-qr-codes {--force : Rewrite mismatched rows instead of only listing them}';

    protected $description = 'List (or with --force, rewrite) equipment qr_code values that do not point at APP_URL';

    public function handle(): int
    {
        $filled = 0;
        $replaced = 0;

        Equipment::withTrashed()->chunkById(500, function ($chunk) use (&$filled, &$replaced): void {
            foreach ($chunk as $equipment) {
                $expected = $equipment->qrCodeUrl();

                if ($equipment->qr_code === $expected) {
                    continue;
                }

                if (($equipment->qr_code ?? '') === '') {
                    $filled++;
                } else {
                    $replaced++;
                }

                $this->line("#{$equipment->id} {$equipment->property_no}: ".($equipment->qr_code ?? '(empty)')." -> {$expected}");

                if (! $this->option('force')) {
                    continue;
                }

                $old = $equipment->qr_code;
                $equipment->forceFill(['qr_code' => $expected])->saveQuietly();

                activity()
                    ->performedOn($equipment)
                    ->event('equipment.qr_code.regenerated')
                    ->withProperties(['old' => ['qr_code' => $old], 'attributes' => ['qr_code' => $expected]])
                    ->log('equipment.qr_code.regenerated');
            }
        });

        if ($filled + $replaced === 0) {
            $this->info('Every qr_code already points at '.config('app.url').'.');
        } elseif ($this->option('force')) {
            if ($filled > 0) {
                $this->info("Filled {$filled} missing qr_code value(s).");
            }
            if ($replaced > 0) {
                $this->warn("Rewrote {$replaced} qr_code value(s) that pointed at a different URL. Reprint those {$replaced} label(s): the old printed codes still encode the old URL.");
            }
        } else {
            $this->warn("{$filled} qr_code value(s) missing, {$replaced} pointing at a different URL. Re-run with --force to rewrite them.");
        }

        return self::SUCCESS;
    }
}
