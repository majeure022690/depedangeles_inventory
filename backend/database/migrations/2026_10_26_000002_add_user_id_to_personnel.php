<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnel', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('office_id')->unique();
        });

        // sqlite rebuilds the table to add a constraint, losing personnel's generated full_name column.
        if (! $this->isSqlite()) {
            Schema::table('personnel', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        $this->backfillFromEmail();
    }

    public function down(): void
    {
        Schema::table('personnel', function (Blueprint $table) {
            if (! $this->isSqlite()) {
                $table->dropForeign(['user_id']);
            }

            $table->dropUnique(['user_id']);
            $table->dropColumn('user_id');
        });
    }

    private function backfillFromEmail(): void
    {
        $users = DB::table('users')
            ->whereNotNull('email')
            ->get(['id', 'email'])
            ->groupBy(fn ($user) => mb_strtolower(trim($user->email)));

        $personnel = DB::table('personnel')
            ->whereNull('deleted_at')
            ->whereNotNull('deped_email')
            ->where('deped_email', '!=', '')
            ->get(['id', 'deped_email'])
            ->groupBy(fn ($row) => mb_strtolower(trim($row->deped_email)));

        $linked = [];
        $ambiguous = [];

        foreach ($personnel as $email => $matches) {
            $userMatches = $users[$email] ?? collect();

            if ($userMatches->isEmpty()) {
                continue;
            }

            if ($matches->count() !== 1 || $userMatches->count() !== 1) {
                $ambiguous[] = [
                    'email' => $email,
                    'personnel_ids' => $matches->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    'user_ids' => $userMatches->pluck('id')->map(fn ($id) => (int) $id)->all(),
                ];

                continue;
            }

            $personnelId = (int) $matches->first()->id;
            $userId = (int) $userMatches->first()->id;

            $updated = DB::table('personnel')
                ->where('id', $personnelId)
                ->whereNull('user_id')
                ->update(['user_id' => $userId]);

            if ($updated === 1) {
                $linked[] = ['personnel_id' => $personnelId, 'user_id' => $userId, 'email' => $email];
            }
        }

        if ($linked === []) {
            return;
        }

        $this->audit([
            'change' => 'personnel.user_id backfilled where exactly one personnel deped_email matches exactly one users.email',
            'linked_count' => count($linked),
            'linked' => $linked,
            'skipped_ambiguous' => $ambiguous,
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function audit(array $properties): void
    {
        $migration = pathinfo(__FILE__, PATHINFO_FILENAME);

        DB::table('activity_log')->insert([
            'log_name' => 'default',
            'description' => $migration,
            'event' => 'system.data_scope_migration',
            'properties' => json_encode(['migration' => $migration, ...$properties]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function isSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }
};
