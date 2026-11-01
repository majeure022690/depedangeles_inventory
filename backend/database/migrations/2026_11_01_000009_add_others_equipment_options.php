<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const LIBRARY_TYPES = ['dcp_package', 'source_of_funds'];

    public function up(): void
    {
        if (! DB::table('equipment_libraries')->exists()) {
            return;
        }

        DB::transaction(function () {
            $now = now();
            $inserted = 0;

            foreach (self::LIBRARY_TYPES as $type) {
                $inserted += DB::table('equipment_libraries')->insertOrIgnore([
                    'type' => $type,
                    'value' => 'Others',
                    'description' => null,
                    'sort_order' => (int) DB::table('equipment_libraries')->where('type', $type)->max('sort_order') + 1,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $inserted += DB::table('equipment_conditions')->insertOrIgnore([
                'name' => 'Others',
                'description' => null,
                'sort_order' => (int) DB::table('equipment_conditions')->max('sort_order') + 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

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
                    'tables' => ['equipment_libraries' => self::LIBRARY_TYPES, 'equipment_conditions' => ['Others']],
                    'inserted_count' => $inserted,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        DB::table('equipment_libraries')->whereIn('type', self::LIBRARY_TYPES)->where('value', 'Others')->delete();
        DB::table('equipment_conditions')->where('name', 'Others')->delete();
    }
};
