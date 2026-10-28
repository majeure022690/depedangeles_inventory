<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DEFAULTED_COLUMNS = [
        'gl_sl_code' => 'default_gl_sl_code',
        'uacs' => 'default_uacs',
        'estimated_useful_life' => 'default_useful_life',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $filled = [];
            $classifications = DB::table('equipment_classifications')->get(['id', ...array_values(self::DEFAULTED_COLUMNS)]);

            foreach (self::DEFAULTED_COLUMNS as $column => $defaultColumn) {
                foreach ($classifications as $classification) {
                    if ($classification->{$defaultColumn} === null) {
                        continue;
                    }

                    $query = DB::table('equipment')
                        ->where('equipment_classification_id', $classification->id)
                        ->whereNull($column);

                    $ids = $query->clone()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

                    if ($ids === []) {
                        continue;
                    }

                    $query->update([$column => $classification->{$defaultColumn}]);

                    $filled[$column] = [...($filled[$column] ?? []), ...$ids];
                }
            }

            if ($filled === []) {
                return;
            }

            $migration = pathinfo(__FILE__, PATHINFO_FILENAME);

            DB::table('activity_log')->insert([
                'log_name' => 'default',
                'description' => $migration,
                'event' => 'system.equipment_classification_defaults_filled',
                'properties' => json_encode([
                    'migration' => $migration,
                    'classification_defaults' => array_map(fn (array $ids) => [
                        'filled_count' => count($ids),
                        'ids' => $ids,
                    ], $filled),
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void {}
};
