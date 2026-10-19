<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Generic reference-data table backing every dropdown/lookup list in
     * the app (~33 lists — item type, brand, category, condition, etc.).
     * `type` values are validated against App\Enums\LookupType at the
     * application layer; the column itself stays a plain string so this
     * table needs no schema change when a new lookup list is introduced.
     */
    public function up(): void
    {
        Schema::create('lookups', function (Blueprint $table) {
            $table->id();
            $table->string('type', 64);
            $table->string('value');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // A given list (type) can't have the same value twice.
            $table->unique(['type', 'value']);

            // Primary read pattern: "give me the active values for this
            // type, in display order" — used to populate every dropdown.
            $table->index(['type', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lookups');
    }
};
