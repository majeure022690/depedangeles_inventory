<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->timestamp('awaiting_receipt_since')->nullable()->after('school_level')->index();
        });
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->dropIndex(['awaiting_receipt_since']);
            $table->dropColumn('awaiting_receipt_since');
        });
    }
};
