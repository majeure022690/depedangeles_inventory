<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4 (cleanup) of the lookup-normalization ADR
     * (docs/architecture-decisions/lookup-normalization.md): drops the 8
     * legacy string columns that the Tier 1 FK columns replaced. All 5
     * consuming resources (Equipment, Personnel, IspAccount,
     * StakeholderProfile, InternetConnectivitySurvey) have been fully cut
     * over and QA/Security/Reviewer-signed-off; these columns have been
     * going stale (unpopulated on new/updated records, per the Step 5
     * rollout plan) since each resource's Backend cutover and are safe to
     * remove outright.
     *
     * `equipment`: item, brand_manufacturer, category, classification,
     * equipment_condition (replaced by item_type_id/brand_id/
     * equipment_category_id/equipment_classification_id/
     * equipment_condition_id).
     * `personnel`: position, ro_division, division_unit (replaced by
     * position_id/ro_office_id/sdo_office_id).
     * `isp_accounts`: isp (replaced by isp_provider_id).
     *
     * Indexes that existed solely on these columns are dropped explicitly
     * first — relying on MySQL's implicit "drop a single/composite-index
     * column and the index shrinks or disappears" behavior would work, but
     * doing it explicitly keeps this migration's down() a complete,
     * self-documenting inverse rather than depending on that implicit
     * behavior. `equipment`'s composite index(['category',
     * 'equipment_condition']) is dropped as a whole since both of its
     * columns are being removed; `disposition_status` and `serial_number`
     * keep their own indexes untouched (those columns aren't going away).
     *
     * IRREVERSIBLE DATA LOSS ON down(): down() restores the columns
     * (nullable, matching their state immediately before this migration —
     * see 2026_07_11_010300/2026_07_11_010301) and their indexes, but the
     * original string *values* are gone the moment up() runs. This is the
     * expected, accepted end state per the ADR's Step 4 — not a defect in
     * this migration. A rollback restores empty (NULL) columns, not
     * historical data.
     */
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->dropIndex(['equipment_condition']);
            $table->dropIndex(['category']);
            $table->dropIndex(['category', 'equipment_condition']);
            $table->dropIndex(['item']);

            $table->dropColumn([
                'item',
                'brand_manufacturer',
                'category',
                'classification',
                'equipment_condition',
            ]);
        });

        Schema::table('personnel', function (Blueprint $table) {
            $table->dropIndex(['position']);

            $table->dropColumn([
                'position',
                'ro_division',
                'division_unit',
            ]);
        });

        Schema::table('isp_accounts', function (Blueprint $table) {
            $table->dropIndex(['isp']);

            $table->dropColumn('isp');
        });
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->string('item')->nullable()->after('serial_number');
            $table->string('brand_manufacturer')->nullable()->after('model');
            $table->string('category')->nullable()->after('dcp_year');
            $table->string('classification')->nullable()->after('category');
            $table->string('equipment_condition')->nullable()->after('non_functional');
        });

        Schema::table('equipment', function (Blueprint $table) {
            $table->index('equipment_condition');
            $table->index('category');
            $table->index(['category', 'equipment_condition']);
            $table->index('item');
        });

        Schema::table('personnel', function (Blueprint $table) {
            $table->string('ro_division')->nullable()->after('full_name');
            $table->string('division_unit')->nullable()->after('ro_division');
            $table->string('position')->nullable()->after('division_unit');
        });

        Schema::table('personnel', function (Blueprint $table) {
            $table->index('position');
        });

        Schema::table('isp_accounts', function (Blueprint $table) {
            $table->string('isp')->nullable()->after('id');
        });

        Schema::table('isp_accounts', function (Blueprint $table) {
            $table->index('isp');
        });
    }
};
