<?php

namespace Database\Seeders;

use App\Models\PsgcProvince;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Seeds `psgc_provinces` from database/seeders/data/psgc_provinces.json —
 * Region III's 7 provinces, parsed from the division's legacy PSGC
 * reference dump.
 *
 * insertOrIgnore, not upsert, matching OfficeSeeder/ReferenceDataSeeder's
 * "reset button" reasoning — a re-run must never revert data. `code` is
 * the unique key a re-run matches against.
 */
class PsgcProvinceSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/psgc_provinces.json');

        if (! File::exists($path)) {
            throw new RuntimeException("PSGC provinces seed file not found at [{$path}].");
        }

        /** @var array<int, array{code: string, name: string}> $rows */
        $rows = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        $inserted = PsgcProvince::insertOrIgnore($rows);

        $this->command?->info(sprintf(
            'psgc_provinces: %d row(s) inserted, %d already present and left untouched (of %d total).',
            $inserted,
            count($rows) - $inserted,
            count($rows),
        ));
    }
}
