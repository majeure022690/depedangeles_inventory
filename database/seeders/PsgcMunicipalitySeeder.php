<?php

namespace Database\Seeders;

use App\Models\PsgcMunicipality;
use App\Models\PsgcProvince;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Seeds `psgc_municipalities` from database/seeders/data/psgc_municipalities.json.
 * Each row's `province_code` is resolved to the parent's real `id` at seed
 * time (ids are auto-increment, not known ahead of seeding) via a
 * code=>id map built from the already-seeded psgc_provinces table — this
 * seeder MUST run after PsgcProvinceSeeder.
 *
 * insertOrIgnore, not upsert — same "reset button" reasoning as
 * OfficeSeeder/ReferenceDataSeeder.
 */
class PsgcMunicipalitySeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/psgc_municipalities.json');

        if (! File::exists($path)) {
            throw new RuntimeException("PSGC municipalities seed file not found at [{$path}].");
        }

        /** @var array<int, array{code: string, name: string, province_code: string}> $rows */
        $rows = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        $provinceIdsByCode = PsgcProvince::query()->pluck('id', 'code');

        $mapped = array_map(fn (array $row) => [
            'code' => $row['code'],
            'name' => $row['name'],
            'province_id' => $provinceIdsByCode[$row['province_code']] ?? throw new RuntimeException(
                "PSGC municipality [{$row['code']}] references unknown province_code [{$row['province_code']}]."
            ),
        ], $rows);

        $inserted = 0;

        foreach (array_chunk($mapped, 200) as $chunk) {
            $inserted += PsgcMunicipality::insertOrIgnore($chunk);
        }

        $this->command?->info(sprintf(
            'psgc_municipalities: %d row(s) inserted, %d already present and left untouched (of %d total).',
            $inserted,
            count($mapped) - $inserted,
            count($mapped),
        ));
    }
}
