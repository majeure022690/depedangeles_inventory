<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 1 of the lookup-normalization ADR (Question 5 rollout plan):
     * additive-only new FK column, nullable for now, alongside the
     * still-live old string column `isp`. Backend has not cut the
     * IspAccount resource's read/write paths over yet.
     *
     * `restrictOnDelete()`/`restrictOnUpdate()`: see the matching note on
     * the equipment FK migration.
     */
    public function up(): void
    {
        Schema::table('isp_accounts', function (Blueprint $table) {
            $table->foreignId('isp_provider_id')
                ->nullable()
                ->after('isp')
                ->constrained('isp_providers')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('isp_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('isp_provider_id');
        });
    }
};
