<?php

namespace Database\Seeders;

use App\Models\IspAccount;
use App\Models\IspProvider;
use App\Models\IspSubscriptionCost;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Seeds `isp_subscription_costs` from
 * database/seeders/data/isp_subscription_costs.json — one cost/budget
 * record per already-seeded isp_accounts row, imported from the source
 * Excel workbook's ISP Subscription Cost sheet (4 real rows; same
 * UsedRange-padding pattern as every other sheet in this workbook).
 *
 * Resolves `isp_account_id` the same way IspSpeedTestSeeder does — see its
 * doc-comment for the full rationale. A match failure aborts the seeder
 * (CLAUDE.md "fail safe, not silent").
 *
 * All source columns beyond the linking key are blank in this dataset
 * (contract_start_date/contract_end_date are populated — matching the
 * "current" period already on isp_accounts — but total_amount_spent and
 * every "projected" column are unrecorded) — every column here is
 * genuinely nullable per the migration's own doc-comment, so blanks are
 * imported as null, not defaulted.
 *
 * firstOrCreate keyed on [isp_account_id, contract_start_date]: this table
 * has no natural unique key either; a given account's "current" budget
 * period is identified by its start date for this dataset (one row per
 * account, all distinct accounts), matching IspSpeedTestSeeder's
 * [isp_account_id, tested_at] idempotency approach.
 */
class IspSubscriptionCostSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/isp_subscription_costs.json');

        if (! File::exists($path)) {
            throw new RuntimeException("ISP subscription cost seed file not found at [{$path}].");
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        $inserted = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $ispAccountId = $this->resolveIspAccountId($row);

            $subscriptionCost = IspSubscriptionCost::firstOrCreate(
                [
                    'isp_account_id' => $ispAccountId,
                    'contract_start_date' => $row['contract_start_date'],
                ],
                [
                    'contract_end_date' => $row['contract_end_date'],
                    'total_amount_spent' => $row['total_amount_spent'],
                    'contract_projected_start_date' => $row['contract_projected_start_date'],
                    'contract_projected_end_date' => $row['contract_projected_end_date'],
                    'total_projected_expenditure' => $row['total_projected_expenditure'],
                ]
            );

            $subscriptionCost->wasRecentlyCreated ? $inserted++ : $skipped++;
        }

        $this->command?->info(sprintf(
            'isp_subscription_costs: %d row(s) inserted, %d already present and left untouched (of %d total).',
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
                'isp_subscription_costs seed row: no matching isp_accounts row for [isp=%s, isp_billing_account_number=%s].',
                $row['isp'],
                $row['isp_billing_account_number']
            ));
        }

        return $accountId;
    }
}
