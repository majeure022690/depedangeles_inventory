<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Minimal, append-only audit trail — proportionate to this project's
     * size, not a general activity-log framework. Today it's written to
     * exclusively by role/permission changes (User::assignRole/removeRole)
     * and denied checks on sensitive permissions; `subject_*` is a
     * nullable morph so it can point at whichever model the event is
     * about (typically the User whose roles changed).
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // e.g. 'role.assigned', 'role.revoked', 'authorization.denied'
            $table->string('action');

            $table->nullableMorphs('subject');

            // Free-form context: role/permission name, old/new values, etc.
            $table->json('properties')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
