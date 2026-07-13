<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tier 1 dedicated entity table (lookup-normalization ADR, Question 1).
     * Paired concept with equipment_categories — same tier for consistency
     * (both are core Equipment taxonomy, always presented/filtered
     * together).
     *
     * Purely additive: `equipment.classification` (string) stays live
     * until Backend cuts the Equipment resource over in Step 2.
     */
    public function up(): void
    {
        Schema::create('equipment_classifications', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_classifications');
    }
};
