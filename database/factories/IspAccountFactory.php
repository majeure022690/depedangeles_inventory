<?php

namespace Database\Factories;

use App\Models\IspAccount;
use App\Models\IspProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IspAccount>
 */
class IspAccountFactory extends Factory
{
    protected $model = IspAccount::class;

    /**
     * Lookup-backed Tier 2 string columns (school_area_coverage,
     * subscription_type, isp_connection_type, purpose_of_subscription,
     * mode_of_acquisition, source_of_acquisition, fund_source,
     * rate_connectivity_admin_areas, rate_connectivity_classroom,
     * overall_signal_quality) use plausible literal strings rather than
     * drawing from a reference table — same reasoning EquipmentFactory/
     * PersonnelFactory use for their own Tier 2 string columns. The
     * literal values chosen also happen to match the real seeded
     * ConnectivityLibrary/EquipmentLibrary rows exactly (see
     * database/seeders/data/connectivity_libraries.json and
     * equipment_libraries.json), so plain `IspAccount::factory()->create()`
     * calls produce values that would also pass IspAccountStoreRequest's
     * validation if ever posted through the HTTP layer, without this
     * factory needing to query those tables directly.
     *
     * The Tier 1 FK column (isp_provider_id, lookup-normalization ADR
     * Step 2+3) is a real, required (NOT NULL as of the ADR's Step 4
     * cleanup) foreign key — resolved via a random existing IspProvider
     * row when ReferenceDataSeeder has run, falling back to creating one
     * minimal row on the fly when it hasn't, mirroring
     * EquipmentFactory::resolveTier1Id(). Tests that need a *specific*
     * provider (e.g. 'PLDT') override it explicitly with an id from a
     * seeded ReferenceDataSeeder run (see
     * EquipmentControllerTest::makeEquipment() for the established
     * pattern, mirrored here by IspAccountControllerTest::makeIspAccount()).
     */
    public function definition(): array
    {
        $minSpeed = fake()->randomFloat(2, 5, 50);
        $modeOfAcquisition = fake()->randomElement(['DepEd Purchase', 'Donation', 'Grant']);

        return [
            'isp_provider_id' => IspProvider::query()->inRandomOrder()->value('id')
                ?? IspProvider::query()->create(['name' => 'PLDT', 'is_active' => true])->id,
            'cost_per_month' => fake()->randomFloat(2, 1500, 15000),
            'isp_billing_account_no' => fake()->bothify('BA-####-??????'),
            'package_purchased_inclusion' => fake()->sentence(10),

            'school_area_coverage' => fake()->randomElement(['ES', 'JHS', 'SHS', 'All (Areawide)']),
            'min_speed' => $minSpeed,
            'max_speed' => $minSpeed + fake()->randomFloat(2, 5, 100),

            'subscription_type' => fake()->randomElement(['Prepaid', 'Postpaid']),
            'isp_connection_type' => fake()->randomElement(['Fiber', 'DSL', 'Cable', 'Satellite']),

            'contract_start_date' => fake()->optional(0.85)->dateTimeBetween('-3 years', 'now'),
            'contract_end_date' => fake()->optional(0.85)->dateTimeBetween('now', '+2 years'),
            'inactive_contract' => fake()->boolean(10),

            'purpose_of_subscription' => fake()->randomElement(['For administrative use', 'For classroom instruction use']),

            'mode_of_acquisition' => $modeOfAcquisition,
            'source_of_acquisition' => fake()->randomElement(['Central Office', 'Regional Office', 'School Division Office']),
            'donor' => $modeOfAcquisition === 'Donation' ? fake()->company() : null,
            'fund_source' => fake()->randomElement(['General Fund (GF)', 'Maintenance and Other Operating Expenses (MOOE)']),

            'number_access_points_linked' => fake()->optional(0.6)->numberBetween(1, 20),
            'locations_access_points' => fake()->optional(0.5)->sentence(8),

            'number_admin_area_covered' => fake()->optional(0.5)->numberBetween(1, 10),
            'rate_connectivity_admin_areas' => fake()->optional(0.5)->randomElement(['Strong', 'Stable', 'Weak', 'Intermittent', 'Poor']),
            'number_classrooms_covered' => fake()->optional(0.6)->numberBetween(1, 40),
            'rate_connectivity_classroom' => fake()->optional(0.6)->randomElement(['Strong', 'Stable', 'Weak', 'Intermittent', 'Poor']),

            'overall_signal_quality' => fake()->randomElement(['Strong', 'Stable', 'Weak', 'Intermittent', 'Poor']),
            'rate_isp_service' => fake()->optional(0.7)->numberBetween(1, 5),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'inactive_contract' => true,
            'contract_end_date' => fake()->dateTimeBetween('-1 year', '-1 day'),
        ]);
    }
}
