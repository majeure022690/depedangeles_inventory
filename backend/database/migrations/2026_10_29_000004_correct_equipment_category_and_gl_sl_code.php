<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const THRESHOLD = '50000.00';

    private const RULES = [
        'High-value' => '10605030',
        'Low-value' => '10405030',
    ];

    public function up(): void
    {
        $categoryIds = DB::table('equipment_categories')->whereIn('name', array_keys(self::RULES))->pluck('id', 'name');

        if ($categoryIds->count() !== count(self::RULES)) {
            return;
        }

        DB::transaction(function () use ($categoryIds) {
            $migration = pathinfo(__FILE__, PATHINFO_FILENAME);
            $now = now();

            foreach (self::RULES as $categoryName => $glSlCode) {
                $categoryId = (int) $categoryIds[$categoryName];

                $rows = DB::table('equipment')
                    ->leftJoin('equipment_categories', 'equipment_categories.id', '=', 'equipment.equipment_category_id')
                    ->whereNotNull('equipment.acquisition_cost')
                    ->where('equipment.acquisition_cost', $categoryName === 'High-value' ? '>=' : '<', self::THRESHOLD)
                    ->where(fn (Builder $query) => $query
                        ->whereNull('equipment.equipment_category_id')
                        ->orWhere('equipment.equipment_category_id', '!=', $categoryId)
                        ->orWhereNull('equipment.gl_sl_code')
                        ->orWhere('equipment.gl_sl_code', '!=', $glSlCode))
                    ->orderBy('equipment.id')
                    ->get([
                        'equipment.id',
                        'equipment.acquisition_cost',
                        'equipment.gl_sl_code',
                        'equipment_categories.name as category_name',
                    ]);

                if ($rows->isEmpty()) {
                    continue;
                }

                DB::table('equipment')
                    ->whereIn('id', $rows->pluck('id'))
                    ->update(['equipment_category_id' => $categoryId, 'gl_sl_code' => $glSlCode]);

                $rows->chunk(200)->each(fn ($chunk) => DB::table('activity_log')->insert($chunk->map(function ($row) use ($categoryName, $glSlCode, $migration, $now) {
                    $old = ['equipment_category' => $row->category_name, 'gl_sl_code' => $row->gl_sl_code];
                    $new = ['equipment_category' => $categoryName, 'gl_sl_code' => $glSlCode];

                    return [
                        'log_name' => 'default',
                        'description' => $migration,
                        'subject_type' => 'App\Models\Equipment',
                        'subject_id' => (int) $row->id,
                        'event' => 'system.equipment_category_gl_corrected',
                        'attribute_changes' => json_encode(['old' => $old, 'attributes' => $new]),
                        'properties' => json_encode([
                            'migration' => $migration,
                            'acquisition_cost' => number_format((float) $row->acquisition_cost, 2, '.', ''),
                            'from' => $old,
                            'to' => $new,
                        ]),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                })->values()->all()));
            }
        });
    }

    public function down(): void {}
};
