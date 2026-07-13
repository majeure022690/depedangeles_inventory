<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Pins the DB-level FK constraint that
 * `2026_07_11_010201_add_tier1_fk_columns_to_personnel_table.php` attaches
 * to `personnel.position_id` / `ro_office_id` / `sdo_office_id` on every
 * driver except sqlite.
 *
 * WHY THIS CAN'T BE A FUNCTIONAL TEST LIKE ItemTypeReferentialIntegrityTest:
 * this project's test suite runs on sqlite (see phpunit.xml, DB_CONNECTION=
 * sqlite). Equipment's identical constraint is functionally testable
 * (attempt a delete, assert QueryException) because Equipment's Tier 1 FK
 * migration attaches its constraint unconditionally on every driver,
 * including sqlite. Personnel's migration deliberately does NOT — per its
 * own doc-comment, adding a foreign-key-CONSTRAINED column via
 * Schema::table() forces SQLite's grammar to rebuild the whole `personnel`
 * table, which corrupts the STORED generated `full_name` column (a
 * root-caused, reproduced SQLite/Laravel-grammar interaction, not a
 * guess — see the migration for the isolated repro). So on sqlite the
 * migration adds the columns WITHOUT the constraint, and a live
 * `$model->delete()` on this suite's sqlite connection would succeed
 * whether or not the migration's MySQL branch is correct — a functional
 * test here would pass even if someone silently dropped the
 * restrictOnDelete()/restrictOnUpdate() calls, which is worse than no test.
 *
 * Instead, this test statically inspects the migration source and asserts
 * the MySQL-only branch (the code path guarded by
 * `if (... driverName() === 'sqlite') { return; }`) still attaches
 * restrictOnDelete()/restrictOnUpdate() to all three Tier 1 columns. It
 * can't prove the constraint actually blocks a delete at the database
 * level (only a real MySQL run — e.g. CI or a local MySQL connection —
 * could prove that), but it does prove the intended protection hasn't been
 * silently removed or weakened from the migration source, which is the
 * failure mode this test exists to catch.
 *
 * Do NOT "fix" this into a RefreshDatabase + $model->delete() functional
 * test — that would silently test nothing, since sqlite never attaches the
 * constraint in the first place.
 */
class PersonnelTier1ForeignKeyMigrationTest extends TestCase
{
    private const MIGRATION_PATH = 'database/migrations/2026_07_11_010201_add_tier1_fk_columns_to_personnel_table.php';

    public function test_mysql_branch_attaches_restrict_on_delete_and_update_for_all_tier1_columns(): void
    {
        $path = base_path(self::MIGRATION_PATH);

        $this->assertFileExists(
            $path,
            'Personnel Tier 1 FK migration file not found at the expected path — '
            .'if it was renamed, update MIGRATION_PATH in this test to match.'
        );

        $source = file_get_contents($path);

        // Isolate the up() method's post-sqlite-early-return branch, i.e.
        // everything after the sqlite guard clause, which is the MySQL
        // (and every other non-sqlite driver) code path.
        $sqliteGuardPosition = strpos($source, "getDriverName() === 'sqlite'");
        $this->assertNotFalse(
            $sqliteGuardPosition,
            'Expected the migration to still contain the documented sqlite driver-name guard clause.'
        );

        $mysqlBranch = substr($source, $sqliteGuardPosition);

        foreach (['position_id', 'ro_office_id', 'sdo_office_id'] as $column) {
            $this->assertMatchesRegularExpression(
                '/foreign\(\''.preg_quote($column, '/').'\'\).*?restrictOnDelete\(\).*?restrictOnUpdate\(\)/s',
                $mysqlBranch,
                "Expected the non-sqlite branch to attach a foreign('{$column}') constraint "
                .'with both restrictOnDelete() and restrictOnUpdate() — reference-data rows '
                .'(positions/RO offices/SDO offices) must never be deletable out from under a '
                .'still-referenced Personnel record.'
            );
        }
    }
}
