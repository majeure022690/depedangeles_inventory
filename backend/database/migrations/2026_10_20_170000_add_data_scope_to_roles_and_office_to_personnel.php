<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('data_scope', 10)->default('all')->after('description');
        });

        DB::table('roles')->where('name', 'viewer')->update(['data_scope' => 'own']);

        Schema::table('personnel', function (Blueprint $table) {
            $table->foreignId('office_id')->nullable()->after('sdo_office_id')->index();
        });

        // sqlite rebuilds the table to add a constraint, losing personnel's generated full_name column.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('personnel', function (Blueprint $table) {
            $table->foreign('office_id')->references('id')->on('offices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personnel', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['office_id']);
            }

            $table->dropIndex(['office_id']);
            $table->dropColumn('office_id');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('data_scope');
        });
    }
};
