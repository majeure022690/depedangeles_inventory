<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            // Encrypted cast on the model, so text rather than a sized string.
            $table->text('recipients');
            $table->string('subject', 255)->nullable();
            $table->string('type', 100)->nullable();
            $table->string('status', 10);
            $table->string('provider_message_id', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
