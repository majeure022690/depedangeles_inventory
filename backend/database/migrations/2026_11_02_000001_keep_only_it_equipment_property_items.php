<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('property_items')->where('category', '!=', 'it_equipment')->delete();

        Schema::table('property_items', function (Blueprint $table) {
            $table->dropColumn('grade_level');
        });
    }

    public function down(): void
    {
        Schema::table('property_items', function (Blueprint $table) {
            $table->string('grade_level', 30)->nullable()->after('on_hand_count');
        });
    }
};
