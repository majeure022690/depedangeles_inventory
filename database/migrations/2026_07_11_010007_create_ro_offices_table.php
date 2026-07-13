<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tier 1 dedicated entity table (lookup-normalization ADR, Question 1).
     * Organizational entity (an actual Regional Office), not a descriptive
     * adjective.
     *
     * Purely additive: `personnel.ro_division` (string) stays live until
     * Backend cuts the Personnel resource over in Step 2.
     */
    public function up(): void
    {
        Schema::create('ro_offices', function (Blueprint $table) {
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
        Schema::dropIfExists('ro_offices');
    }
};
