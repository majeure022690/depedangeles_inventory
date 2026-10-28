<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULTS = [
        'Office, ICT Equipment, Furniture And Fixtures' => [
            'default_gl_sl_code' => '10605030',
            'default_uacs' => '1060503000',
            'default_useful_life' => 5,
        ],
    ];

    public function up(): void
    {
        Schema::table('equipment_classifications', function (Blueprint $table) {
            $table->string('default_gl_sl_code')->nullable()->after('description');
            $table->string('default_uacs')->nullable()->after('default_gl_sl_code');
            $table->unsignedSmallInteger('default_useful_life')->nullable()->after('default_uacs');
        });

        foreach (self::DEFAULTS as $name => $defaults) {
            DB::table('equipment_classifications')->where('name', $name)->update($defaults);
        }
    }

    public function down(): void
    {
        Schema::table('equipment_classifications', function (Blueprint $table) {
            $table->dropColumn(['default_gl_sl_code', 'default_uacs', 'default_useful_life']);
        });
    }
};
