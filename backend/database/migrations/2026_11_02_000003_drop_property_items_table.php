<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const GUARD = 'web';

    private const REVOKED = ['property_items.create', 'property_items.edit', 'property_items.delete'];

    public function up(): void
    {
        DB::transaction(function () {
            $ids = DB::table('permissions')->where('guard_name', self::GUARD)->whereIn('name', self::REVOKED)->pluck('id');

            if ($ids->isNotEmpty()) {
                DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
                DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
                DB::table('permissions')->whereIn('id', $ids)->delete();

                $migration = pathinfo(__FILE__, PATHINFO_FILENAME);

                DB::table('activity_log')->insert([
                    'log_name' => 'default',
                    'description' => $migration,
                    'event' => 'system.permissions_revoked',
                    'properties' => json_encode(['migration' => $migration, 'permissions' => self::REVOKED]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::dropIfExists('property_items');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::create('property_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->restrictOnDelete();
            $table->string('category', 40);
            $table->string('article', 150);
            $table->string('description', 500)->nullable();
            $table->string('property_no', 100)->nullable();
            $table->string('unit_of_measure', 50)->nullable();
            $table->date('date_acquired')->nullable();
            $table->decimal('unit_value', 14, 2)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('on_hand_count')->default(1);
            $table->string('source', 30)->nullable();
            $table->string('condition', 20)->nullable();
            $table->boolean('with_are')->default(false);
            $table->foreignId('accountable_officer_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->foreignId('end_user_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->string('location', 255)->nullable();
            $table->string('remarks', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['office_id', 'category']);
            $table->index('property_no');
        });
    }
};
