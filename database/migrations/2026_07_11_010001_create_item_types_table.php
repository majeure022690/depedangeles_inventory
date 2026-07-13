<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tier 1 dedicated entity table (lookup-normalization ADR,
     * docs/architecture-decisions/lookup-normalization.md, Question 1).
     * Genuine reusable Equipment identity field (65 rows) — earns a real
     * FK target instead of living in a domain-grouped library table.
     *
     * Purely additive: `equipment.item` (string) stays live until Backend
     * cuts the Equipment resource over in Step 2. This table has no
     * consumer yet.
     */
    public function up(): void
    {
        Schema::create('item_types', function (Blueprint $table) {
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
        Schema::dropIfExists('item_types');
    }
};
