<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tier 2 domain-grouped library table (lookup-normalization ADR,
     * Question 1). Small, closed vocabularies with no independent
     * identity of their own, scoped to the Equipment bounded context:
     * unit_of_measure, disposition_status, dcp_package, mode_of_acquisition,
     * source_of_acquisition, source_of_funds, allotment_class,
     * supporting_document_type, transaction_type (9 App\Enums\
     * EquipmentLibraryType cases — see that enum).
     *
     * Identical column shape to today's `lookups` table, just domain-
     * scoped — consumer columns stay validated strings (no FK), same
     * pattern as today. Cross-domain reads (e.g. IspAccount reading
     * mode_of_acquisition/source_of_acquisition/source_of_funds,
     * StakeholderProfile reading transaction_type) are expected and fine
     * per the ADR — the table name denotes primary steward, not an access
     * boundary.
     *
     * Purely additive: the old `lookups` table and every old string
     * column stay live until Backend cuts each resource over in Step 2.
     */
    public function up(): void
    {
        Schema::create('equipment_libraries', function (Blueprint $table) {
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
        Schema::dropIfExists('equipment_libraries');
    }
};
