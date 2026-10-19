<?php

namespace Database\Factories;

use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Office>
 */
class OfficeFactory extends Factory
{
    protected $model = Office::class;

    /**
     * `office_type` matches the real vocabulary observed in the seeded
     * offices.json (Elementary School, High School, Integrated School,
     * Senior High School, Division Office, Unit, and a handful with no
     * type at all) — `school_id` is only populated when the type is
     * actually a school, matching Office's doc-comment.
     */
    public function definition(): array
    {
        $schoolTypes = ['Elementary School', 'High School', 'Integrated School', 'Senior High School'];
        $officeType = fake()->randomElement([...$schoolTypes, 'Division Office', 'Unit', null]);
        $isSchool = in_array($officeType, $schoolTypes, true);

        // Uniqueness enforced via a numeric suffix (like EquipmentFactory's
        // property_no), not Faker's unique() modifier on city()/company()
        // alone — those pools are combinatorially large but not guaranteed
        // collision-free across a big test run.
        $name = $isSchool
            ? fake()->city().' '.fake()->randomElement(['ES', 'HS', 'NHS', 'IS'])
            : fake()->company().' Office';

        return [
            'office_name' => trim($name).' '.fake()->unique()->numerify('#####'),
            'office_type' => $officeType,
            'school_id' => $isSchool ? fake()->unique()->numberBetween(100000, 199999) : null,
            'address' => fake()->optional(0.7)->address(),
            'region' => fake()->optional(0.7)->randomElement(['Region III', 'Region IV-A', 'NCR']),
            'district' => fake()->optional(0.6)->city(),
            'division' => fake()->optional(0.6)->city().' Division',
            'contact_number' => fake()->optional(0.6)->phoneNumber(),
            'email' => fake()->optional(0.6)->safeEmail(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
