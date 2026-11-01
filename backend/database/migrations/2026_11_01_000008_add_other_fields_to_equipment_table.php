<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->string('item_type_other')->nullable()->after('item_type_id');
            $table->string('brand_other')->nullable()->after('brand_id');
            $table->string('dcp_package_other')->nullable()->after('dcp_package');
            $table->string('source_fund_other')->nullable()->after('source_fund');
            $table->string('equipment_condition_other')->nullable()->after('equipment_condition_id');
        });
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->dropColumn(['item_type_other', 'brand_other', 'dcp_package_other', 'source_fund_other', 'equipment_condition_other']);
        });
    }
};
