<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row = one ISP subscription/connection (division office, school,
     * or admin area link). Speed-test history and cost/budget tracking
     * live in the child tables isp_speed_tests / isp_subscription_costs.
     *
     * Lookup-backed columns are plain VARCHAR values matching
     * `lookups.value` for the corresponding App\Enums\LookupType, NOT
     * foreign keys to lookups.id — see the migration-level note in
     * create_equipment_table for the full rationale (kept once there to
     * avoid repeating it on every table). Several of these lookup types
     * (mode_of_acquisition, source_of_acquisition, source_of_funds,
     * signal_quality, school_level_coverage) are reused as-is from
     * Equipment/the org-context group rather than duplicated.
     *
     * SCHEMA DECISION — isp_billing_account_no is intentionally NOT
     * unique. It's the natural identifier the source workbook uses to
     * visually group rows, but different ISPs can reuse account-number
     * formats and nothing here guarantees global uniqueness — the row
     * itself (this table's surrogate id) is the real identity. It's
     * indexed (non-unique) because it's a common search/lookup key.
     *
     * SCHEMA DECISION — isp_connection_types (source column name, plural)
     * is stored as a single VARCHAR `isp_connection_type` (singular).
     * It's a single-select in practice despite the workbook's plural
     * column header, per the source data review.
     */
    public function up(): void
    {
        Schema::create('isp_accounts', function (Blueprint $table) {
            $table->id();

            $table->string('isp'); // lookup type: isp_provider
            $table->decimal('cost_per_month', 10, 2);
            $table->string('isp_billing_account_no');
            $table->text('package_purchased_inclusion');

            $table->string('school_area_coverage'); // lookup type: school_level_coverage
            $table->decimal('min_speed', 8, 2); // Mbps
            $table->decimal('max_speed', 8, 2); // Mbps

            $table->string('subscription_type'); // lookup type: subscription_type (Prepaid/Postpaid)
            $table->string('isp_connection_type'); // lookup type: isp_connection_type

            $table->date('contract_start_date')->nullable();
            $table->date('contract_end_date')->nullable();
            $table->boolean('inactive_contract')->default(false);

            $table->string('purpose_of_subscription'); // lookup type: purpose_of_subscription

            $table->string('mode_of_acquisition'); // lookup type: mode_of_acquisition (reused from Equipment)
            $table->string('source_of_acquisition'); // lookup type: source_of_acquisition (reused from Equipment)
            $table->string('donor')->nullable(); // only relevant when mode_of_acquisition = Donation
            $table->string('fund_source'); // lookup type: source_of_funds (reused from Equipment)

            $table->unsignedInteger('number_access_points_linked')->nullable();
            $table->text('locations_access_points')->nullable();

            $table->unsignedInteger('number_admin_area_covered')->nullable();
            $table->string('rate_connectivity_admin_areas')->nullable(); // lookup type: signal_quality
            $table->unsignedInteger('number_classrooms_covered')->nullable();
            $table->string('rate_connectivity_classroom')->nullable(); // lookup type: signal_quality

            $table->string('overall_signal_quality'); // lookup type: signal_quality
            // Star rating (1-5), NOT lookup-backed — a small ad hoc scale,
            // not a shared vocabulary list.
            $table->unsignedTinyInteger('rate_isp_service')->nullable();

            $table->timestamps();
            // Genuine business need: ISP subscriptions are government
            // budget/procurement records (cost_per_month, contract dates,
            // fund_source) that must remain retrievable for COA/budget
            // audit history even after a connection is decommissioned —
            // same rationale as Equipment. inactive_contract covers the
            // "no longer active" business state; softDeletes covers
            // outright record removal while preserving the audit trail.
            $table->softDeletes();

            $table->index('isp_billing_account_no');
            $table->index('isp');
            $table->index('subscription_type');
            $table->index('inactive_contract');
            $table->index('contract_end_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('isp_accounts');
    }
};
