<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gsp_ict_profiles', function (Blueprint $table) {
            $table->foreignId('school_head_id')->nullable()->after('isp_reliability')->constrained('personnel')->nullOnDelete();
            $table->foreignId('ito_id')->nullable()->after('school_head_id')->constrained('personnel')->nullOnDelete();
            $table->foreignId('planning_officer_id')->nullable()->after('ito_id')->constrained('personnel')->nullOnDelete();
        });

        Schema::table('gsp_ict_profiles', function (Blueprint $table) {
            $table->dropColumn(['school_head_name', 'school_head_position', 'ito_name', 'planning_officer_name']);
        });
    }

    public function down(): void
    {
        Schema::table('gsp_ict_profiles', function (Blueprint $table) {
            $table->string('school_head_name', 150)->nullable();
            $table->string('school_head_position', 150)->nullable();
            $table->string('ito_name', 150)->nullable();
            $table->string('planning_officer_name', 150)->nullable();
        });

        Schema::table('gsp_ict_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_head_id');
            $table->dropConstrainedForeignId('ito_id');
            $table->dropConstrainedForeignId('planning_officer_id');
        });
    }
};
