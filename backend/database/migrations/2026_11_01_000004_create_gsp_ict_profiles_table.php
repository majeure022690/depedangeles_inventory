<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsp_ict_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->restrictOnDelete();
            $table->string('school_year', 9);
            $table->date('as_of');
            $table->string('level', 20);
            $table->json('radio_teachers')->nullable();
            $table->string('dict_free_wifi', 30)->nullable();
            $table->string('dict_free_wifi_installed', 30)->nullable();
            $table->json('isp_reliability')->nullable();
            $table->string('school_head_name', 150)->nullable();
            $table->string('school_head_position', 150)->nullable();
            $table->string('ito_name', 150)->nullable();
            $table->string('planning_officer_name', 150)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['office_id', 'school_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsp_ict_profiles');
    }
};
