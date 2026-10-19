<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time data conversion for the app timezone change UTC -> Asia/Manila
 * (commit 436b336). Every timestamp the system wrote before that change
 * holds UTC wall-clock, so it is shifted forward 8 hours. Manila has no DST,
 * so a fixed offset is exact.
 *
 * The list is hard-coded from information_schema as of 2026-10-19, not
 * discovered at run time, so a later column can never be shifted by
 * accident. Deliberately excluded (user-entered Manila wall-clock, already
 * correct): isp_speed_tests.tested_at, stakeholder_profiles.submitted_at,
 * and every DATE column. Integer unix-time columns (sessions, cache, jobs,
 * job_batches) are timezone-independent and untouched.
 *
 * activity_log is otherwise append-only; this owner-approved shift is a
 * second sanctioned exception alongside the audit_logs copy.
 */
return new class extends Migration
{
    private const HOURS = 8;

    /**
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'activity_log' => ['created_at', 'updated_at'],
        'audit_logs' => ['created_at'],
        'brands' => ['created_at', 'updated_at'],
        'connectivity_libraries' => ['created_at', 'updated_at'],
        'equipment' => ['created_at', 'updated_at', 'deleted_at'],
        'equipment_categories' => ['created_at', 'updated_at'],
        'equipment_classifications' => ['created_at', 'updated_at'],
        'equipment_conditions' => ['created_at', 'updated_at'],
        'equipment_libraries' => ['created_at', 'updated_at'],
        'equipment_transactions' => ['created_at', 'updated_at'],
        'failed_jobs' => ['failed_at'],
        'internet_connectivity_surveys' => ['created_at', 'updated_at'],
        'isp_accounts' => ['created_at', 'updated_at', 'deleted_at'],
        'isp_providers' => ['created_at', 'updated_at'],
        'isp_speed_tests' => ['created_at', 'updated_at'],
        'isp_subscription_costs' => ['created_at', 'updated_at'],
        'item_types' => ['created_at', 'updated_at'],
        'offices' => ['created_at', 'updated_at'],
        'password_reset_tokens' => ['created_at'],
        'permissions' => ['created_at', 'updated_at'],
        'personal_access_tokens' => ['last_used_at', 'expires_at', 'created_at', 'updated_at'],
        'personnel' => ['created_at', 'updated_at', 'deleted_at'],
        'personnel_libraries' => ['created_at', 'updated_at'],
        'positions' => ['created_at', 'updated_at'],
        'psgc_barangays' => ['created_at', 'updated_at'],
        'psgc_municipalities' => ['created_at', 'updated_at'],
        'psgc_provinces' => ['created_at', 'updated_at'],
        'roles' => ['created_at', 'updated_at'],
        'ro_offices' => ['created_at', 'updated_at'],
        'sdo_offices' => ['created_at', 'updated_at'],
        'stakeholder_libraries' => ['created_at', 'updated_at'],
        'stakeholder_profiles' => ['created_at', 'updated_at'],
        'system_settings' => ['created_at', 'updated_at'],
        'trusted_devices' => ['last_used_at', 'expires_at', 'created_at', 'updated_at'],
        'users' => ['email_verified_at', 'two_factor_confirmed_at', 'created_at', 'updated_at'],
    ];

    public function up(): void
    {
        $this->shift(self::HOURS);
    }

    public function down(): void
    {
        $this->shift(-self::HOURS);
    }

    /**
     * @param  8|-8  $hours
     */
    private function shift(int $hours): void
    {
        $isMysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        $previousZone = null;

        if ($isMysql) {
            $this->assertNoUnlistedOnUpdateColumns();
            // Do the arithmetic on TIMESTAMP's internal UTC value so the
            // session time zone (SYSTEM here) can't introduce a DST gap.
            $previousZone = DB::selectOne('SELECT @@session.time_zone AS tz')->tz;
            DB::statement("SET time_zone = '+00:00'");
        }

        try {
            DB::transaction(function () use ($hours, $isMysql) {
                foreach (self::COLUMNS as $table => $columns) {
                    // audit_logs is dropped in a later phase; skip if already gone.
                    if (! Schema::hasTable($table)) {
                        continue;
                    }

                    // One UPDATE per table: assigning every column explicitly
                    // also stops any ON UPDATE CURRENT_TIMESTAMP from firing.
                    $sets = [];
                    foreach ($columns as $column) {
                        $sets[$column] = DB::raw($this->shiftExpression($column, $hours, $isMysql));
                    }

                    DB::table($table)->update($sets);
                }
            });
        } finally {
            if ($previousZone !== null) {
                DB::statement('SET time_zone = ?', [$previousZone]);
            }
        }
    }

    /**
     * Quotes by hand, as the grammar's wrap() would, so the SQL stays a
     * literal-string built only from COLUMNS and the fixed offset.
     *
     * @param  literal-string  $column
     * @param  8|-8  $hours
     * @return literal-string
     */
    private function shiftExpression(string $column, int $hours, bool $isMysql): string
    {
        $sign = $hours >= 0 ? '+' : '';

        // NULL in -> NULL out on both drivers.
        return $isMysql
            ? "DATE_ADD(`{$column}`, INTERVAL {$hours} HOUR)"
            : "datetime(\"{$column}\", '{$sign}{$hours} hours')";
    }

    /**
     * An ON UPDATE CURRENT_TIMESTAMP column not in the list would be bumped
     * to now() by our UPDATE; refuse rather than corrupt it.
     */
    private function assertNoUnlistedOnUpdateColumns(): void
    {
        $rows = DB::select(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND EXTRA LIKE '%on update%'"
        );

        foreach ($rows as $row) {
            if (array_key_exists($row->t, self::COLUMNS) && ! in_array($row->c, self::COLUMNS[$row->t], true)) {
                throw new RuntimeException("Unlisted ON UPDATE column {$row->t}.{$row->c} would be clobbered by the timezone shift.");
            }
        }
    }
};
