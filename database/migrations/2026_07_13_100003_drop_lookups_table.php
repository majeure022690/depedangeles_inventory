<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4 (cleanup) of the lookup-normalization ADR: drops the `lookups`
     * table outright. Superseded by the 13 Tier 1/Tier 2 reference tables
     * created in the 2026_07_11_010* migrations; every consuming resource
     * (Equipment, Personnel, IspAccount, StakeholderProfile,
     * InternetConnectivitySurvey — including the nested IspSpeedTest child
     * resource's `signal_quality` field, the last remaining consumer) has
     * been confirmed cut over and the codebase greps clean of
     * App\Models\Lookup / App\Enums\LookupType before this migration was
     * written.
     *
     * Run only after 2026_07_13_100001 (drops the legacy string columns
     * that mirrored this table's values) — no FK relationship exists
     * between `lookups` and any other table, so ordering between these two
     * migrations is for narrative clarity, not a hard dependency.
     *
     * down() recreates the table's structure (matching
     * 2026_07_10_000001_create_lookups_table) but not its data — the 677
     * seeded rows are not restored. database/seeders/data/lookups.json was
     * DELETED alongside this migration (not retained — the ADR's Step 4.3
     * left that choice to Database's discretion, and it was deleted). The
     * closest surviving record of the original 677 rows' values is the 13
     * per-table JSON files ReferenceDataSeeder reads
     * (database/seeders/data/{item_types,brands,...}.json), which the
     * original values were split into during Step 1 of the rollout — see
     * docs/architecture-decisions/lookup-normalization.md.
     */
    public function up(): void
    {
        Schema::dropIfExists('lookups');
    }

    public function down(): void
    {
        Schema::create('lookups', function (Blueprint $table) {
            $table->id();
            $table->string('type', 64);
            $table->string('value');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'value']);
            $table->index(['type', 'is_active', 'sort_order']);
        });
    }
};
