<?php

namespace Database\Seeders;

use App\Models\PsgcBarangay;
use App\Models\PsgcMunicipality;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Seeds `psgc_barangays` from database/seeders/data/psgc_barangays.json
 * (~3,100 rows). Each row's `municipality_code` is resolved to the
 * parent's real `id` at seed time via a code=>id map built from the
 * already-seeded psgc_municipalities table — this seeder MUST run after
 * PsgcMunicipalitySeeder.
 *
 * insertOrIgnore, not upsert — same "reset button" reasoning as
 * OfficeSeeder/ReferenceDataSeeder.
 */
class PsgcBarangaySeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/psgc_barangays.json');

        if (! File::exists($path)) {
            throw new RuntimeException("PSGC barangays seed file not found at [{$path}].");
        }

        /** @var array<int, array{code: string, name: string, municipality_code: string}> $rows */
        $rows = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        $municipalityIdsByCode = PsgcMunicipality::query()->pluck('id', 'code');

        $mapped = array_map(fn (array $row) => [
            'code' => $row['code'],
            'name' => $row['name'],
            'municipality_id' => $municipalityIdsByCode[$row['municipality_code']] ?? throw new RuntimeException(
                "PSGC barangay [{$row['code']}] references unknown municipality_code [{$row['municipality_code']}]."
            ),
        ], $rows);

        $inserted = 0;

        foreach (array_chunk($mapped, 200) as $chunk) {
            $inserted += PsgcBarangay::insertOrIgnore($chunk);
        }

        $this->command?->info(sprintf(
            'psgc_barangays: %d row(s) inserted, %d already present and left untouched (of %d total).',
            $inserted,
            count($mapped) - $inserted,
            count($mapped),
        ));
    }
}
