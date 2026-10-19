<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cost/budget tracking for an ISP account, one row per budget period
     * (current actuals + projected figures for planning). Append-only
     * history, like isp_speed_tests — no soft deletes.
     *
     * All columns are nullable per the source data: a budget period can
     * be recorded with only actuals, only projections, or a partial mix
     * (e.g. dates known but amount not yet finalized).
     */
    public function up(): void
    {
        Schema::create('isp_subscription_costs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('isp_account_id')
                ->constrained('isp_accounts')
                ->cascadeOnDelete();

            // "Current" period.
            $table->date('contract_start_date')->nullable();
            $table->date('contract_end_date')->nullable();
            $table->decimal('total_amount_spent', 12, 2)->nullable();

            // "Projected" period, for budget planning.
            $table->date('contract_projected_start_date')->nullable();
            $table->date('contract_projected_end_date')->nullable();
            $table->decimal('total_projected_expenditure', 12, 2)->nullable();

            $table->timestamps();

            // Primary access pattern: "give me this account's cost-history
            // records in chronological order" (mirrors isp_speed_tests).
            $table->index(['isp_account_id', 'created_at']);
            // Budget/renewal reporting: "which contracts are ending soon"
            // and "which projected periods are coming up" across accounts.
            $table->index('contract_end_date');
            $table->index('contract_projected_end_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('isp_subscription_costs');
    }
};
