<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Converts internet_connectivity_surveys from a global singleton to one
 * row per Office, mirroring stakeholder_profiles' own conversion. The one
 * existing row holds real survey answers but is discarded rather than
 * attached to a guessed office — an explicit product-owner call, since the
 * source data was never tied to a specific office.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('internet_connectivity_surveys')->delete();

        Schema::table('internet_connectivity_surveys', function (Blueprint $table) {
            $table->dropUnique(['singleton_guard']);
            $table->dropColumn('singleton_guard');

            $table->foreignId('office_id')->after('id')->constrained()->cascadeOnDelete();
            $table->unique('office_id');
        });
    }

    public function down(): void
    {
        Schema::table('internet_connectivity_surveys', function (Blueprint $table) {
            // dropUnique() first would fail here (MySQL uses this unique
            // index to satisfy the FK) — dropConstrainedForeignId() alone
            // drops the FK, then the column, taking the index with it.
            $table->dropConstrainedForeignId('office_id');

            $table->unsignedTinyInteger('singleton_guard')->default(1)->after('id');
            $table->unique('singleton_guard');
        });
    }
};
