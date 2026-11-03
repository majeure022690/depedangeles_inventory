<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_counts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('committee_chair_id');
            $table->dropColumn(['committee_member_ids', 'coa_representative']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_counts', function (Blueprint $table) {
            $table->foreignId('committee_chair_id')->nullable()->after('office_ids')->constrained('personnel')->nullOnDelete();
            $table->json('committee_member_ids')->nullable()->after('committee_chair_id');
            $table->string('coa_representative', 150)->nullable()->after('committee_member_ids');
        });
    }
};
