<?php

namespace Database\Factories;

use App\Models\IspAccount;
use App\Models\IspSubscriptionCost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IspSubscriptionCost>
 */
class IspSubscriptionCostFactory extends Factory
{
    protected $model = IspSubscriptionCost::class;

    public function definition(): array
    {
        // Built from fake()->boolean() + a plain dateTimeBetween() call
        // rather than fake()->optional()->dateTimeBetween() (equivalent
        // at runtime): Faker's own stubs declare
        // Generator::dateTimeBetween() as always returning a non-null
        // \DateTime, even when called through the optional() proxy that
        // actually can return null — which made Larastan see the
        // `$currentStart ? ... : null` checks below as dead code no
        // matter how the nullable value was produced.
        $currentStart = fake()->boolean(85) ? fake()->dateTimeBetween('-2 years', '-1 month') : null;
        $projectedStart = fake()->boolean(60) ? fake()->dateTimeBetween('now', '+1 year') : null;

        return [
            'contract_start_date' => $currentStart,
            'contract_end_date' => $currentStart ? fake()->dateTimeBetween($currentStart, '+1 year') : null,
            'total_amount_spent' => fake()->optional(0.8)->randomFloat(2, 5000, 180000),

            'contract_projected_start_date' => $projectedStart,
            'contract_projected_end_date' => $projectedStart ? fake()->dateTimeBetween($projectedStart, '+1 year') : null,
            'total_projected_expenditure' => fake()->optional(0.6)->randomFloat(2, 5000, 200000),

            'isp_account_id' => IspAccount::factory(),
        ];
    }
}
