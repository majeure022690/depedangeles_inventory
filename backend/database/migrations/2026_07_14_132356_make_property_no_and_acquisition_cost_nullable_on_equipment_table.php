<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permanent data-model decision (not a one-time import accommodation):
     * the source division office's real asset register legitimately has
     * equipment awaiting property-number issuance from the Asset
     * Management Office, and a similar chunk with acquisition cost not
     * yet on file — the spreadsheet's own instructions document this as a
     * normal pending state (~19% of a 536-row real sample lacked
     * property_no). Staff need to register equipment in that "pending"
     * state going forward, not just during a historical backfill.
     *
     * `property_no` keeps its existing plain `->unique()` index from
     * 2026_07_10_000003_create_equipment_table (confirmed there — not a
     * composite/partial index, nothing that would behave differently
     * under multiple NULLs). MySQL's unique index treats NULL as "no
     * value to compare," so it allows unlimited NULL rows while still
     * rejecting any two rows that share the same assigned property
     * number. No index change is needed to get "many pending, no
     * duplicate assigned numbers" — nullable + the pre-existing unique
     * constraint already means exactly that.
     *
     * Checked for other non-null assumptions on these two columns before
     * this change:
     *   - `qr_code` (same table) is derived from the record's `id` via
     *     route('equipment.edit', $equipment) in
     *     App\Observers\EquipmentObserver, not from property_no — QR
     *     generation isn't built yet and won't choke on a null
     *     property_no when it is.
     *   - Equipment::casts()'s `acquisition_cost => 'decimal:2'` is
     *     null-safe: Eloquent's castAttribute() short-circuits to `null`
     *     for primitive cast types (which include 'decimal') before
     *     touching the decimal-formatting logic, so no accessor/cast
     *     changes are needed here.
     *   - No composite index or generated column references either
     *     column elsewhere in the schema.
     */
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->string('property_no')->nullable()->change();
            $table->decimal('acquisition_cost', 12, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Note: like 2026_07_11_010300's down(), this will fail at the DB
     * level if any row has NULL in either column by the time it runs
     * (expected once "pending" equipment has been registered in
     * production). That's an acceptable, self-explanatory failure mode
     * for rolling back a deliberate, permanent schema decision — not a
     * sign this migration should be marked irreversible.
     */
    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->string('property_no')->nullable(false)->change();
            $table->decimal('acquisition_cost', 12, 2)->nullable(false)->change();
        });
    }
};
