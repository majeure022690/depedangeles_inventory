<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 2 of the lookup-normalization ADR: Backend has cut IspAccount's
     * write path over to the new Tier 1 FK column (`isp_provider_id`,
     * added nullable in
     * 2026_07_11_010202_add_tier1_fk_column_to_isp_accounts_table). The
     * legacy string column it replaces is no longer populated by the app,
     * so its original NOT NULL constraint must be relaxed or every write
     * fails. Same problem, same fix as Equipment's five legacy columns in
     * 2026_07_11_010300_make_legacy_tier1_string_columns_nullable_on_equipment_table.
     *
     * Per the ADR's Step 5 rollout plan, this column is intentionally left
     * to go stale (unpopulated on new records) until Step 4 drops it
     * outright — this migration only changes nullability, nothing else.
     * The existing non-unique index on `isp` is left untouched; a nullable
     * column remains indexable.
     */
    public function up(): void
    {
        Schema::table('isp_accounts', function (Blueprint $table) {
            $table->string('isp')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Note: this will fail at the DB level if any row has NULL in
        // `isp` by the time it runs (expected once Backend's cutover has
        // been live for a while and new/updated rows stop populating it).
        // That's an acceptable, self-explanatory failure mode for a
        // rollback of a since-superseded write path, not a sign this
        // migration should be marked irreversible.
        Schema::table('isp_accounts', function (Blueprint $table) {
            $table->string('isp')->nullable(false)->change();
        });
    }
};
