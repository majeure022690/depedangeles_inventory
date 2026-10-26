<?php

namespace App\Console\Commands;

use App\Services\PrimeUserSync;
use Illuminate\Console\Command;
use Throwable;

class SyncExternalUsers extends Command
{
    protected $signature = 'users:sync-external';

    protected $description = 'Sync offices from PRIME departments, then import active PRIME users and sync names, deactivations, employee numbers and offices';

    public function handle(PrimeUserSync $sync): int
    {
        try {
            $counts = $sync->run();
        } catch (Throwable $e) {
            $this->error('PRIME sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $offices = $counts['office_sync'];

        $this->info("Offices: linked {$offices['linked']}, created {$offices['created']}, renamed {$offices['renamed']}.");
        $this->line("Offices reported, not changed: {$offices['rename_skipped']} rename(s) skipped, {$offices['ambiguous']} ambiguous name(s), "
            ."{$offices['leftovers']} duplicate department(s), {$offices['create_skipped']} department(s) not created, {$offices['gone']} office(s) whose department left PRIME.");
        $this->info("Users: created {$counts['created']}, updated {$counts['updated']} (of which {$counts['deactivated']} deactivated).");
        $this->info("Employee numbers: set {$counts['numbers_set']}, changed {$counts['numbers_changed']}, cleared {$counts['numbers_cleared']}.");
        $this->info("User offices: set {$counts['offices_set']}, changed {$counts['offices_changed']}, cleared {$counts['offices_cleared']}.");
        $this->line('Run prime:sync-preview for the details of anything reported.');

        return self::SUCCESS;
    }
}
