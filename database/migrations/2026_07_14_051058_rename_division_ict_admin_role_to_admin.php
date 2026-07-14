<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data-only migration: renames the seeded role's internal `name` from
 * `division-ict-admin` to `admin` (its `label` was already renamed to
 * "Administrator" separately). RolePermissionSeeder's own upsert can't do
 * this rename by itself — updateOrCreate(['name' => 'admin'], ...) matches
 * on the NEW name, so on an already-seeded database it would create a
 * second, duplicate role rather than renaming the existing one. This runs
 * once, before the seeder's array key changes, so existing installs and
 * fresh ones converge on the same row.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->where('name', 'division-ict-admin')->update(['name' => 'admin']);
    }

    public function down(): void
    {
        DB::table('roles')->where('name', 'admin')->update(['name' => 'division-ict-admin']);
    }
};
