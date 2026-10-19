<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tier 1 dedicated entity table (lookup-normalization ADR, Question 1).
     * Has a dedicated query scope (Equipment::scopeCondition); serviceable/
     * unserviceable counts are a standard government inventory reporting
     * metric — worth the FK even at only 4 rows.
     *
     * Purely additive: `equipment.equipment_condition` (string) stays live
     * until Backend cuts the Equipment resource over in Step 2.
     */
    public function up(): void
    {
        Schema::create('equipment_conditions', function (Blueprint $table) {
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
        Schema::dropIfExists('equipment_conditions');
    }
};
