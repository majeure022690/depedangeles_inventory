<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PRIME is the only password authority. Clears every local password except
 * those of the developer break-glass accounts in LOCAL_LOGIN_EMAILS — in
 * production (empty list) that is every local password, including the PRIME
 * hashes the old login/sync copied. Rows are kept: audit and equipment
 * records reference them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $allowed = array_values(array_filter(array_map(
            fn (string $email): string => mb_strtolower(trim($email)),
            (array) config('auth.local_login_emails', []),
        )));

        DB::table('users')
            ->whereNotNull('password')
            ->when($allowed !== [], fn ($query) => $query->whereNotIn(DB::raw('LOWER(email)'), $allowed))
            ->update(['password' => null]);
    }

    public function down(): void
    {
        // No-op: cleared password hashes can't be restored.
    }
};
