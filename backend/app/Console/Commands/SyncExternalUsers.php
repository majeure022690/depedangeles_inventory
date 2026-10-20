<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ExternalUserAuthService;
use Illuminate\Console\Command;

class SyncExternalUsers extends Command
{
    protected $signature = 'users:sync-external';

    protected $description = 'Import new active PRIME users and sync names and deactivations of existing ones';

    public function handle(): int
    {
        $created = 0;
        $updated = 0;
        $deactivated = 0;

        ExternalUserAuthService::users()
            ->orderBy('user_id')
            ->each(function (object $primeUser) use (&$created, &$updated, &$deactivated) {
                $active = $primeUser->user_status === ExternalUserAuthService::ACTIVE_STATUS;
                $local = User::where('email', $primeUser->email_address)->first();

                if (! $local) {
                    if ($active) {
                        User::create([
                            'name' => $primeUser->full_name,
                            'email' => $primeUser->email_address,
                            'password' => $primeUser->password,
                            'is_active' => true,
                        ]);
                        $created++;
                    }

                    return;
                }

                $local->name = $primeUser->full_name;

                if (! $active && $local->is_active) {
                    $local->is_active = false;
                    $deactivated++;
                }

                if ($local->isDirty()) {
                    $local->save();
                    $updated++;
                }
            });

        $this->info("Created {$created}, updated {$updated} (of which {$deactivated} deactivated).");

        return self::SUCCESS;
    }
}
