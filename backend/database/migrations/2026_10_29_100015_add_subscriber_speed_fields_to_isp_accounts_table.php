<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('isp_accounts', function (Blueprint $table) {
            $table->decimal('subscriber_speed_upload', 10, 2)->nullable()->after('max_speed');
            $table->decimal('subscriber_speed_download', 10, 2)->nullable()->after('subscriber_speed_upload');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('isp_accounts', function (Blueprint $table) {
            $table->dropColumn(['subscriber_speed_upload', 'subscriber_speed_download']);
        });
    }
};
