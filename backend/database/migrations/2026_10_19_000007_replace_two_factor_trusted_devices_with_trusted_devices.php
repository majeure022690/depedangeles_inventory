<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Existing trusts are intentionally invalidated, not copied: rwatch's
 * TrustedDeviceService uses a different cookie name and token format, so no
 * old row could match again. `trusted_devices` is verbatim from rwatch
 * (2026_10_01_040000). down() restores the old table empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('two_factor_trusted_devices');

        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('user_agent', 512)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');

        Schema::create('two_factor_trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash')->unique();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }
};
