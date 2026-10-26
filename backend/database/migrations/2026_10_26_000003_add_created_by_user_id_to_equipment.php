<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->after('current_end_user_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        $creators = DB::table('activity_log')
            ->join('users', 'users.id', '=', 'activity_log.causer_id')
            ->where('activity_log.subject_type', 'App\Models\Equipment')
            ->where('activity_log.event', 'created')
            ->where('activity_log.causer_type', 'App\Models\User')
            ->orderBy('activity_log.id')
            ->get(['activity_log.subject_id', 'activity_log.causer_id'])
            ->unique('subject_id');

        $backfilled = [];

        foreach ($creators as $creator) {
            $updated = DB::table('equipment')
                ->where('id', $creator->subject_id)
                ->whereNull('created_by_user_id')
                ->update(['created_by_user_id' => $creator->causer_id]);

            if ($updated === 1) {
                $backfilled[(int) $creator->causer_id][] = (int) $creator->subject_id;
            }
        }

        if ($backfilled !== []) {
            $this->audit([
                'change' => 'equipment.created_by_user_id backfilled from the earliest created activity by an existing user',
                'equipment_count' => array_sum(array_map('count', $backfilled)),
                'equipment_ids_by_user_id' => $backfilled,
            ]);
        }
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

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('equipment', function (Blueprint $table) {
            $table->dropColumn('created_by_user_id');
        });
    }
};
