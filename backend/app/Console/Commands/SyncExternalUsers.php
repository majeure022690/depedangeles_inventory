<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncExternalUsers extends Command
{
    protected $signature = 'users:sync-external';
    protected $description = 'Import all users from external database to local database';

    public function handle(): int
    {
        $this->info('Syncing users from external database...');

        $externalUsers = DB::connection('external_users')
            ->table('users')  // Change to your table name
            ->get();

        $synced = 0;
        $skipped = 0;

        foreach ($externalUsers as $externalUser) {
            // Skip if user already exists locally
            if (User::where('email', $externalUser->email)->exists()) {
                $skipped++;
                continue;
            }

            User::create([
                'name' => $externalUser->name ?? $externalUser->username,
                'email' => $externalUser->email,
                'password' => $externalUser->password,
                'is_active' => true,
                'office_id' => null, // Admin assigns later
            ]);

            $synced++;
        }

        $this->info("Synced {$synced} new users.");
        $this->info("Skipped {$skipped} existing users.");

        return 0;
    }
}
