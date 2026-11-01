<?php

namespace Database\Factories;

use App\Enums\PropertyCategory;
use App\Models\Office;
use App\Models\PropertyItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyItem>
 */
class PropertyItemFactory extends Factory
{
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 50);

        return [
            'office_id' => Office::factory(),
            'category' => PropertyCategory::FurnitureFixtures,
            'article' => 'Monoblock Chair',
            'description' => fake()->optional()->sentence(4),
            'property_no' => fake()->optional()->bothify('PROP-####'),
            'unit_of_measure' => 'pc',
            'date_acquired' => fake()->optional()->dateTimeBetween('-8 years', 'now'),
            'unit_value' => fake()->randomFloat(2, 100, 5000),
            'quantity' => $quantity,
            'on_hand_count' => $quantity,
            'condition' => 'functional',
            'with_are' => false,
        ];
    }
}
