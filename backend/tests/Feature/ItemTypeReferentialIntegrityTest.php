<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\EquipmentClassification;
use App\Models\EquipmentCondition;
use App\Models\ItemType;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the `restrictOnDelete()` constraint added to `equipment.item_type_id`
 * by the lookup-normalization ADR's Tier 1 FK migration
 * (2026_07_11_010200_add_tier1_fk_columns_to_equipment_table.php): a
 * reference-data row that's still referenced by an Equipment record must
 * never be deletable, at the database level, regardless of what app-layer
 * code does or doesn't check. Retiring a reference value is `is_active =
 * false`, never a delete — see that migration's doc-comment.
 */
class ItemTypeReferentialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_an_item_type_referenced_by_equipment_throws_a_query_exception(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $itemType = ItemType::where('name', 'Laptop')->firstOrFail();

        Equipment::factory()->create([
            'current_accountable_officer_id' => null,
            'current_end_user_id' => null,
            'item_type_id' => $itemType->id,
            'brand_id' => Brand::where('name', 'Dell')->value('id'),
            'equipment_category_id' => EquipmentCategory::where('name', 'High-value')->value('id'),
            'equipment_classification_id' => EquipmentClassification::query()->active()->value('id'),
            'equipment_condition_id' => EquipmentCondition::where('name', 'Serviceable')->value('id'),
        ]);

        $this->expectException(QueryException::class);

        $itemType->delete();
    }
}
