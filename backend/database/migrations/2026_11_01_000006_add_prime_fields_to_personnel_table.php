<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnel', function (Blueprint $table) {
            $table->unsignedBigInteger('prime_employee_id')->nullable()->unique()->after('employee_id');
            $table->string('appointment_status', 50)->nullable()->after('date_hired');
            $table->string('plantilla_item_no', 50)->nullable()->after('appointment_status');
            $table->string('plantilla_item_level', 255)->nullable()->after('plantilla_item_no');
            $table->string('workstation', 255)->nullable()->after('plantilla_item_level');
            $table->date('date_last_promotion')->nullable()->after('workstation');
            $table->timestamp('prime_synced_at')->nullable()->after('date_last_promotion');
        });
    }

    public function down(): void
    {
        Schema::table('personnel', function (Blueprint $table) {
            $table->dropUnique(['prime_employee_id']);
            $table->dropColumn(['prime_employee_id', 'appointment_status', 'plantilla_item_no', 'plantilla_item_level', 'workstation', 'date_last_promotion', 'prime_synced_at']);
        });
    }
};
