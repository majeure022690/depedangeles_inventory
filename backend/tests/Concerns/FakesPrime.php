<?php

namespace Tests\Concerns;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait FakesPrime
{
    private int $nextEmployeeId = 1;

    protected function fakePrime(): void
    {
        config(['database.connections.external_users' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('external_users');

        $schema = Schema::connection('external_users');
        $schema->create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('full_name');
            $table->string('email_address');
            $table->string('password')->default('x');
            $table->string('user_status')->default('Active');
            $table->unsignedInteger('department_school_id')->nullable();
        });
        $schema->create('employees', function (Blueprint $table) {
            $table->increments('employee_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('surname');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('name_extension')->nullable();
            $table->string('deped_id_no')->nullable();
            $table->string('deped_email')->nullable();
            $table->string('employee_status')->default('Active');
            $table->unsignedInteger('department_school_id')->nullable();
        });
        $schema->create('departments', function (Blueprint $table) {
            $table->increments('dept_id');
            $table->unsignedTinyInteger('office_id');
            $table->unsignedInteger('district_id')->nullable();
            $table->string('department_school')->nullable();
            $table->string('shortened_name')->nullable();
            $table->unsignedInteger('office_school_id')->nullable();
        });
    }

    protected function breakPrime(): void
    {
        config(['database.connections.external_users' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x']]);
        DB::purge('external_users');
    }

    protected function prime(string $table): Builder
    {
        return DB::connection('external_users')->table($table);
    }

    protected function primeAccount(string $email, ?int $department = null, string $status = 'Active', string $name = ''): int
    {
        return $this->prime('users')->insertGetId([
            'full_name' => $name !== '' ? $name : 'Prime '.$email,
            'email_address' => $email,
            'user_status' => $status,
            'department_school_id' => $department,
        ]);
    }

    protected function primeEmployee(?int $userId, ?string $number, ?int $department = null, string $surname = 'Reyes'): int
    {
        $id = $this->nextEmployeeId++;

        return $this->prime('employees')->insertGetId([
            'employee_id' => $id, 'user_id' => $userId, 'surname' => $surname, 'first_name' => 'Maria',
            'deped_id_no' => $number, 'deped_email' => "emp{$id}@deped.gov.ph", 'department_school_id' => $department,
        ]);
    }

    protected function primeDepartment(string $name, int $officeId = 2, ?int $schoolId = null): int
    {
        return $this->prime('departments')->insertGetId(['office_id' => $officeId, 'department_school' => $name, 'office_school_id' => $schoolId]);
    }
}
