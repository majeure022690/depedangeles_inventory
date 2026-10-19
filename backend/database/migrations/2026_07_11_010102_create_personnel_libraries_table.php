<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tier 2 domain-grouped library table (lookup-normalization ADR,
     * Question 1). Scoped to the Personnel bounded context: name_suffix,
     * teachers_funding_source, cause_of_separation (3 App\Enums\
     * PersonnelLibraryType cases — see that enum).
     *
     * Identical column shape to today's `lookups` table, just domain-
     * scoped. Purely additive: the old `lookups` table and every old
     * string column stay live until Backend cuts Personnel over in
     * Step 2.
     */
    public function up(): void
    {
        Schema::create('personnel_libraries', function (Blueprint $table) {
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
        Schema::dropIfExists('personnel_libraries');
    }
};
