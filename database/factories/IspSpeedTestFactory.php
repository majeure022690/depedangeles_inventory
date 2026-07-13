<?php

namespace Database\Factories;

use App\Enums\ConnectivityLibraryType;
use App\Models\ConnectivityLibrary;
use App\Models\IspAccount;
use App\Models\IspSpeedTest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IspSpeedTest>
 */
class IspSpeedTestFactory extends Factory
{
    protected $model = IspSpeedTest::class;

    public function definition(): array
    {
        return [
            'isp_account_id' => IspAccount::factory(),
            'download_speed' => fake()->randomFloat(2, 1, 100),
            'upload_speed' => fake()->randomFloat(2, 1, 100),
            'ping' => fake()->optional(0.85)->numberBetween(1, 200),
            'tested_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'signal_quality' => fake()->randomElement(ConnectivityLibrary::activeValues(ConnectivityLibraryType::SignalQuality)),
            'rate_isp_service' => fake()->optional(0.6)->numberBetween(1, 5),
        ];
    }
}
