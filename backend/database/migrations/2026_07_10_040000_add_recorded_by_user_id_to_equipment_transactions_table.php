<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * COA-auditability fix: equipment_transactions previously recorded only
     * the Personnel referenced by a transaction (accountable officer, end
     * user, receiver) but never WHICH LOGGED-IN USER performed the data
     * entry. This adds that operator trail. Nullable + nullOnDelete because
     * losing/deleting a user account must never cascade-delete audit
     * history — the transaction row is the record of truth even if the
     * operator's account is later removed.
     */
    public function up(): void
    {
        Schema::table('equipment_transactions', function (Blueprint $table) {
            $table->foreignId('recorded_by_user_id')
                ->nullable()
                ->after('equipment_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('equipment_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_by_user_id');
        });
    }
};
