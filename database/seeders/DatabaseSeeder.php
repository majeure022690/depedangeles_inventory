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

        $testUser = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
        $testUser->assignRole('division-ict-admin');

        // The 13 Tier 1/Tier 2 reference tables from the lookup-
        // normalization ADR. Supersedes the old LookupSeeder/`lookups`
        // table, dropped in the ADR's Step 4 cleanup.
        $this->call(ReferenceDataSeeder::class);
    }
}
