<?php

namespace Database\Seeders;

use App\Models\IspAccount;
use App\Models\IspProvider;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Seeds `isp_accounts` from database/seeders/data/isp_accounts.json — the
 * division's current ISP subscriptions, imported from the source Excel
 * workbook's ISP Account sheet (4 real rows; the sheet's much larger
 * UsedRange was mostly blank padding plus one spreadsheet-computed summary
 * row, not real data — same pattern already found in Personnel/Equipment).
 *
 * Tier 1 FK resolution (lookup-normalization ADR): the data file stores
 * `isp` as a plain provider-name string (matching the source workbook),
 * resolved here to `isp_provider_id` via an exact match against the
 * already-seeded `isp_providers` table (ReferenceDataSeeder must run first
 * — see DatabaseSeeder ordering). A name that fails to resolve aborts the
 * seeder with the offending row's billing account number (CLAUDE.md "fail
 * safe, not silent"), matching PersonnelSeeder's convention.
 *
 * `fund_source` is blank in 100% of source rows (never captured in the
 * original spreadsheet) — defaulted to "Not Applicable" at data-cleaning
 * time (see the one-off transform that produced isp_accounts.json), the
 * same fallback Equipment's seeder uses for allotment_class.
 *
 * firstOrCreate keyed on [isp_provider_id, isp_billing_account_no]: unlike
 * Personnel's employee_id, isp_billing_account_no is deliberately NOT
 * unique at the database level (see create_isp_accounts_table's SCHEMA
 * DECISION comment — different providers can reuse account-number
 * formats), but combined with isp_provider_id it's a stable, collision-free
 * match for this specific dataset (verified: all 4 rows have distinct
 * billing account numbers), giving idempotent re-runs without inventing a
 * new database-level constraint this table was deliberately built without.
 */
class IspAccountSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/isp_accounts.json');

        if (! File::exists($path)) {
            throw new RuntimeException("ISP account seed file not found at [{$path}].");
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        $inserted = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $ispProviderId = $this->resolveIspProviderId($row);

            $ispAccount = IspAccount::firstOrCreate(
                [
                    'isp_provider_id' => $ispProviderId,
                    'isp_billing_account_no' => $row['isp_billing_account_no'],
                ],
                [
                    'cost_per_month' => $row['cost_per_month'],
                    'package_purchased_inclusion' => $row['package_purchased_inclusion'],

                    'school_area_coverage' => $row['school_area_coverage'],
                    'min_speed' => $row['min_speed'],
                    'max_speed' => $row['max_speed'],

                    'subscription_type' => $row['subscription_type'],
                    'isp_connection_type' => $row['isp_connection_type'],

                    'contract_start_date' => $row['contract_start_date'],
                    'contract_end_date' => $row['contract_end_date'],
                    'inactive_contract' => $row['inactive_contract'],

                    'purpose_of_subscription' => $row['purpose_of_subscription'],

                    'mode_of_acquisition' => $row['mode_of_acquisition'],
                    'source_of_acquisition' => $row['source_of_acquisition'],
                    'donor' => $row['donor'],
                    'fund_source' => $row['fund_source'],

                    'number_access_points_linked' => $row['number_access_points_linked'],
                    'locations_access_points' => $row['locations_access_points'],

                    'number_admin_area_covered' => $row['number_admin_area_covered'],
                    'rate_connectivity_admin_areas' => $row['rate_connectivity_admin_areas'],
                    'number_classrooms_covered' => $row['number_classrooms_covered'],
                    'rate_connectivity_classroom' => $row['rate_connectivity_classroom'],

                    'overall_signal_quality' => $row['overall_signal_quality'],
                    'rate_isp_service' => $row['rate_isp_service'],
                ]
            );

            $ispAccount->wasRecentlyCreated ? $inserted++ : $skipped++;
        }

        $this->command?->info(sprintf(
            'isp_accounts: %d row(s) inserted, %d already present and left untouched (of %d total).',
            $inserted,
            $skipped,
            count($rows)
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveIspProviderId(array $row): int
    {
        $id = IspProvider::where('name', $row['isp'])->value('id');

        if ($id === null) {
            throw new RuntimeException(sprintf(
                'isp_accounts seed row [isp_billing_account_no=%s]: provider [%s] not found in isp_providers table.',
                $row['isp_billing_account_no'],
                $row['isp']
            ));
        }

        return $id;
    }
}
