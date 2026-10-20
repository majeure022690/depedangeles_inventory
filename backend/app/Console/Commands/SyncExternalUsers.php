<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ExternalUserAuthService;
use Illuminate\Console\Command;

class SyncExternalUsers extends Command
{
    protected $signature = 'users:sync-external';

    protected $description = 'Import active PRIME users that do not exist locally yet';

    public function handle(): int
    {
        $synced = 0;
        $skipped = 0;

        ExternalUserAuthService::users()
            ->where('user_status', ExternalUserAuthService::ACTIVE_STATUS)
            ->orderBy('user_id')
            ->each(function (object $primeUser) use (&$synced, &$skipped) {
                if (User::where('email', $primeUser->email_address)->exists()) {
                    $skipped++;

                    return;
                }

                User::create([
                    'name' => $primeUser->full_name,
                    'email' => $primeUser->email_address,
                    'password' => $primeUser->password,
                    'is_active' => true,
                ]);

                $synced++;
            });

        $this->info("Synced {$synced} new users.");
        $this->info("Skipped {$skipped} existing users.");

        return self::SUCCESS;
    }
}
