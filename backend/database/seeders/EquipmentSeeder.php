<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\EquipmentClassification;
use App\Models\EquipmentCondition;
use App\Models\ItemType;
use App\Models\Personnel;
use App\Services\EquipmentAccountabilityService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Seeds `equipment` and the paired "Beginning Inventory" `equipment_transactions`
 * row for each from database/seeders/data/equipment.json — the division's
 * current asset register, imported from the source Excel workbook's
 * Equipment sheet (536 rows). Mirrors PersonnelSeeder's shape/idempotency
 * pattern; read that seeder first if this one is unclear.
 *
 * ONE ROW = ONE EQUIPMENT ITEM: despite the source sheet's documented
 * "copy this row for each lifecycle transaction" convention, this real
 * dataset never used it — transaction_type is uniformly "Beginning
 * Inventory" wherever populated, end_user/received_by are blank in every
 * row, and there are zero duplicate non-blank property_no values (verified
 * against the raw extract before this seeder was written). So every row
 * becomes exactly one Equipment row plus exactly one Beginning Inventory
 * EquipmentTransaction row — no grouping/merging logic needed.
 *
 * TIER 1 FK RESOLUTION (lookup-normalization ADR): item/brand_manufacturer/
 * category/classification/equipment_condition are resolved here to
 * item_type_id/brand_id/equipment_category_id/equipment_classification_id/
 * equipment_condition_id against the already-seeded reference tables
 * (ReferenceDataSeeder must run first). Equipment's legacy string columns
 * for these were dropped outright in the ADR's Step 4 cleanup, so — same as
 * PersonnelSeeder only populating position_id/sdo_office_id — only the FK
 * columns are written here, matching EquipmentStoreRequest's validated
 * fields. A name that fails to resolve is a data-quality bug in the source
 * extract, not something to silently default or drop (CLAUDE.md "fail
 * safe, not silent") — it aborts the seeder with the offending row's
 * source line number and the unresolved value.
 *
 * IDEMPOTENCY KEY: ~19% of this real dataset (100/536 rows) has no
 * property_no — equipment awaiting property-number issuance is a normal,
 * permanent state here (see 2026_07_14_132356's migration doc-comment), not
 * a data-entry gap, so those rows can't be dropped or key on a column that
 * doesn't exist for them. firstOrCreate needs *some* stable WHERE clause
 * per row:
 *   - Rows WITH a property_no key on it alone — it's already unique
 *     (`equipment.property_no` has a unique index) and is the natural
 *     real-world identifier.
 *   - Rows WITHOUT one key on the composite
 *     (property_no IS NULL, serial_number, model, item_type_id,
 *     specifications, equipment_location). serial_number is populated in
 *     100% of rows (verified against the raw extract), so it anchors the
 *     composite even though it isn't unique alone (e.g. many placeholder
 *     "N/A" serials). Deliberately excludes current_accountable_officer_id
 *     from the match: that column is null at the moment of Equipment::create()
 *     and only gets synced afterward by EquipmentAccountabilityService, so
 *     using it as a firstOrCreate search key would never match on a
 *     re-run's first (pre-sync) lookup. The five-column composite above was
 *     verified unique across all 100 null-property_no rows before this
 *     seeder was written — no two rows collide on it.
 *
 * TRANSACTION CREATION GUARD: EquipmentAccountabilityService::recordTransaction()
 * is only called when Equipment::create()/firstOrCreate() actually inserted
 * a new row (wasRecentlyCreated) — otherwise a re-run of this seeder would
 * append a duplicate Beginning Inventory transaction (and re-sync current_*,
 * pointlessly but harmlessly) onto equipment that already has its initial
 * transaction from a prior run.
 *
 * ACCOUNTABILITY SYNC GUARD: current_accountable_officer_id/current_end_user_id
 * are never set directly on Equipment::create() — they stay null/default
 * from creation and are synced exclusively through
 * EquipmentAccountabilityService::recordTransaction(), per the guard
 * documented on the Equipment model's booted() hook.
 */
class EquipmentSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/equipment.json');

        if (! File::exists($path)) {
            throw new RuntimeException("Equipment seed file not found at [{$path}].");
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        $accountability = app(EquipmentAccountabilityService::class);

        $inserted = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $itemTypeId = $this->resolveId(ItemType::class, 'item_types', $row['item'], $row);
            $brandId = $this->resolveId(Brand::class, 'brands', $row['brand_manufacturer'], $row);
            $categoryId = $this->resolveId(EquipmentCategory::class, 'equipment_categories', $row['category'], $row);
            $classificationId = $this->resolveId(EquipmentClassification::class, 'equipment_classifications', $row['classification'], $row);
            $conditionId = $this->resolveId(EquipmentCondition::class, 'equipment_conditions', $row['equipment_condition'], $row);

            $attributes = [
                'old_property_no' => $row['old_property_no'],
                'serial_number' => $row['serial_number'],
                'uom' => $row['uom'],
                'model' => $row['model'],
                'specifications' => $row['specifications'],
                'non_dcp' => $row['non_dcp'],
                'dcp_package' => $row['dcp_package'],
                'dcp_year' => $row['dcp_year'],
                'gl_sl_code' => $row['gl_sl_code'],
                'uacs' => $row['uacs'],
                'acquisition_cost' => $row['acquisition_cost'],
                'received_date' => $row['received_date'],
                'estimated_useful_life' => $row['estimated_useful_life'],
                'mode_acquisition' => $row['mode_acquisition'],
                'source_acquisition' => $row['source_acquisition'],
                'donor' => $row['donor'],
                'source_fund' => $row['source_fund'],
                'allotment_class' => $row['allotment_class'],
                'pm_plan' => $row['pm_plan'],
                'equipment_location' => $row['equipment_location'],
                'non_functional' => $row['non_functional'],
                'disposition_status' => $row['disposition_status'],
                'remarks' => $row['remarks'],
                'under_warranty' => $row['under_warranty'],
                'end_warranty_date' => $row['end_warranty_date'],
                'supplier' => $row['supplier'],
                'item_type_id' => $itemTypeId,
                'brand_id' => $brandId,
                'equipment_category_id' => $categoryId,
                'equipment_classification_id' => $classificationId,
                'equipment_condition_id' => $conditionId,
            ];

            $searchKey = $row['property_no'] !== null
                ? ['property_no' => $row['property_no']]
                : [
                    'property_no' => null,
                    'serial_number' => $row['serial_number'],
                    'model' => $row['model'],
                    'item_type_id' => $itemTypeId,
                    'specifications' => $row['specifications'],
                    'equipment_location' => $row['equipment_location'],
                ];

            $equipment = Equipment::firstOrCreate($searchKey, $attributes);

            if ($equipment->wasRecentlyCreated) {
                $inserted++;

                $accountableOfficerId = $this->resolveAccountableOfficerId($row);

                $accountability->recordTransaction($equipment, [
                    'transaction_type' => $row['transaction_type'],
                    'accountable_officer_id' => $accountableOfficerId,
                    'end_user_id' => null,
                    'received_by_id' => null,
                    'date_assigned_accountable_officer' => null,
                    'date_assigned_end_user' => null,
                    'date_received_new_accountable' => null,
                    'supporting_documents1' => $row['supporting_documents1'],
                    'or_si_dr_iar_no' => $row['or_si_dr_iar_no'],
                    'supporting_documents2' => $row['supporting_documents2'],
                    'par_ics_rrsp_rs_wmr_no' => $row['par_ics_rrsp_rs_wmr_no'],
                ]);
            } else {
                $skipped++;
            }
        }

        $this->command?->info(sprintf(
            'equipment: %d row(s) inserted, %d already present and left untouched (of %d total).',
            $inserted,
            $skipped,
            count($rows)
        ));
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @param  array<string, mixed>  $row
     */
    private function resolveId(string $modelClass, string $table, string $name, array $row): int
    {
        $id = $modelClass::where('name', $name)->value('id');

        if ($id === null) {
            throw new RuntimeException(sprintf(
                'equipment seed row [source_row=%s, property_no=%s]: value [%s] not found in %s table.',
                $row['source_row'],
                $row['property_no'] ?? 'null',
                $name,
                $table
            ));
        }

        return $id;
    }

    /**
     * 22 of 536 rows have no accountable_officer recorded (the same rows
     * that lack a transaction_type) — that's an expected, blank-in-source
     * state, so null is a valid resolution, not an error.
     *
     * Two-tier match, discovered necessary while running this seeder
     * against the real dataset (the task's pre-verification only spot-
     * checked full_name equality and missed this):
     *   1. Exact match against personnel.full_name — the primary path,
     *      resolves the overwhelming majority of rows.
     *   2. Fallback: 6 of 536 rows abbreviate the middle name to an
     *      initial in this column (e.g. "CHANTENGCO, HAIDIE L - 5426280"
     *      vs. personnel.full_name's "Chantengco, Haidie Lapid -
     *      5426280") — same person, same employee_id, just a shorter
     *      middle name in this sheet. Falls back to an employee_id match
     *      (extracted from the trailing " - EMPLOYEE_ID" segment),
     *      cross-checked against last_name as a sanity guard against a
     *      coincidental/malformed id matching the wrong person.
     *
     * A residual few (3 of 536: "NUNAG, CHRISTINE F - N/A", "PUNZALAN,
     * ADRIAN M - N/A", "GARCIA, MARIA LEONOVA - N/A") have employee_id
     * literally "N/A" and genuinely do not exist anywhere in the personnel
     * roster (confirmed — no personnel.employee_id is ever "N/A") — real
     * gaps in the source data, not a matching bug. Per CLAUDE.md "fail
     * safe, not silent": these resolve to null (identical in effect to the
     * legitimately-blank rows — accountable_officer_id is nullable) but
     * are surfaced as a loud seeder warning rather than swallowed, so a
     * human can decide whether to backfill the personnel roster later.
     *
     * @param  array<string, mixed>  $row
     */
    private function resolveAccountableOfficerId(array $row): ?int
    {
        $officer = $row['accountable_officer'];

        if ($officer === null) {
            return null;
        }

        $id = Personnel::where('full_name', $officer)->value('id');

        if ($id !== null) {
            return $id;
        }

        if (preg_match('/^(.*?),.* - ([^-]+)$/', $officer, $matches) === 1) {
            $lastName = trim($matches[1]);
            $employeeId = trim($matches[2]);

            $candidate = Personnel::where('employee_id', $employeeId)->first(['id', 'last_name']);

            if ($candidate !== null && mb_strtolower($candidate->last_name) === mb_strtolower($lastName)) {
                return $candidate->id;
            }
        }

        $this->command?->warn(sprintf(
            'equipment seed row [source_row=%s, property_no=%s]: accountable_officer [%s] not found in personnel table (by full_name or employee_id) — leaving accountable_officer_id null.',
            $row['source_row'],
            $row['property_no'] ?? 'null',
            $officer
        ));

        return null;
    }
}
