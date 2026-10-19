<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A role is nothing more than a named, seeded bundle of permissions —
     * the bundle is data (see permission_role), never a branch in
     * application code. `name` is the stable machine key checked nowhere
     * in authorization logic (that's always a permission string); it only
     * exists for seeding/admin-UI display and the pivot relationship.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
