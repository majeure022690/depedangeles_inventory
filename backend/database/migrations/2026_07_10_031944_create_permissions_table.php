<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Materializes App\Enums\Permission (the actual source of truth for
     * what permission strings exist) into rows so roles can be attached
     * to them via a normal FK pivot. `name` always matches an enum case's
     * backed value — RolePermissionSeeder validates this the same way
     * LookupSeeder validates `lookups.type` against LookupType.
     */
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
