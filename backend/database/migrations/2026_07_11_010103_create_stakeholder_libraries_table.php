<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tier 2 domain-grouped library table (lookup-normalization ADR,
     * Question 1). Scoped to the StakeholderProfile bounded context:
     * governance_level, community_context, type_of_access_road,
     * by_transportation, nearby_institution, community_engagement,
     * source_of_electricity (7 App\Enums\StakeholderLibraryType cases —
     * see that enum). Also feeds InternetConnectivitySurvey.
     * electricity_sources (source_of_electricity), per Question 4 of the
     * ADR.
     *
     * Identical column shape to today's `lookups` table, just domain-
     * scoped. Purely additive: the old `lookups` table and every old
     * string/JSON column stay live until Backend cuts the relevant
     * resources over in Step 2.
     */
    public function up(): void
    {
        Schema::create('stakeholder_libraries', function (Blueprint $table) {
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
        Schema::dropIfExists('stakeholder_libraries');
    }
};
