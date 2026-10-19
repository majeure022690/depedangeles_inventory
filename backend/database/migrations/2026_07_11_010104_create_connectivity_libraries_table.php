<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tier 2 domain-grouped library table (lookup-normalization ADR,
     * Question 1). Scoped to the ISP/connectivity bounded context:
     * isp_connection_type, subscription_type, purpose_of_subscription,
     * signal_quality, mobile_network_signal, coverage_area,
     * school_level_coverage (7 App\Enums\ConnectivityLibraryType cases —
     * see that enum).
     *
     * `school_level_coverage` is DELIBERATELY here, not in
     * stakeholder_libraries — the old LookupType enum's comment grouped it
     * under "Organizational / stakeholder context," but its only real
     * consumer is `isp_accounts.school_area_coverage`. This is a deliberate
     * ADR correction on actual-usage grounds, not an oversight (see the
     * ADR's Question 1 section).
     *
     * Identical column shape to today's `lookups` table, just domain-
     * scoped. Purely additive: the old `lookups` table and every old
     * string/JSON column stay live until Backend cuts the relevant
     * resources over in Step 2.
     */
    public function up(): void
    {
        Schema::create('connectivity_libraries', function (Blueprint $table) {
            $table->id();
            $table->string('type', 64);
            $table->string('value');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'value']);
            $table->index(['type', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connectivity_libraries');
    }
};
