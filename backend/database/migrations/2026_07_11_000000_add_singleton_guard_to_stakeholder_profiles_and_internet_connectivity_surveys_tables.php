<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SECURITY FIX — closes a race-condition gap flagged in review:
     * `stakeholder_profiles` and `internet_connectivity_surveys` are
     * singleton tables (exactly one row should ever exist) fetched/created
     * via `Model::firstOrCreate([])` in their controllers and Form
     * Requests. Before this migration, neither table had any unique
     * constraint beyond the auto-increment `id`, so two concurrent
     * requests hitting an empty table (e.g. two admins loading the edit
     * page for the first time within milliseconds of each other) could
     * both pass the SELECT half of firstOrCreate and both INSERT a row —
     * nothing at the DB level prevented a second row.
     *
     * Laravel's `firstOrCreate()`/`createOrFirst()` already contains a
     * retry-on-duplicate-key path (see
     * `Illuminate\Database\Eloquent\Builder::createOrFirst()`): if the
     * INSERT throws a `UniqueConstraintViolationException`, it re-runs the
     * SELECT and returns the row the other request inserted, instead of
     * blowing up or creating a duplicate. That retry path only engages if
     * a real unique constraint exists — which is what this migration adds.
     *
     * `singleton_guard` is an internal DB-level guard column, not
     * application/business data:
     *   - `unsignedTinyInteger` with a hard-coded `default(1)` means every
     *     existing INSERT (via firstOrCreate([]) or anything else) gets the
     *     same value automatically — no application code changes needed,
     *     and Backend never sets it manually.
     *   - The `unique()` index means a second concurrent INSERT collides on
     *     this column and throws UniqueConstraintViolationException,
     *     engaging Laravel's retry-and-return-existing-row path.
     *   - Deliberately NOT boolean: MySQL's BOOLEAN is just TINYINT(1), so
     *     this is functionally identical to the reviewer's
     *     `boolean('singleton_lock')` suggestion — named `singleton_guard`
     *     /`unsignedTinyInteger` here to read clearly as "a fixed sentinel
     *     value used only to trip a unique index," not a true/false flag
     *     with two meaningful states.
     *
     * Deliberately additive: does not modify the original
     * create_stakeholder_profiles_table / create_internet_connectivity_
     * surveys_table migrations, and touches no other table.
     */
    public function up(): void
    {
        Schema::table('stakeholder_profiles', function (Blueprint $table) {
            $table->unsignedTinyInteger('singleton_guard')->default(1)->after('id');
            $table->unique('singleton_guard');
        });

        Schema::table('internet_connectivity_surveys', function (Blueprint $table) {
            $table->unsignedTinyInteger('singleton_guard')->default(1)->after('id');
            $table->unique('singleton_guard');
        });
    }

    public function down(): void
    {
        Schema::table('stakeholder_profiles', function (Blueprint $table) {
            $table->dropUnique(['singleton_guard']);
            $table->dropColumn('singleton_guard');
        });

        Schema::table('internet_connectivity_surveys', function (Blueprint $table) {
            $table->dropUnique(['singleton_guard']);
            $table->dropColumn('singleton_guard');
        });
    }
};
