<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same COA-auditability gap as equipment_transactions (see that
     * table's add_recorded_by_user_id migration): isp_speed_tests and
     * isp_subscription_costs recorded WHEN an entry was made but never
     * WHICH LOGGED-IN USER made it — and total_amount_spent is budget/
     * procurement-sensitive government data. Nullable + nullOnDelete for
     * the same reason: losing/deleting a user account must never
     * cascade-delete this history.
     */
    public function up(): void
    {
        Schema::table('isp_speed_tests', function (Blueprint $table) {
            $table->foreignId('recorded_by_user_id')
                ->nullable()
                ->after('isp_account_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('isp_subscription_costs', function (Blueprint $table) {
            $table->foreignId('recorded_by_user_id')
                ->nullable()
                ->after('isp_account_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('isp_speed_tests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_by_user_id');
        });

        Schema::table('isp_subscription_costs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_by_user_id');
        });
    }
};
