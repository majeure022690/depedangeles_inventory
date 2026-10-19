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
        Schema::create('offices', function (Blueprint $table) {
            $table->id();
            $table->string('office_name');
            $table->string('office_type', 100)->nullable();

            // The DepEd-assigned School ID code (e.g. 107022) — distinct
            // from this table's own auto-increment `id`. Only populated for
            // rows that are actual schools; division-level offices/units
            // (office_type = "Division Office"/"Unit"/null) leave this null.
            $table->unsignedInteger('school_id')->nullable();

            $table->text('address')->nullable();
            $table->string('region', 100)->nullable();
            $table->string('division', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('contact_number', 50)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('school_id');
            $table->index(['is_active', 'office_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offices');
    }
};
