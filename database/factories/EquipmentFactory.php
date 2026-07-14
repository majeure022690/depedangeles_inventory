<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\EquipmentClassification;
use App\Models\EquipmentCondition;
use App\Models\ItemType;
use App\Models\Personnel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Equipment>
 */
class EquipmentFactory extends Factory
{
    protected $model = Equipment::class;

    /**
     * Define the model's default state.
     *
     * TIER 1 FK RESOLUTION (lookup-normalization ADR): item_type_id/
     * brand_id/equipment_category_id/equipment_classification_id/
     * equipment_condition_id are real, required (NOT NULL) foreign keys —
     * see resolveTier1Id() below. Each pulls a random existing row from
     * the corresponding reference table (realistic variety when
     * ReferenceDataSeeder has run) and falls back to creating one minimal
     * row on the fly when it hasn't, so this factory never depends on
     * seeding order. The remaining lookup-backed fields (uom,
     * mode_acquisition, source_acquisition, source_fund, allotment_class,
     * disposition_status, dcp_package) are Tier 2 — still plausible
     * literal strings, matching the real seeded EquipmentLibrary rows
     * where it matters for validation (see IspAccountFactory's doc-comment
     * for the same pattern applied there).
     */
    public function definition(): array
    {
        $cost = fake()->randomFloat(2, 500, 120000);

        return [
            'property_no' => sprintf(
                'AC-%d-IT-%s-%s',
                fake()->numberBetween(2018, 2026),
                fake()->randomElement(['MFP', 'LT', 'DT', 'PR', 'RT', 'SW']),
                fake()->unique()->numerify('######')
            ),
            'old_property_no' => fake()->optional(0.2)->numerify('OLD-######'),
            'serial_number' => fake()->optional(0.9)->bothify('SN-????-########'),
            'item_type_id' => self::resolveTier1Id(ItemType::class, 'Laptop'),
            'uom' => fake()->randomElement(['Unit', 'Piece', 'Set']),
            'brand_id' => self::resolveTier1Id(Brand::class, 'Dell'),
            'model' => fake()->optional(0.9)->bothify('Model-###??'),
            'specifications' => fake()->optional(0.7)->sentence(12),
            'non_dcp' => fake()->boolean(70),
            'dcp_package' => fake()->optional(0.3)->randomElement(['Package 1', 'Package 2', 'Package 3']),
            'dcp_year' => fake()->optional(0.3)->numberBetween(2015, 2024),
            'equipment_category_id' => self::resolveTier1IdByName(
                EquipmentCategory::class,
                $cost >= 50000 ? 'High-value' : 'Low-value'
            ),
            'equipment_classification_id' => self::resolveTier1Id(EquipmentClassification::class, 'ICT Equipment'),
            'gl_sl_code' => fake()->optional(0.6)->numerify('###-##-###'),
            'uacs' => fake()->optional(0.6)->numerify('1-06-05-0##'),
            'acquisition_cost' => $cost,
            'received_date' => fake()->optional(0.9)->dateTimeBetween('-8 years', 'now'),
            'estimated_useful_life' => fake()->optional(0.8)->numberBetween(3, 10),
            'mode_acquisition' => fake()->randomElement(['DepEd Purchase', 'Donation', 'Grant']),
            'source_acquisition' => fake()->randomElement(['Central Office', 'Regional Office', 'School Division Office', 'LGU', 'Private Corporation']),
            'donor' => fake()->optional(0.15)->company(),
            'source_fund' => fake()->randomElement(['General Fund', 'MOOE', 'Capital Outlay', 'SEF', 'Trust Fund']),
            'allotment_class' => fake()->randomElement(['Personal Services', 'MOOE', 'Capital Outlay', 'Not Applicable']),
            'pm_plan' => fake()->optional(0.4)->bothify('PPMP-####-##'),
            'equipment_location' => fake()->optional(0.9)->randomElement(['ICT Unit', 'Records Section', 'Office of the SDS', 'Curriculum Implementation Division']),
            'non_functional' => fake()->boolean(10),
            'equipment_condition_id' => self::resolveTier1Id(EquipmentCondition::class, 'Serviceable'),
            'disposition_status' => fake()->randomElement(['Normal', 'Transferred', 'For Disposal', 'Disposed', 'Donated']),
            'remarks' => fake()->optional(0.3)->sentence(),
            'qr_code' => null,
            'under_warranty' => fake()->boolean(30),
            'end_warranty_date' => fake()->optional(0.3)->dateTimeBetween('now', '+3 years'),
            'supplier' => fake()->optional(0.7)->company(),
            'current_accountable_officer_id' => Personnel::factory(),
            'current_end_user_id' => Personnel::factory(),
        ];
    }

    /**
     * High-value asset (>= PHP 50,000), per the source workbook's COA
     * category threshold.
     */
    public function highValue(): static
    {
        return $this->state(fn (array $attributes) => [
            'acquisition_cost' => fake()->randomFloat(2, 50000, 250000),
            'equipment_category_id' => self::resolveTier1IdByName(EquipmentCategory::class, 'High-value'),
        ]);
    }

    public function unserviceable(): static
    {
        return $this->state(fn (array $attributes) => [
            'equipment_condition_id' => self::resolveTier1IdByName(EquipmentCondition::class, 'Unserviceable'),
            'non_functional' => true,
        ]);
    }

    /**
     * Registered but awaiting Asset Management Office assignment of a
     * property number and/or acquisition cost — a real, ongoing workflow
     * state (not a historical-import artifact), per
     * 2026_07_14_132356_make_property_no_and_acquisition_cost_nullable_on_equipment_table's
     * doc-comment. `property_no` must stay unique when non-null, but here
     * it's null outright, so the default state's unique() sequence call
     * is simply never generated for these rows.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'property_no' => null,
            'acquisition_cost' => null,
        ]);
    }

    /**
     * Resolves a random existing row's id from a Tier 1 reference table
     * (lookup-normalization ADR) for realistic variety when
     * ReferenceDataSeeder has run, falling back to creating one minimal
     * row on the fly when it hasn't — so this factory never depends on
     * seeding order or a specific test's setup sequence. Safe to call
     * repeatedly: once the fallback row exists, subsequent calls find it
     * via the random-order query instead of attempting another insert.
     *
     * @param  class-string  $modelClass
     */
    private static function resolveTier1Id(string $modelClass, string $fallbackName): int
    {
        return $modelClass::query()->inRandomOrder()->value('id')
            ?? $modelClass::query()->create(['name' => $fallbackName, 'is_active' => true])->id;
    }

    /**
     * Same as resolveTier1Id(), but resolves (or creates) a row matching a
     * *specific* name rather than a random one — for states like
     * highValue()/unserviceable() that need a particular category/
     * condition, not just any valid one.
     *
     * @param  class-string  $modelClass
     */
    private static function resolveTier1IdByName(string $modelClass, string $name): int
    {
        return $modelClass::query()->where('name', $name)->value('id')
            ?? $modelClass::query()->create(['name' => $name, 'is_active' => true])->id;
    }
}
