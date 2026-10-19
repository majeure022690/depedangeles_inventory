<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Time-series log of individual speed-test runs against an ISP
     * account's connection. Append-only, like equipment_transactions —
     * no soft deletes.
     *
     * SCHEMA DECISION — min_speed/max_speed (the plan's promised
     * bandwidth) are deliberately NOT duplicated here. The source
     * workbook repeats them on every test row, but that's an artifact of
     * its flat, one-sheet-per-entity shape, not a claim that the promised
     * speed varies per test. isp_accounts models exactly one *current*
     * plan per account (no versioned plan-history table), so a test row
     * can only ever mean "measured against the account's current plan
     * speed" — duplicating the value here would just be a copy that goes
     * stale the moment the plan's speed is edited on the parent, with no
     * way to tell which rows still match. Consumers needing the promised
     * range read it off isp_account (min_speed/max_speed) and compare
     * against this row's download_speed/upload_speed. If a genuine
     * plan-tier-history requirement shows up later, that's a new
     * "isp_account_plan_history" concern, not a reason to duplicate
     * columns on every speed test.
     *
     * `tested_at` (not `timestamp`, to avoid colliding with the SQL type
     * name / Laravel's own timestamps() pair) is the user-entered/
     * imported moment the physical test was run — distinct from
     * created_at, which is just when the row was inserted into this
     * system (e.g. during a bulk import, potentially long after the
     * test happened).
     */
    public function up(): void
    {
        Schema::create('isp_speed_tests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('isp_account_id')
                ->constrained('isp_accounts')
                ->cascadeOnDelete();

            $table->decimal('download_speed', 8, 2); // Mbps, measured
            $table->decimal('upload_speed', 8, 2); // Mbps, measured
            $table->unsignedInteger('ping')->nullable(); // ms

            $table->dateTime('tested_at');

            $table->string('signal_quality'); // lookup type: signal_quality
            // Star rating (1-5), NOT lookup-backed.
            $table->unsignedTinyInteger('rate_isp_service')->nullable();

            $table->timestamps();

            // Primary access pattern: "give me this account's speed-test
            // history in chronological order" (mirrors equipment_transactions).
            $table->index(['isp_account_id', 'tested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('isp_speed_tests');
    }
};
