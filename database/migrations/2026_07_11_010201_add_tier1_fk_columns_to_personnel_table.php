<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 1 of the lookup-normalization ADR (Question 5 rollout plan):
     * additive-only new FK columns, nullable for now, alongside the
     * still-live old string columns (`position`, `ro_division`,
     * `division_unit`). Backend has not cut the Personnel resource's
     * read/write paths over yet.
     *
     * `restrictOnDelete()`/`restrictOnUpdate()`: see the matching note on
     * the equipment FK migration — reference-data rows are retired via
     * `is_active = false`, never deleted out from under a still-referenced
     * Personnel record.
     *
     * SQLITE-ONLY CARVE-OUT — root-caused by hand (isolated, reproducible
     * minimal test cases, not guessed): `personnel.full_name` is a MySQL
     * STORED generated column whose expression uses `if(...)`. Adding a
     * foreign-key-CONSTRAINED column via Schema::table() forces Laravel's
     * SQLite grammar to rebuild the whole table (copy into a temp table,
     * drop, rename) — SQLite itself has no ALTER TABLE ADD COLUMN path for
     * a column with a REFERENCES clause otherwise. That rebuild loses the
     * STORED generation expression specifically when it contains `if(...)`
     * (confirmed: a plain `concat()` expression survives the same rebuild
     * intact; only adding `if(...)` back in reproduces the loss). The
     * column silently degrades to an always-NULL plain column, breaking
     * every test reading `full_name`. A plain (unconstrained) column add
     * does NOT trigger the rebuild and leaves `full_name` untouched.
     *
     * This is a SQLite/test-only artifact — MySQL's ALTER TABLE ADD COLUMN
     * never rebuilds unrelated columns, so production integrity is
     * unaffected. The fix: add the columns everywhere, but only attach the
     * real FK constraint when the connection isn't sqlite. Real
     * referential integrity is enforced on every driver that matters
     * (MySQL, in production and any local/CI MySQL run); sqlite (test
     * suite only) gets the same nullable columns without the DB-level
     * constraint, which the app-layer Form Request validation (Step 2)
     * covers regardless.
     */
    public function up(): void
    {
        Schema::table('personnel', function (Blueprint $table) {
            $table->foreignId('position_id')->nullable();
            $table->foreignId('ro_office_id')->nullable();
            $table->foreignId('sdo_office_id')->nullable();
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('personnel', function (Blueprint $table) {
            $table->foreign('position_id')->references('id')->on('positions')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('ro_office_id')->references('id')->on('ro_offices')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('sdo_office_id')->references('id')->on('sdo_offices')
                ->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('personnel', function (Blueprint $table) {
                $table->dropColumn(['position_id', 'ro_office_id', 'sdo_office_id']);
            });

            return;
        }

        Schema::table('personnel', function (Blueprint $table) {
            $table->dropConstrainedForeignId('position_id');
            $table->dropConstrainedForeignId('ro_office_id');
            $table->dropConstrainedForeignId('sdo_office_id');
        });
    }
};
