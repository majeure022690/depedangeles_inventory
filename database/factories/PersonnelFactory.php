<?php

namespace Database\Factories;

use App\Models\Personnel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Personnel>
 */
class PersonnelFactory extends Factory
{
    protected $model = Personnel::class;

    /**
     * Define the model's default state.
     *
     * `separation_cause` uses a plausible literal string rather than
     * drawing from a reference table, since seeding the real reference
     * lists is a separate follow-up concern from building test fixtures.
     *
     * The Tier 1 FK columns (position_id/ro_office_id/sdo_office_id,
     * lookup-normalization ADR Step 2) are deliberately NOT set here — they
     * default to null, which is valid: unlike Equipment's five Tier 1 FKs
     * (NOT NULL as of the ADR's Step 4 cleanup, since their legacy string
     * columns were originally required), personnel.position/ro_division/
     * division_unit were already nullable in the original schema, so their
     * FK replacements stay nullable too. Tests that need a real, resolvable
     * Position/RoOffice/SdoOffice override them explicitly with ids from a
     * seeded ReferenceDataSeeder run (see
     * EquipmentControllerTest::makeEquipment() for the established
     * pattern).
     *
     * `fund_source` switches content type under the lookup-normalization
     * ADR (Question 4): it's no longer a parallel legacy+new column pair
     * like position/ro_division/division_unit (there's only ever been the
     * one `fund_source` column), so its stored contents move straight from
     * value-strings to `personnel_libraries` row ids. Plausible small
     * integers stand in here (no query against personnel_libraries,
     * keeping the factory fast and independent of ReferenceDataSeeder
     * having run) — tests asserting a specific funding-source label
     * override with real seeded ids, same override pattern as the Tier 1
     * FK columns above.
     */
    public function definition(): array
    {
        return [
            'employee_id' => fake()->unique()->numerify('########'),
            'last_name' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional(0.8)->lastName(),
            'suffix' => fake()->optional(0.1)->randomElement(['Jr.', 'Sr.', 'II', 'III', 'IV']),
            'oic' => fake()->boolean(10),
            'oic_office' => fake()->optional(0.1)->randomElement([
                'Office of the SDS', 'Office of the ASDS', 'ICT Unit',
            ]),
            'mobile_1' => fake()->numerify('09#########'),
            'mobile_2' => fake()->optional(0.3)->numerify('09#########'),
            'deped_email' => fake()->unique()->userName().'@deped.gov.ph',
            'personal_email' => fake()->optional(0.8)->safeEmail(),
            'date_hired' => fake()->optional(0.9)->dateTimeBetween('-30 years', '-1 year'),
            'non_deped_funded' => fake()->boolean(15),
            // Placeholder personnel_libraries ids (lookup-normalization ADR
            // Question 4) — not guaranteed to resolve against a real seeded
            // row, same tradeoff documented in this method's doc-comment.
            'fund_source' => fake()->optional(0.15)->randomElements(
                [1, 2, 3, 4, 5, 6],
                fake()->numberBetween(1, 2)
            ),
            'inactive' => false,
            'separation_date' => null,
            'separation_cause' => null,
            'transferred_from' => fake()->optional(0.05)->city(),
            'transferred_to' => null,
        ];
    }

    /**
     * Separated personnel — separation_date/cause populated, inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'inactive' => true,
            'separation_date' => fake()->dateTimeBetween('-2 years', 'now'),
            'separation_cause' => fake()->randomElement(['Retirement', 'Resignation', 'Transfer', 'Death']),
        ]);
    }

    /**
     * Officer-in-charge of an office (distinct from their own position).
     */
    public function oic(): static
    {
        return $this->state(fn (array $attributes) => [
            'oic' => true,
            'oic_office' => 'Office of the SDS',
        ]);
    }
}
