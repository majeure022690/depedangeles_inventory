<?php

namespace Database\Factories;

use App\Models\GspIctProfile;
use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GspIctProfile>
 */
class GspIctProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'school_year' => '2025-2026',
            'as_of' => '2026-03-31',
            'level' => 'elementary',
        ];
    }
}
