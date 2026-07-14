<?php

namespace Database\Seeders;

use App\Models\Office;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Seeds `offices` from database/seeders/data/offices.json — a wholesale
 * import of the division's existing "offices" table (schools and
 * division-level offices/units together), `id` values preserved exactly so
 * they stay stable if anything else ever references them by that id.
 *
 * insertOrIgnore, not upsert — same "reset button" reasoning as
 * ReferenceDataSeeder: a re-run (migrate:fresh --seed, a redeploy) must
 * never revert a later admin edit made through the app back to this seed
 * data. Only rows whose `id` doesn't already exist get inserted.
 */
class OfficeSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/offices.json');

        if (! File::exists($path)) {
            throw new RuntimeException("Office seed file not found at [{$path}].");
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        $inserted = 0;

        foreach (array_chunk($rows, 200) as $chunk) {
            $inserted += Office::insertOrIgnore($chunk);
        }

        $this->command?->info(sprintf(
            'offices: %d row(s) inserted, %d already present and left untouched (of %d total).',
            $inserted,
            count($rows) - $inserted,
            count($rows),
        ));
    }
}
