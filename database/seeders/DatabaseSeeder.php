<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $this->call(RolePermissionSeeder::class);

        $testUser = User::firstOrCreate(
            ['email' => 'test@example.com'],
            User::factory()->raw(['name' => 'Test User']),
        );
        $testUser->assignRole('admin');

        // The 13 Tier 1/Tier 2 reference tables from the lookup-
        // normalization ADR. Supersedes the old LookupSeeder/`lookups`
        // table, dropped in the ADR's Step 4 cleanup.
        $this->call(ReferenceDataSeeder::class);

        // Every school and division-level office/unit, imported from the
        // division's existing "offices" table.
        $this->call(OfficeSeeder::class);

        // Region III PSGC reference data (provinces -> municipalities ->
        // barangays), imported from the division's legacy PSGC dump.
        // Order matters: each seeder resolves its parent's id from the
        // one seeded immediately before it.
        $this->call(PsgcProvinceSeeder::class);
        $this->call(PsgcMunicipalitySeeder::class);
        $this->call(PsgcBarangaySeeder::class);

        // Division personnel roster, imported from the source Excel
        // workbook. Depends on ReferenceDataSeeder above — resolves
        // position_id/sdo_office_id against the positions/sdo_offices
        // tables it seeds.
        $this->call(PersonnelSeeder::class);

        // Division asset register (equipment + paired Beginning Inventory
        // equipment_transactions rows), imported from the source Excel
        // workbook. Depends on ReferenceDataSeeder above (item_types/
        // brands/equipment_categories/equipment_classifications/
        // equipment_conditions/equipment_libraries) and PersonnelSeeder
        // immediately above (resolves accountable_officer_id against the
        // personnel table it seeds).
        $this->call(EquipmentSeeder::class);
    }
}
