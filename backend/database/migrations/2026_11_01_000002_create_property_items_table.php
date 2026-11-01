<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->restrictOnDelete();
            $table->string('category', 40);
            $table->string('article', 150);
            $table->string('description', 500)->nullable();
            $table->string('property_no', 100)->nullable();
            $table->string('unit_of_measure', 50)->nullable();
            $table->date('date_acquired')->nullable();
            $table->decimal('unit_value', 14, 2)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('on_hand_count')->default(1);
            $table->string('grade_level', 30)->nullable();
            $table->string('source', 30)->nullable();
            $table->string('condition', 20)->nullable();
            $table->boolean('with_are')->default(false);
            $table->foreignId('accountable_officer_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->foreignId('end_user_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->string('location', 255)->nullable();
            $table->string('remarks', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['office_id', 'category']);
            $table->index('property_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_items');
    }
};
