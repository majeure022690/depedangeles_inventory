<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Office CRUD is getting a delete UI: silently nulling `users.office_id`
 * (nullOnDelete) or wiping out an office's stakeholder profile / connectivity
 * survey (cascadeOnDelete) is fine for a seed-only table but not once an
 * admin can delete real data by mistake. Switch all three to
 * restrictOnDelete(), matching Tier 1 reference-data's guarded-delete UX —
 * dependents must be reassigned/cleared first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['office_id']);
            $table->foreign('office_id')->references('id')->on('offices')->restrictOnDelete();
        });

        Schema::table('stakeholder_profiles', function (Blueprint $table) {
            $table->dropForeign(['office_id']);
            $table->foreign('office_id')->references('id')->on('offices')->restrictOnDelete();
        });

        Schema::table('internet_connectivity_surveys', function (Blueprint $table) {
            $table->dropForeign(['office_id']);
            $table->foreign('office_id')->references('id')->on('offices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['office_id']);
            $table->foreign('office_id')->references('id')->on('offices')->nullOnDelete();
        });

        Schema::table('stakeholder_profiles', function (Blueprint $table) {
            $table->dropForeign(['office_id']);
            $table->foreign('office_id')->references('id')->on('offices')->cascadeOnDelete();
        });

        Schema::table('internet_connectivity_surveys', function (Blueprint $table) {
            $table->dropForeign(['office_id']);
            $table->foreign('office_id')->references('id')->on('offices')->cascadeOnDelete();
        });
    }
};
