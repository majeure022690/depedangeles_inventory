<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tier 1 dedicated entity table (lookup-normalization ADR, Question 1).
     * Referenced from three places (`isp_accounts.isp`,
     * `internet_connectivity_surveys.available_isps`/`.subscribed_isps`) —
     * genuine cross-table reuse; has a dedicated query scope
     * (IspAccount::scopeProvider).
     *
     * Purely additive: `isp_accounts.isp` (string) and the two JSON-array
     * columns stay live/string-valued until Backend/this ADR's Step 1.5
     * data migration cut them over.
     */
    public function up(): void
    {
        Schema::create('isp_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('isp_providers');
    }
};
