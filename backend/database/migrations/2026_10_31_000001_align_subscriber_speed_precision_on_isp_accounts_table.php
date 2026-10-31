<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('isp_accounts', function (Blueprint $table) {
            $table->decimal('subscriber_speed_upload', 8, 2)->nullable()->change(); // Mbps
            $table->decimal('subscriber_speed_download', 8, 2)->nullable()->change(); // Mbps
        });
    }

    public function down(): void
    {
        Schema::table('isp_accounts', function (Blueprint $table) {
            $table->decimal('subscriber_speed_upload', 10, 2)->nullable()->change();
            $table->decimal('subscriber_speed_download', 10, 2)->nullable()->change();
        });
    }
};
