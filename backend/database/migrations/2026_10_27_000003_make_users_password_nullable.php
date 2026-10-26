<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * PRIME is the only password authority: PRIME accounts have no local
 * password. Only developer break-glass accounts keep one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Unusable random hashes, so restoring NOT NULL can't open a login.
        DB::table('users')->whereNull('password')->eachById(function (object $user) {
            DB::table('users')->where('id', $user->id)->update(['password' => Hash::make(Str::random(64))]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
