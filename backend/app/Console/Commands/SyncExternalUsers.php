<?php

namespace App\Console\Commands;

use App\Services\PrimeUserSync;
use Illuminate\Console\Command;

class SyncExternalUsers extends Command
{
    protected $signature = 'users:sync-external';

    protected $description = 'Import new active PRIME users and sync names and deactivations of existing ones';

    public function handle(PrimeUserSync $sync): int
    {
        ['created' => $created, 'updated' => $updated, 'deactivated' => $deactivated] = $sync->run();

        $this->info("Created {$created}, updated {$updated} (of which {$deactivated} deactivated).");

        return self::SUCCESS;
    }
}
