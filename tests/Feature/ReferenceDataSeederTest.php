<?php

namespace Tests\Feature;

use App\Enums\EquipmentLibraryType;
use App\Models\Brand;
use App\Models\ConnectivityLibrary;
use App\Models\EquipmentCategory;
use App\Models\EquipmentClassification;
use App\Models\EquipmentCondition;
use App\Models\EquipmentLibrary;
use App\Models\IspProvider;
use App\Models\ItemType;
use App\Models\PersonnelLibrary;
use App\Models\Position;
use App\Models\RoOffice;
use App\Models\SdoOffice;
use App\Models\StakeholderLibrary;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for ReferenceDataSeeder — the Step 1 successor that
 * redistributes the same 677 rows LookupSeeder seeds into the 13 new
 * Tier 1/Tier 2 tables (lookup-normalization ADR). Mirrors
 * LookupSeederTest's coverage of the exact same "reset button" bug class:
 * insertOrIgnore must never clobber a future admin edit on re-seed.
 */
class ReferenceDataSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<class-string, int>
     */
    private static function expectedTier1Counts(): array
    {
        return [
            ItemType::class => 65,
            Brand::class => 76,
            EquipmentCategory::class => 2,
            EquipmentClassification::class => 5,
            EquipmentCondition::class => 4,
            Position::class => 316,
            RoOffice::class => 13,
            SdoOffice::class => 8,
            IspProvider::class => 10,
        ];
    }

    /**
     * @return array<class-string, int>
     */
    private static function expectedTier2Counts(): array
    {
        return [
            EquipmentLibrary::class => 60,
            PersonnelLibrary::class => 27,
            StakeholderLibrary::class => 49,
            ConnectivityLibrary::class => 42,
        ];
    }

    public function test_fresh_run_inserts_every_row_across_all_13_tables_summing_to_677(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $total = 0;

        foreach ([...self::expectedTier1Counts(), ...self::expectedTier2Counts()] as $modelClass => $expected) {
            $this->assertSame($expected, $modelClass::count(), "{$modelClass} row count mismatch.");
            $total += $expected;
        }

        $this->assertSame(677, $total);
    }

    public function test_school_level_coverage_lands_in_connectivity_libraries_not_stakeholder_libraries(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $this->assertDatabaseHas('connectivity_libraries', [
            'type' => 'school_level_coverage',
        ]);
        $this->assertDatabaseMissing('stakeholder_libraries', [
            'type' => 'school_level_coverage',
        ]);
    }

    public function test_tier1_row_is_seeded_with_expected_shape(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $this->assertDatabaseHas('item_types', [
            'name' => 'Server (System Unit)',
            'is_active' => true,
        ]);
    }

    /**
     * The critical regression test: an admin edit to a reference-data row
     * (Step 2's successor to the lookups.manage screen) must survive a
     * re-seed untouched, for both a Tier 1 and a Tier 2 table.
     */
    public function test_reseeding_does_not_overwrite_an_admin_edited_row(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $itemType = ItemType::where('name', 'Server (System Unit)')->firstOrFail();
        $itemType->update(['is_active' => false, 'description' => 'ADMIN EDITED', 'sort_order' => 999]);

        $library = EquipmentLibrary::where('type', EquipmentLibraryType::UnitOfMeasure->value)->firstOrFail();
        $library->update(['is_active' => false, 'description' => 'ADMIN EDITED', 'sort_order' => 999]);

        $itemTypeCountBefore = ItemType::count();
        $libraryCountBefore = EquipmentLibrary::count();

        $this->seed(ReferenceDataSeeder::class);

        $itemType->refresh();
        $library->refresh();

        $this->assertFalse($itemType->is_active);
        $this->assertSame('ADMIN EDITED', $itemType->description);
        $this->assertSame(999, $itemType->sort_order);
        $this->assertSame($itemTypeCountBefore, ItemType::count());

        $this->assertFalse($library->is_active);
        $this->assertSame('ADMIN EDITED', $library->description);
        $this->assertSame(999, $library->sort_order);
        $this->assertSame($libraryCountBefore, EquipmentLibrary::count());
    }

    public function test_reseeding_inserts_rows_that_do_not_exist_yet(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        Brand::where('name', 'Dell')->delete();
        $this->assertSame(75, Brand::count());

        $this->seed(ReferenceDataSeeder::class);

        $this->assertDatabaseHas('brands', ['name' => 'Dell']);
        $this->assertSame(76, Brand::count());
    }
}
