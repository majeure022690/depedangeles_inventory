<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_no', 50)->nullable()->after('office_id')->unique();
        });

        $this->copyLinksToEmployeeNo();

        Schema::table('personnel', function (Blueprint $table) {
            if (! $this->isSqlite()) {
                $table->dropForeign(['user_id']);
            }

            $table->dropUnique(['user_id']);
        });

        Schema::table('personnel', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('personnel', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('office_id')->unique();
        });

        // sqlite rebuilds the table to add a constraint, losing personnel's generated full_name column.
        if (! $this->isSqlite()) {
            Schema::table('personnel', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        $personnelIds = DB::table('personnel')
            ->whereNull('deleted_at')
            ->pluck('id', 'employee_id');

        foreach (DB::table('users')->whereNotNull('employee_no')->pluck('employee_no', 'id') as $userId => $employeeNo) {
            if (isset($personnelIds[$employeeNo])) {
                DB::table('personnel')->where('id', $personnelIds[$employeeNo])->update(['user_id' => $userId]);
            }
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['employee_no']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('employee_no');
        });
    }

    private function copyLinksToEmployeeNo(): void
    {
        [$links, $trashed] = DB::table('personnel')
            ->join('users', 'users.id', '=', 'personnel.user_id')
            ->orderBy('personnel.id')
            ->get(['personnel.id as personnel_id', 'personnel.user_id', 'personnel.employee_id', 'personnel.deleted_at'])
            ->partition(fn ($row) => $row->deleted_at === null)
            ->map(fn ($rows) => $rows->map(fn ($row) => [
                'personnel_id' => (int) $row->personnel_id,
                'user_id' => (int) $row->user_id,
                'employee_no' => trim((string) $row->employee_id),
            ])->values());

        $copied = [];
        $skipped = [];

        foreach ($links->groupBy('employee_no') as $group) {
            $link = $group->first();

            if ($link['employee_no'] === '' || mb_strlen($link['employee_no']) > 50 || $group->count() !== 1) {
                array_push($skipped, ...$group->all());

                continue;
            }

            DB::table('users')->where('id', $link['user_id'])->update(['employee_no' => $link['employee_no']]);
            $copied[] = $link;
        }

        if ($copied === [] && $skipped === [] && $trashed->isEmpty()) {
            return;
        }

        $this->audit([
            'change' => 'personnel.user_id dropped; each non-trashed link copied to users.employee_no as trim(personnel.employee_id)',
            'copied_count' => count($copied),
            'copied' => $copied,
            'skipped' => $skipped,
            'dropped_trashed' => $trashed->all(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function audit(array $properties): void
    {
        $migration = pathinfo(__FILE__, PATHINFO_FILENAME);

        DB::table('activity_log')->insert([
            'log_name' => 'default',
            'description' => $migration,
            'event' => 'system.data_scope_migration',
            'properties' => json_encode(['migration' => $migration, ...$properties]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function isSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }
};
