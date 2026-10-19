<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Real subscriptions sometimes have no documented plan-speed tier on
     * file (e.g. an older residential plan where the source records cost
     * and coverage but never captured a min/max Mbps figure) — genuinely
     * unknown, not zero. Same reasoning as making Equipment's property_no/
     * acquisition_cost nullable: represent "not on file" as null rather
     * than a fabricated placeholder number.
     */
    public function up(): void
    {
        Schema::table('isp_accounts', function (Blueprint $table) {
            $table->decimal('min_speed', 8, 2)->nullable()->change();
            $table->decimal('max_speed', 8, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('isp_accounts', function (Blueprint $table) {
            $table->decimal('min_speed', 8, 2)->nullable(false)->change();
            $table->decimal('max_speed', 8, 2)->nullable(false)->change();
        });
    }
};
