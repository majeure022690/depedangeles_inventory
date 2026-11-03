<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_counts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->date('as_of_date');
            $table->string('status', 20)->default('draft')->index();
            $table->json('office_ids');
            $table->foreignId('committee_chair_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->json('committee_member_ids')->nullable();
            $table->string('coa_representative', 150)->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->restrictOnDelete();
            $table->foreignId('office_id')->nullable()->constrained('offices')->nullOnDelete();
            $table->foreignId('condition_id')->nullable()->constrained('equipment_conditions')->nullOnDelete();
            $table->foreignId('accountable_officer_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->text('location')->nullable();
            $table->string('result', 20)->default('pending');
            $table->foreignId('found_condition_id')->nullable()->constrained('equipment_conditions')->nullOnDelete();
            $table->foreignId('found_holder_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->text('found_location')->nullable();
            $table->string('remarks', 500)->nullable();
            $table->foreignId('counted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('counted_at')->nullable();
            $table->timestamps();

            $table->unique(['inventory_count_id', 'equipment_id']);
            $table->index(['inventory_count_id', 'office_id', 'result']);
        });

        Schema::create('inventory_count_found_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained('offices')->restrictOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained('equipment')->nullOnDelete();
            $table->string('description', 255)->nullable();
            $table->string('property_no', 100)->nullable();
            $table->string('serial_no', 100)->nullable();
            $table->foreignId('condition_id')->nullable()->constrained('equipment_conditions')->nullOnDelete();
            $table->text('location')->nullable();
            $table->string('remarks', 500)->nullable();
            $table->foreignId('counted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('counted_at')->nullable();
            $table->timestamps();

            $table->index(['inventory_count_id', 'office_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_count_found_items');
        Schema::dropIfExists('inventory_count_items');
        Schema::dropIfExists('inventory_counts');
    }
};
