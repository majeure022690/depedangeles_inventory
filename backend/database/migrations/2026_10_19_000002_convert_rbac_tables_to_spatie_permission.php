<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Converts the hand-rolled RBAC tables to spatie/laravel-permission's
 * schema in place, keeping every roles/permissions id so grants and any id
 * already recorded in audit properties stay meaningful (decoupled-spa-stack
 * ADR §7). `roles.label`/`description` are kept. The three pivots match
 * rwatch's 2026_09_14_075229_create_permission_tables exactly, no teams.
 * Spatie's stock create_permission_tables migration is never published.
 */
return new class extends Migration
{
    private const USER_MORPH = 'App\\Models\\User';

    public function up(): void
    {
        foreach (['permissions', 'roles'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('guard_name')->default('web')->after('name');
            });

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropUnique(['name']);
                $table->unique(['name', 'guard_name']);
            });
        }

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');

            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');

            $table->foreign('permission_id')
                ->references('id')
                ->on('permissions')
                ->cascadeOnDelete();

            $table->primary(['permission_id', 'model_id', 'model_type'],
                'model_has_permissions_permission_model_type_primary');
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');

            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');

            $table->foreign('role_id')
                ->references('id')
                ->on('roles')
                ->cascadeOnDelete();

            $table->primary(['role_id', 'model_id', 'model_type'],
                'model_has_roles_role_model_type_primary');
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');

            $table->foreign('permission_id')
                ->references('id')
                ->on('permissions')
                ->cascadeOnDelete();

            $table->foreign('role_id')
                ->references('id')
                ->on('roles')
                ->cascadeOnDelete();

            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
        });

        DB::transaction(function () {
            DB::table('role_has_permissions')->insertUsing(
                ['permission_id', 'role_id'],
                DB::table('permission_role')->select('permission_id', 'role_id'),
            );

            // Bound, not inlined: MySQL treats `\` inside a string literal as an escape.
            DB::table('model_has_roles')->insertUsing(
                ['role_id', 'model_type', 'model_id'],
                DB::table('role_user')->selectRaw('role_id, ? as model_type, user_id', [self::USER_MORPH]),
            );
        });

        Schema::drop('permission_role');
        Schema::drop('role_user');

        $this->forgetSpatieCache();
    }

    /**
     * Direct per-user grants in model_has_permissions have no equivalent in
     * the old schema and are discarded; inventory never writes any.
     */
    public function down(): void
    {
        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('permission_id')
                ->constrained('permissions')
                ->cascadeOnDelete();
            $table->foreignId('role_id')
                ->constrained('roles')
                ->cascadeOnDelete();

            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('role_id')
                ->constrained('roles')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->primary(['role_id', 'user_id']);
        });

        DB::transaction(function () {
            DB::table('permission_role')->insertUsing(
                ['permission_id', 'role_id'],
                DB::table('role_has_permissions')->select('permission_id', 'role_id'),
            );

            // model_has_roles has no FK to users, so skip any orphaned rows
            // that role_user's FK would reject.
            DB::table('role_user')->insertUsing(
                ['role_id', 'user_id'],
                DB::table('model_has_roles')
                    ->select('role_id', 'model_id')
                    ->where('model_type', self::USER_MORPH)
                    ->whereIn('model_id', DB::table('users')->select('id')),
            );
        });

        Schema::drop('role_has_permissions');
        Schema::drop('model_has_roles');
        Schema::drop('model_has_permissions');

        foreach (['permissions', 'roles'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropUnique(['name', 'guard_name']);
                $table->unique('name');
            });

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('guard_name');
            });
        }

        $this->forgetSpatieCache();
    }

    private function forgetSpatieCache(): void
    {
        $store = config('permission.cache.store', 'default');

        app('cache')
            ->store($store !== 'default' ? $store : null)
            ->forget(config('permission.cache.key', 'spatie.permission.cache'));
    }
};
