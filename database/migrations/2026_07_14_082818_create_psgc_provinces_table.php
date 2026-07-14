<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tier 1 dedicated entity table (lookup-normalization ADR pattern),
     * but deliberately NOT using the ADR's usual name/description/
     * sort_order/is_active shape (see App\Concerns\HasActiveReferenceOptions):
     * PSGC data is government reference data, not admin-editable, so
     * there is no "toggle active" or manual reordering concern — `code`
     * is the real PSGC code and is the load-bearing identity, `name` is
     * display only. Data is scoped to Region III only (this division's
     * actual coverage area) — see database/seeders/data/psgc_provinces.json.
     */
    public function up(): void
    {
        Schema::create('psgc_provinces', function (Blueprint $table) {
            $table->id();
            $table->string('code', 9)->unique();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psgc_provinces');
    }
};
