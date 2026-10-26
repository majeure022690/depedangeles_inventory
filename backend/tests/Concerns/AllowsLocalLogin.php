<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * For tests of flows that don't depend on which authority checks the
 * password (2FA, rate limits, password re-confirmation): every account
 * created in the test is a developer break-glass account, so its local
 * factory password works. PRIME is an empty, reachable directory, so it
 * knows none of these accounts. PRIME-authority behaviour is covered in
 * PrimeLoginTest and CurrentPasswordTest.
 */
trait AllowsLocalLogin
{
    protected function setUpAllowsLocalLogin(): void
    {
        config(['database.connections.external_users' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('external_users');
        Schema::connection('external_users')->create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('full_name');
            $table->string('email_address');
            $table->string('password')->default('x');
            $table->string('user_status')->default('Active');
            $table->unsignedInteger('department_school_id')->nullable();
        });

        User::created(function (User $user): void {
            config(['auth.local_login_emails' => [...config('auth.local_login_emails', []), $user->email]]);
        });
    }
}
