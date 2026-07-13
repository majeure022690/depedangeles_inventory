<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SINGLETON TABLE — exactly one row should ever exist (one Division
     * Office connectivity survey, mirroring the source workbook's single
     * "Internet Connectivity" sheet, a 27-question survey). Same singleton
     * treatment as stakeholder_profiles (see that migration for the full
     * rationale on nullability, no soft deletes, no factory, no
     * speculative indexes, and Backend's firstOrCreate([]) pattern).
     *
     * NON-DUPLICATION — this table deliberately does NOT store: total ISPs,
     * total cost/month, total amount spent, total projected expenditure,
     * rooms covered (admin/classroom), or total access points. The source
     * sheet marks these "Protected, source data from..." — i.e. they are
     * computed/derived from the isp_accounts / isp_subscription_costs
     * tables and MUST be computed live by a query/scope on those tables,
     * never duplicated here. Backend needs isp_accounts (cost_per_month,
     * number_access_points_linked, number_admin_area_covered,
     * number_classrooms_covered) and isp_subscription_costs to build those
     * display-only aggregate fields — there is no FK from this table to
     * either; the relationship is "query them separately when rendering
     * the survey," not a foreign key.
     *
     * The one exception is rooms_other_use, which IS user-entered free
     * text in the source (not protected), so it gets a real column here.
     *
     * Lookup-backed columns are plain VARCHAR values matching
     * `lookups.value` for the corresponding App\Enums\LookupType, NOT
     * foreign keys to lookups.id — see the migration-level note in
     * create_equipment_table for the full rationale.
     */
    public function up(): void
    {
        Schema::create('internet_connectivity_surveys', function (Blueprint $table) {
            $table->id();

            $table->boolean('has_isp_in_area')->nullable();

            // lookup type: isp_provider (includes DICT and Others, already
            // seeded — no lookup changes were needed for this field).
            $table->json('available_isps')->nullable();
            $table->string('available_isps_other')->nullable();

            // lookup type: mobile_network_signal (new — see LookupType)
            $table->json('mobile_signal_types')->nullable();

            $table->boolean('has_mobile_data_connectivity')->nullable();
            $table->string('mobile_data_quality')->nullable(); // lookup type: signal_quality

            $table->boolean('subscribes_to_isp')->nullable();
            $table->json('subscribed_isps')->nullable(); // lookup type: isp_provider (same list, DICT already included)

            $table->text('insufficient_bandwidth_explanation')->nullable();

            // lookup type: coverage_area (new — see LookupType)
            $table->json('coverage_areas')->nullable();
            $table->string('coverage_areas_other')->nullable();

            $table->boolean('dict_free_wifi_recipient')->nullable();
            // Star rating (1-5), NOT lookup-backed — same treatment as
            // isp_accounts.rate_isp_service (a small ad hoc scale, not a
            // shared vocabulary list).
            $table->unsignedTinyInteger('dict_free_wifi_rating')->nullable();

            $table->boolean('has_sufficient_bandwidth')->nullable();
            $table->text('no_subscription_reason')->nullable();

            $table->boolean('has_electricity_source')->nullable();
            $table->json('electricity_sources')->nullable(); // lookup type: source_of_electricity
            $table->boolean('primarily_solar_powered')->nullable();
            $table->boolean('frequent_brownouts')->nullable();

            // User-entered free text in the source sheet (unlike the other
            // "rooms covered" figures, which are protected/derived — see
            // class-level note above).
            $table->unsignedInteger('rooms_other_use')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internet_connectivity_surveys');
    }
};
