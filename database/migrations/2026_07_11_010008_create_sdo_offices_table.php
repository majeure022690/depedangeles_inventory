<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tier 1 dedicated entity table (lookup-normalization ADR, Question 1).
     * Same reasoning as ro_offices — an actual organizational office, not
     * a descriptive adjective.
     *
     * Purely additive: `personnel.division_unit` (string) stays live until
     * Backend cuts the Personnel resource over in Step 2.
     */
    public function up(): void
    {
        Schema::create('sdo_offices', function (Blueprint $table) {
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
        Schema::dropIfExists('sdo_offices');
    }
};
