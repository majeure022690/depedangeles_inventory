<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('isp_accounts', function (Blueprint $table) {
            $table->foreignId('office_id')
                ->nullable()
                ->after('isp_provider_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('isp_accounts', function (Blueprint $table) {
            $table->dropForeign(['office_id']);
            $table->dropIndex(['office_id']);
        });

        Schema::table('isp_accounts', function (Blueprint $table) {
            $table->dropColumn('office_id');
        });
    }
};
