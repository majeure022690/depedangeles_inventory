<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->unsignedInteger('prime_department_id')->nullable()->after('id')->unique();
        });
    }

    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->dropUnique(['prime_department_id']);
        });

        Schema::table('offices', function (Blueprint $table) {
            $table->dropColumn('prime_department_id');
        });
    }
};
