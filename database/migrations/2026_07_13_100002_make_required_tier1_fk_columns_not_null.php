<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4 (cleanup) of the lookup-normalization ADR: enforces NOT NULL
     * on the Tier 1 FK columns whose original legacy string column was
     * itself required (not nullable) — restoring the same required/
     * optional shape the schema had before the cutover, now backed by a
     * real FK instead of a validated string.
     *
     * Checked against each original create-table migration (not assumed):
     *   - `equipment.item`, `brand_manufacturer`, `category`,
     *     `classification`, `equipment_condition` were all declared
     *     without ->nullable() in 2026_07_10_000003_create_equipment_table
     *     — i.e. required. Their FK replacements (item_type_id, brand_id,
     *     equipment_category_id, equipment_classification_id,
     *     equipment_condition_id) become NOT NULL here.
     *   - `isp_accounts.isp` was likewise required in
     *     2026_07_10_070151_create_isp_accounts_table. Its replacement
     *     (isp_provider_id) becomes NOT NULL here.
     *   - `personnel.position`, `ro_division`, `division_unit` were
     *     already ->nullable() in 2026_07_10_000002_create_personnel_table
     *     — their FK replacements (position_id, ro_office_id,
     *     sdo_office_id) are DELIBERATELY left nullable, matching the
     *     original schema's shape. Not touched by this migration.
     *
     * Safe to run against the real `inventory` database: per
     * ReferenceDataBackfillSeederTest's doc-comment, production currently
     * has 0 Equipment/Personnel/IspAccount rows, so there is no existing
     * NULL data that could violate the new constraint.
     */
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->foreignId('item_type_id')->nullable(false)->change();
            $table->foreignId('brand_id')->nullable(false)->change();
            $table->foreignId('equipment_category_id')->nullable(false)->change();
            $table->foreignId('equipment_classification_id')->nullable(false)->change();
            $table->foreignId('equipment_condition_id')->nullable(false)->change();
        });

        Schema::table('isp_accounts', function (Blueprint $table) {
            $table->foreignId('isp_provider_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->foreignId('item_type_id')->nullable()->change();
            $table->foreignId('brand_id')->nullable()->change();
            $table->foreignId('equipment_category_id')->nullable()->change();
            $table->foreignId('equipment_classification_id')->nullable()->change();
            $table->foreignId('equipment_condition_id')->nullable()->change();
        });

        Schema::table('isp_accounts', function (Blueprint $table) {
            $table->foreignId('isp_provider_id')->nullable()->change();
        });
    }
};
