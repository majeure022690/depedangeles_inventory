<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * See create_psgc_provinces_table for the rationale behind this
     * table's shape. `municipality_id` is a real FK (RESTRICT on delete —
     * government reference data is never expected to lose its parent).
     */
    public function up(): void
    {
        Schema::create('psgc_barangays', function (Blueprint $table) {
            $table->id();
            $table->string('code', 9)->unique();
            $table->foreignId('municipality_id')->constrained('psgc_municipalities');
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psgc_barangays');
    }
};
