<?php

namespace Database\Seeders;

use App\Models\Personnel;
use App\Models\Position;
use App\Models\SdoOffice;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Seeds `personnel` from database/seeders/data/personnel.json — the
 * division's current personnel roster, imported from the source Excel
 * workbook's Personnel sheet (114 rows).
 *
 * Tier 1 FK resolution (lookup-normalization ADR): the data file stores
 * `position`/`division_unit` as plain name strings (matching the source
 * workbook), resolved here to `position_id`/`sdo_office_id` via an exact
 * match against the already-seeded `positions`/`sdo_offices` reference
 * tables (ReferenceDataSeeder must run first — see DatabaseSeeder
 * ordering). `ro_office_id` is always null for this dataset — the source
 * workbook's `ro_division` column is blank in every row. A name that fails
 * to resolve is treated as a data-quality bug in the source extract, not
 * silently dropped — it aborts the seeder with the offending row's
 * employee_id and name (CLAUDE.md "fail safe, not silent").
 *
 * firstOrCreate on `employee_id` (unique on the table), matching the
 * "reset button" idempotency lesson the other seeders use insertOrIgnore
 * for: a re-run (migrate:fresh --seed, a redeploy, a stray db:seed) only
 * inserts rows that don't already exist and never overwrites a later
 * admin edit made through the app. Row-by-row (not a bulk insertOrIgnore)
 * because each row needs its own FK lookup first.
 */
class PersonnelSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/personnel.json');

        if (! File::exists($path)) {
            throw new RuntimeException("Personnel seed file not found at [{$path}].");
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        $inserted = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $positionId = $this->resolvePositionId($row);
            $sdoOfficeId = $this->resolveSdoOfficeId($row);

            $personnel = Personnel::firstOrCreate(
                ['employee_id' => $row['employee_id']],
                [
                    'last_name' => $row['last_name'],
                    'first_name' => $row['first_name'],
                    'middle_name' => $row['middle_name'],
                    'suffix' => $row['suffix'],

                    'position_id' => $positionId,
                    'ro_office_id' => null,
                    'sdo_office_id' => $sdoOfficeId,

                    'oic' => $row['oic'],
                    'oic_office' => $row['oic_office'],

                    'mobile_1' => $row['mobile_1'],
                    'mobile_2' => $row['mobile_2'],
                    'deped_email' => $row['deped_email'],
                    'personal_email' => $row['personal_email'],

                    'date_hired' => $row['date_hired'],

                    'non_deped_funded' => $row['non_deped_funded'],
                    'fund_source' => $row['fund_source'],

                    'inactive' => $row['inactive'],
                    'separation_date' => $row['separation_date'],
                    'separation_cause' => $row['separation_cause'],

                    'transferred_from' => $row['transferred_from'],
                    'transferred_to' => $row['transferred_to'],
                ]
            );

            $personnel->wasRecentlyCreated ? $inserted++ : $skipped++;
        }

        $this->command?->info(sprintf(
            'personnel: %d row(s) inserted, %d already present and left untouched (of %d total).',
            $inserted,
            $skipped,
            count($rows)
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolvePositionId(array $row): ?int
    {
        if (blank($row['position'])) {
            return null;
        }

        $id = Position::where('name', $row['position'])->value('id');

        if ($id === null) {
            throw new RuntimeException(sprintf(
                'personnel seed row [employee_id=%s]: position [%s] not found in positions table.',
                $row['employee_id'],
                $row['position']
            ));
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveSdoOfficeId(array $row): ?int
    {
        if (blank($row['division_unit'])) {
            return null;
        }

        $id = SdoOffice::where('name', $row['division_unit'])->value('id');

        if ($id === null) {
            throw new RuntimeException(sprintf(
                'personnel seed row [employee_id=%s]: division_unit [%s] not found in sdo_offices table.',
                $row['employee_id'],
                $row['division_unit']
            ));
        }

        return $id;
    }
}
