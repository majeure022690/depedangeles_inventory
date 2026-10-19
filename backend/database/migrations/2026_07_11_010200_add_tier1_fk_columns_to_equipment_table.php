<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 1 of the lookup-normalization ADR (Question 5 rollout plan):
     * additive-only new FK columns, nullable for now, alongside the
     * still-live old string columns (`item`, `brand_manufacturer`,
     * `category`, `classification`, `equipment_condition`). Backend has
     * not cut the Equipment resource's read/write paths over yet — this
     * migration changes nothing consumer-facing.
     *
     * `restrictOnDelete()` on all five: these are reference/lookup tables,
     * not disposable rows. A real Equipment record's category/brand/etc.
     * must never be silently null-ed out or cascade-deleted just because
     * an admin deleted (rather than deactivated) a reference-data row —
     * `is_active = false` is the correct way to retire a reference value,
     * deletion of a still-referenced row should fail loudly instead.
     * `restrictOnUpdate()` for the same reason on the rare case a PK is
     * ever changed (Laravel defaults FK id columns to `cascadeOnUpdate`
     * off already, but stated explicitly here as a deliberate choice, not
     * an oversight).
     */
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->foreignId('item_type_id')
                ->nullable()
                ->after('item')
                ->constrained('item_types')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreignId('brand_id')
                ->nullable()
                ->after('brand_manufacturer')
                ->constrained('brands')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreignId('equipment_category_id')
                ->nullable()
                ->after('category')
                ->constrained('equipment_categories')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreignId('equipment_classification_id')
                ->nullable()
                ->after('classification')
                ->constrained('equipment_classifications')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreignId('equipment_condition_id')
                ->nullable()
                ->after('equipment_condition')
                ->constrained('equipment_conditions')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_type_id');
            $table->dropConstrainedForeignId('brand_id');
            $table->dropConstrainedForeignId('equipment_category_id');
            $table->dropConstrainedForeignId('equipment_classification_id');
            $table->dropConstrainedForeignId('equipment_condition_id');
        });
    }
};
