<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OfficeStoreRequest/OfficeUpdateRequest already validate office_name
 * uniqueness, but that's a pre-write check with no row lock — a real DB
 * constraint closes the race two concurrent requests could otherwise slip
 * through. Confirmed no duplicate office_name values exist in the current
 * seeded data before adding this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->unique('office_name');
        });
    }

    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->dropUnique(['office_name']);
        });
    }
};
