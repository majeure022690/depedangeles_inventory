<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Converts `stakeholder_profiles` from a true global singleton (exactly one
 * row for the whole app) to one row PER OFFICE (school/division-level unit)
 * — each office now has its own stakeholder profile, shared by every user
 * whose `office_id` matches it, following the addition of the `offices`
 * table.
 *
 * `singleton_guard` enforced "exactly one row globally"; that invariant no
 * longer applies; it's replaced by a unique index on `office_id` itself
 * (MySQL/MariaDB unique indexes allow unlimited NULLs, so this still only
 * enforces "at most one row per NON-NULL office_id" — every row the app
 * creates going forward always has a concrete office_id, so this is exactly
 * the guarantee needed).
 *
 * The one existing row (the old global singleton, created empty by
 * firstOrCreate([]) with no office_id and never actually filled in by
 * anyone) is deleted outright rather than migrated to a specific office —
 * there's no real data to preserve and no way to know which office it was
 * ever meant to represent. That also means the table is empty by the time
 * `office_id` is added, so it can be NOT NULL from the start — every row
 * this app creates going forward always belongs to a specific office.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('stakeholder_profiles')->delete();

        Schema::table('stakeholder_profiles', function (Blueprint $table) {
            $table->dropUnique(['singleton_guard']);
            $table->dropColumn('singleton_guard');

            $table->foreignId('office_id')->after('id')->constrained()->cascadeOnDelete();
            $table->unique('office_id');
        });
    }

    public function down(): void
    {
        Schema::table('stakeholder_profiles', function (Blueprint $table) {
            $table->dropUnique(['office_id']);
            $table->dropConstrainedForeignId('office_id');

            $table->unsignedTinyInteger('singleton_guard')->default(1)->after('id');
            $table->unique('singleton_guard');
        });
    }
};
