<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TYPE = 'used_by';

    private const VALUES = ['Learner', 'Teacher', 'Teaching-Related', 'Non-Teaching'];

    public function up(): void
    {
        if (! DB::table('equipment_libraries')->exists()) {
            return;
        }

        DB::transaction(function () {
            $now = now();

            $inserted = DB::table('equipment_libraries')->insertOrIgnore(array_map(fn (string $value, int $sortOrder) => [
                'type' => self::TYPE,
                'value' => $value,
                'description' => null,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], self::VALUES, array_keys(self::VALUES)));

            if ($inserted === 0) {
                return;
            }

            $migration = pathinfo(__FILE__, PATHINFO_FILENAME);

            DB::table('activity_log')->insert([
                'log_name' => 'default',
                'description' => $migration,
                'event' => 'system.reference_data_seeded',
                'properties' => json_encode([
                    'migration' => $migration,
                    'table' => 'equipment_libraries',
                    'type' => self::TYPE,
                    'values' => self::VALUES,
                    'inserted_count' => $inserted,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        DB::table('equipment_libraries')->where('type', self::TYPE)->delete();
    }
};
