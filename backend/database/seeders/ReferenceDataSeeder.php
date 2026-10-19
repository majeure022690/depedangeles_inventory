<?php

namespace Database\Seeders;

use App\Enums\ConnectivityLibraryType;
use App\Enums\EquipmentLibraryType;
use App\Enums\PersonnelLibraryType;
use App\Enums\StakeholderLibraryType;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Seeds the 13 Tier 1/Tier 2 reference-data tables introduced by the
 * lookup-normalization ADR (docs/architecture-decisions/
 * lookup-normalization.md, Step 1) from the same 677 rows that used to
 * feed the single `lookups` table — just redistributed into 13 new
 * per-table JSON files under database/seeders/data/ (split once from the
 * original lookups.json by database/seeders/data's provenance script; no
 * re-extraction from the source Excel workbook was needed).
 *
 * The old `lookups` table/LookupSeeder/App\Models\Lookup/App\Enums\LookupType
 * this replaced were dropped/deleted outright in the ADR's Step 4 cleanup,
 * once every consuming resource (Equipment, Personnel, IspAccount,
 * StakeholderProfile, InternetConnectivitySurvey) was confirmed cut over.
 *
 * Same "reset button" lesson the old LookupSeeder learned the hard way:
 * insertOrIgnore, never upsert. Tier 1 tables are unique on `name`; Tier 2
 * tables are unique on (`type`, `value`) — either way, a row that already
 * exists is left completely untouched, so a re-run (migrate:fresh --seed,
 * a redeploy, a stray db:seed) can never revert a future admin edit made
 * through the reference-data admin screen back to this seed data. Only
 * genuinely new rows get inserted.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        // Tier 1 — dedicated entity tables, unique on `name`.
        $this->seedTier1('item_types', ItemType::class);
        $this->seedTier1('brands', Brand::class);
        $this->seedTier1('equipment_categories', EquipmentCategory::class);
        $this->seedTier1('equipment_classifications', EquipmentClassification::class);
        $this->seedTier1('equipment_conditions', EquipmentCondition::class);
        $this->seedTier1('positions', Position::class);
        $this->seedTier1('ro_offices', RoOffice::class);
        $this->seedTier1('sdo_offices', SdoOffice::class);
        $this->seedTier1('isp_providers', IspProvider::class);

        // Tier 2 — domain-grouped library tables, unique on (type, value).
        $this->seedTier2('equipment_libraries', EquipmentLibrary::class, EquipmentLibraryType::class);
        $this->seedTier2('personnel_libraries', PersonnelLibrary::class, PersonnelLibraryType::class);
        $this->seedTier2('stakeholder_libraries', StakeholderLibrary::class, StakeholderLibraryType::class);
        $this->seedTier2('connectivity_libraries', ConnectivityLibrary::class, ConnectivityLibraryType::class);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function seedTier1(string $jsonFile, string $modelClass): void
    {
        /** @var array<int, array{name: string, description: ?string, sort_order: int}> $rows */
        $rows = $this->readJson($jsonFile);

        $now = now();

        $records = array_map(static fn (array $row): array => [
            'name' => $row['name'],
            'description' => $row['description'] ?? null,
            'sort_order' => $row['sort_order'],
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        $this->insertIgnoringExisting($modelClass, $jsonFile, $records);
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  class-string<\BackedEnum>  $enumClass
     */
    private function seedTier2(string $jsonFile, string $modelClass, string $enumClass): void
    {
        /** @var array<int, array{type: string, value: string, description: ?string, sort_order: int}> $rows */
        $rows = $this->readJson($jsonFile);

        $now = now();

        $records = array_map(function (array $row) use ($enumClass, $now): array {
            // Validate against the backed enum — throws \ValueError on an
            // unknown/typo'd type, exactly like LookupSeeder does today.
            $type = $enumClass::from($row['type']);

            return [
                'type' => $type->value,
                'value' => $row['value'],
                'description' => $row['description'] ?? null,
                'sort_order' => $row['sort_order'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $rows);

        $this->insertIgnoringExisting($modelClass, $jsonFile, $records);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readJson(string $jsonFile): array
    {
        $path = database_path("seeders/data/{$jsonFile}.json");

        if (! File::exists($path)) {
            throw new RuntimeException("Reference data seed file not found at [{$path}].");
        }

        return json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<int, array<string, mixed>>  $records
     */
    private function insertIgnoringExisting(string $modelClass, string $jsonFile, array $records): void
    {
        $inserted = 0;

        foreach (array_chunk($records, 200) as $chunk) {
            $inserted += $modelClass::insertOrIgnore($chunk);
        }

        $this->command?->info(sprintf(
            '%s: %d row(s) inserted, %d already present and left untouched (of %d total).',
            $jsonFile,
            $inserted,
            count($records) - $inserted,
            count($records)
        ));
    }
}
