<?php

namespace Tests\Feature\Migrations;

use App\Models\Equipment;
use App\Models\EquipmentCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CorrectEquipmentCategoryAndGlSlCodeMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const EVENT = 'system.equipment_category_gl_corrected';

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_29_000004_correct_equipment_category_and_gl_sl_code.php');
    }

    private function seedRow(?string $cost, string $category, ?string $glSlCode): Equipment
    {
        $equipment = Equipment::factory()->create();

        DB::table('equipment')->where('id', $equipment->id)->update([
            'acquisition_cost' => $cost,
            'equipment_category_id' => EquipmentCategory::query()->where('name', $category)->value('id'),
            'gl_sl_code' => $glSlCode,
            'uacs' => '1-06-05-030',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        return $equipment;
    }

    private function categoryOf(Equipment $equipment): string
    {
        return EquipmentCategory::query()->whereKey(DB::table('equipment')->where('id', $equipment->id)->value('equipment_category_id'))->value('name');
    }

    public function test_corrects_category_and_gl_sl_code_from_cost_and_audits_each_changed_row(): void
    {
        EquipmentCategory::query()->firstOrCreate(['name' => 'High-value'], ['sort_order' => 0, 'is_active' => true]);
        EquipmentCategory::query()->firstOrCreate(['name' => 'Low-value'], ['sort_order' => 1, 'is_active' => true]);

        $flipped = $this->seedRow('50000.00', 'Low-value', '10605030');
        $glOnly = $this->seedRow('49999.99', 'Low-value', '10605030');
        $correct = $this->seedRow('75000.00', 'High-value', '10605030');
        $unknownCost = $this->seedRow(null, 'Low-value', '10605030');
        $trashed = $this->seedRow('120000.00', 'Low-value', null);
        DB::table('equipment')->where('id', $trashed->id)->update(['deleted_at' => now()]);

        $this->migration()->up();

        $this->assertSame('High-value', $this->categoryOf($flipped));
        $this->assertSame('10605030', DB::table('equipment')->where('id', $flipped->id)->value('gl_sl_code'));
        $this->assertSame('Low-value', $this->categoryOf($glOnly));
        $this->assertSame('10405030', DB::table('equipment')->where('id', $glOnly->id)->value('gl_sl_code'));
        $this->assertSame('High-value', $this->categoryOf($trashed));
        $this->assertSame('10605030', DB::table('equipment')->where('id', $trashed->id)->value('gl_sl_code'));
        $this->assertSame('Low-value', $this->categoryOf($unknownCost));
        $this->assertSame('10605030', DB::table('equipment')->where('id', $unknownCost->id)->value('gl_sl_code'));

        foreach ([$flipped, $glOnly, $correct, $unknownCost, $trashed] as $equipment) {
            $row = DB::table('equipment')->where('id', $equipment->id)->first(['uacs', 'updated_at']);
            $this->assertSame('1-06-05-030', $row->uacs);
            $this->assertSame('2026-01-01 00:00:00', (string) $row->updated_at);
        }

        $activities = DB::table('activity_log')->where('event', self::EVENT)->orderBy('subject_id')->get();
        $this->assertEqualsCanonicalizing([$flipped->id, $glOnly->id, $trashed->id], $activities->pluck('subject_id')->map(fn ($id) => (int) $id)->all());
        $this->assertTrue($activities->every(fn ($activity) => $activity->subject_type === Equipment::class && $activity->causer_id === null));

        $flippedActivity = $activities->firstWhere('subject_id', $flipped->id);
        $properties = json_decode($flippedActivity->properties, true);
        $this->assertSame(['equipment_category' => 'Low-value', 'gl_sl_code' => '10605030'], $properties['from']);
        $this->assertSame(['equipment_category' => 'High-value', 'gl_sl_code' => '10605030'], $properties['to']);
        $this->assertSame('50000.00', $properties['acquisition_cost']);

        $this->migration()->up();

        $this->assertSame(3, DB::table('activity_log')->where('event', self::EVENT)->count());
    }

    public function test_an_empty_table_is_a_no_op(): void
    {
        EquipmentCategory::query()->firstOrCreate(['name' => 'High-value'], ['sort_order' => 0, 'is_active' => true]);
        EquipmentCategory::query()->firstOrCreate(['name' => 'Low-value'], ['sort_order' => 1, 'is_active' => true]);

        $this->migration()->up();

        $this->assertSame(0, DB::table('activity_log')->where('event', self::EVENT)->count());
    }
}
