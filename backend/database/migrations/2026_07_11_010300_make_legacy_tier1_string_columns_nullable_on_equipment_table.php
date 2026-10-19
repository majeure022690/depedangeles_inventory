<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 2 of the lookup-normalization ADR: Backend has cut Equipment's
     * write path over to the new Tier 1 FK columns (`item_type_id`,
     * `brand_id`, `equipment_category_id`, `equipment_classification_id`,
     * `equipment_condition_id`, added nullable in
     * 2026_07_11_010200_add_tier1_fk_columns_to_equipment_table). The five
     * legacy string columns they replace are no longer populated by the
     * app, so their original NOT NULL constraints must be relaxed or every
     * write fails.
     *
     * Per the ADR's Step 5 rollout plan, these columns are intentionally
     * left to go stale (unpopulated on new records) until Step 4 drops
     * them outright — this migration only changes nullability, nothing
     * else. Existing indexes on these columns are left untouched; a
     * nullable column remains indexable.
     */
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->string('item')->nullable()->change();
            $table->string('brand_manufacturer')->nullable()->change();
            $table->string('category')->nullable()->change();
            $table->string('classification')->nullable()->change();
            $table->string('equipment_condition')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Note: this will fail at the DB level if any row has NULL in one
        // of these columns by the time it runs (expected once Backend's
        // cutover has been live for a while and new/updated rows stop
        // populating them). That's an acceptable, self-explanatory failure
        // mode for a rollback of a since-superseded write path, not a sign
        // this migration should be marked irreversible.
        Schema::table('equipment', function (Blueprint $table) {
            $table->string('item')->nullable(false)->change();
            $table->string('brand_manufacturer')->nullable(false)->change();
            $table->string('category')->nullable(false)->change();
            $table->string('classification')->nullable(false)->change();
            $table->string('equipment_condition')->nullable(false)->change();
        });
    }
};
