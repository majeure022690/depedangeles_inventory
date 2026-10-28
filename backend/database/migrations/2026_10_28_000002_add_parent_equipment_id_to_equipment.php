<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->foreignId('parent_equipment_id')
                ->nullable()
                ->after('id')
                ->index()
                ->constrained('equipment')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->dropForeign(['parent_equipment_id']);
            $table->dropIndex(['parent_equipment_id']);
        });

        Schema::table('equipment', function (Blueprint $table) {
            $table->dropColumn('parent_equipment_id');
        });
    }
};
