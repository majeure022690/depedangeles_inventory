<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passkeys go away with Fortify (decoupled-spa-stack ADR §4). down()
 * restores the 2024_01_01_000000 schema, empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('passkeys');
    }

    public function down(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('credential_id')->unique();
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }
};
