<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Step 1.5 of the lookup-normalization ADR (docs/architecture-decisions/
 * lookup-normalization.md, Question 4): migrates the CONTENTS of the 10
 * JSON-array multi-select columns from arrays of value-strings to arrays
 * of the corresponding new table's integer row ids. The columns
 * themselves stay JSON — this is a data-only migration, no schema change.
 *
 *   - personnel.fund_source -> personnel_libraries (teachers_funding_source) ids
 *   - stakeholder_profiles.nearby_institutions -> stakeholder_libraries (nearby_institution) ids
 *   - stakeholder_profiles.access_paths -> stakeholder_libraries (type_of_access_road) ids
 *   - stakeholder_profiles.transportation_options -> stakeholder_libraries (by_transportation) ids
 *   - stakeholder_profiles.community_engagement -> stakeholder_libraries (community_engagement) ids
 *   - internet_connectivity_surveys.available_isps -> isp_providers ids
 *   - internet_connectivity_surveys.subscribed_isps -> isp_providers ids
 *   - internet_connectivity_surveys.mobile_signal_types -> connectivity_libraries (mobile_network_signal) ids
 *   - internet_connectivity_surveys.coverage_areas -> connectivity_libraries (coverage_area) ids
 *   - internet_connectivity_surveys.electricity_sources -> stakeholder_libraries (source_of_electricity) ids
 *
 * IDEMPOTENT / SAFE TO RE-RUN: each array element is inspected
 * individually. An element that's already an integer is assumed already
 * migrated and left untouched — this is what makes re-running safe;
 * without it, a second run would try to look up an id like `5` against
 * the reference table's string column, find nothing, and misreport an
 * already-migrated element as unmatched. A string element that doesn't
 * match any reference row's value/name is left as the original string
 * (NOT dropped — dropping would silently delete data) and reported, so a
 * later re-run (after the typo is fixed, e.g. by adding the missing
 * reference row) picks it up automatically.
 *
 * Not called from DatabaseSeeder — operational, run-on-demand
 * (`php artisan db:seed --class=ReferenceDataJsonBackfillSeeder`), same
 * as ReferenceDataBackfillSeeder.
 */
class ReferenceDataJsonBackfillSeeder extends Seeder
{
    public function run(): void
    {
        $this->backfillJsonColumn('personnel', 'fund_source', 'personnel_libraries', 'teachers_funding_source');

        $this->backfillJsonColumn('stakeholder_profiles', 'nearby_institutions', 'stakeholder_libraries', 'nearby_institution');
        $this->backfillJsonColumn('stakeholder_profiles', 'access_paths', 'stakeholder_libraries', 'type_of_access_road');
        $this->backfillJsonColumn('stakeholder_profiles', 'transportation_options', 'stakeholder_libraries', 'by_transportation');
        $this->backfillJsonColumn('stakeholder_profiles', 'community_engagement', 'stakeholder_libraries', 'community_engagement');

        $this->backfillJsonColumn('internet_connectivity_surveys', 'available_isps', 'isp_providers', null);
        $this->backfillJsonColumn('internet_connectivity_surveys', 'subscribed_isps', 'isp_providers', null);
        $this->backfillJsonColumn('internet_connectivity_surveys', 'mobile_signal_types', 'connectivity_libraries', 'mobile_network_signal');
        $this->backfillJsonColumn('internet_connectivity_surveys', 'coverage_areas', 'connectivity_libraries', 'coverage_area');
        $this->backfillJsonColumn('internet_connectivity_surveys', 'electricity_sources', 'stakeholder_libraries', 'source_of_electricity');
    }

    /**
     * @param  string|null  $refType  null for a Tier 1 table matched on
     *                                `name` (isp_providers); a Tier 2
     *                                library `type` value matched on
     *                                `value` otherwise.
     * @return array{matched: int, unmatched: array<string, int>, rows_updated: int} Exposed for tests.
     */
    public function backfillJsonColumn(string $table, string $jsonColumn, string $refTable, ?string $refType): array
    {
        $valueToId = $refType === null
            ? DB::table($refTable)->pluck('id', 'name')
            : DB::table($refTable)->where('type', $refType)->pluck('id', 'value');

        $matched = 0;
        $unmatched = [];
        $rowsUpdated = 0;

        DB::table($table)
            ->whereNotNull($jsonColumn)
            ->orderBy('id')
            ->select('id', $jsonColumn)
            ->chunkById(200, function ($rows) use (&$matched, &$unmatched, &$rowsUpdated, $table, $jsonColumn, $valueToId) {
                foreach ($rows as $row) {
                    $raw = $row->{$jsonColumn};
                    $decoded = json_decode($raw, true);

                    if (! is_array($decoded) || $decoded === []) {
                        continue;
                    }

                    $result = [];
                    $changed = false;

                    foreach ($decoded as $item) {
                        if (is_int($item)) {
                            // Already migrated (or already an id) — leave
                            // as-is, this is what keeps re-runs safe.
                            $result[] = $item;

                            continue;
                        }

                        if (! is_string($item)) {
                            // Defensive: unexpected element shape, keep
                            // as-is rather than lose data.
                            $result[] = $item;

                            continue;
                        }

                        $id = $valueToId->get($item);

                        if ($id !== null) {
                            $result[] = $id;
                            $matched++;
                            $changed = true;
                        } else {
                            // Leave the original string in place (not
                            // dropped) so a future re-run can pick it up
                            // once the typo/missing reference row is fixed.
                            $result[] = $item;
                            $unmatched[$item] = ($unmatched[$item] ?? 0) + 1;
                        }
                    }

                    if ($changed) {
                        DB::table($table)->where('id', $row->id)->update([
                            $jsonColumn => json_encode($result),
                        ]);
                        $rowsUpdated++;
                    }
                }
            });

        $this->report($table, $jsonColumn, $refTable, $matched, $unmatched, $rowsUpdated);

        return ['matched' => $matched, 'unmatched' => $unmatched, 'rows_updated' => $rowsUpdated];
    }

    /**
     * @param  array<string, int>  $unmatched
     */
    private function report(
        string $table,
        string $jsonColumn,
        string $refTable,
        int $matched,
        array $unmatched,
        int $rowsUpdated
    ): void {
        $this->command?->info(sprintf(
            '%s.%s -> %s ids: %d element(s) matched across %d row(s) updated.',
            $table, $jsonColumn, $refTable, $matched, $rowsUpdated
        ));

        if ($unmatched === []) {
            return;
        }

        $this->command?->warn(sprintf(
            '%s.%s -> %s: %d distinct unmatched value(s), left as strings — needs manual review:',
            $table, $jsonColumn, $refTable, count($unmatched)
        ));

        foreach ($unmatched as $value => $count) {
            $this->command?->warn(sprintf('    "%s" (%d occurrence(s))', $value, $count));
        }
    }
}
