<?php

namespace Database\Seeders;

use App\Models\IspAccount;
use App\Models\IspProvider;
use App\Models\IspSpeedTest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Seeds `isp_speed_tests` from database/seeders/data/isp_speed_tests.json —
 * one speed-test run per already-seeded isp_accounts row, imported from the
 * source Excel workbook's ISP Speed Test Results sheet (4 real rows; same
 * UsedRange-padding pattern as every other sheet in this workbook).
 *
 * Resolves `isp_account_id` by matching [isp, isp_billing_account_number]
 * against the already-seeded isp_accounts table (IspAccountSeeder must run
 * first — see DatabaseSeeder ordering). A match failure aborts the seeder
 * rather than silently dropping the row (CLAUDE.md "fail safe, not
 * silent").
 *
 * firstOrCreate keyed on [isp_account_id, tested_at]: this table is
 * append-only time-series data with no natural unique key of its own, but
 * a given account can only genuinely have one test result recorded at one
 * exact timestamp — sufficient to make a re-run idempotent for this
 * dataset without inventing a new column.
 */
class IspSpeedTestSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/isp_speed_tests.json');

        if (! File::exists($path)) {
            throw new RuntimeException("ISP speed test seed file not found at [{$path}].");
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        $inserted = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $ispAccountId = $this->resolveIspAccountId($row);

            $speedTest = IspSpeedTest::firstOrCreate(
                [
                    'isp_account_id' => $ispAccountId,
                    'tested_at' => $row['tested_at'],
                ],
                [
                    'download_speed' => $row['download_speed'],
                    'upload_speed' => $row['upload_speed'],
                    'ping' => $row['ping'],
                    'signal_quality' => $row['signal_quality'],
                    'rate_isp_service' => $row['rate_isp_service'],
                ]
            );

            $speedTest->wasRecentlyCreated ? $inserted++ : $skipped++;
        }

        $this->command?->info(sprintf(
            'isp_speed_tests: %d row(s) inserted, %d already present and left untouched (of %d total).',
            $inserted,
            $skipped,
            count($rows)
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveIspAccountId(array $row): int
    {
        $providerId = IspProvider::where('name', $row['isp'])->value('id');

        $accountId = $providerId !== null
            ? IspAccount::where('isp_provider_id', $providerId)
                ->where('isp_billing_account_no', $row['isp_billing_account_number'])
                ->value('id')
            : null;

        if ($accountId === null) {
            throw new RuntimeException(sprintf(
                'isp_speed_tests seed row: no matching isp_accounts row for [isp=%s, isp_billing_account_number=%s].',
                $row['isp'],
                $row['isp_billing_account_number']
            ));
        }

        return $accountId;
    }
}
