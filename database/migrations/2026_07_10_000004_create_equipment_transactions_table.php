<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit log of equipment accountability/lifecycle events
     * — replaces the source workbook's "copy the row" pattern. Backend
     * owns the logic that syncs equipment.current_accountable_officer_id
     * / current_end_user_id from the latest row here (e.g. via a model
     * Observer); this migration only establishes the storage shape.
     */
    public function up(): void
    {
        Schema::create('equipment_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('equipment_id')
                ->constrained('equipment')
                ->cascadeOnDelete();

            // lookup type: transaction_type (Beginning Inventory/Delivery/
            // Inspection/Issuance-Transfer/Return/Stock Position/Disposal/etc.)
            $table->string('transaction_type');

            $table->foreignId('accountable_officer_id')
                ->nullable()
                ->constrained('personnel')
                ->nullOnDelete();
            $table->foreignId('end_user_id')
                ->nullable()
                ->constrained('personnel')
                ->nullOnDelete();
            $table->foreignId('received_by_id')
                ->nullable()
                ->constrained('personnel')
                ->nullOnDelete();

            $table->date('date_assigned_accountable_officer')->nullable();
            $table->date('date_assigned_end_user')->nullable();
            $table->date('date_received_new_accountable')->nullable();

            // lookup type: supporting_document_type (OR/SI/DR/IAR/RRSP).
            // Nullable: not every transaction (e.g. a reconstructed
            // Beginning Inventory entry) has a source document — and its
            // paired *_no column is nullable for the same reason, so
            // requiring the type while allowing a blank number would be
            // inconsistent.
            $table->string('supporting_documents1')->nullable();
            $table->string('or_si_dr_iar_no')->nullable();

            // lookup type: supporting_document_type (PAR/ICS/RRSP/RS/WMR/RRPE)
            $table->string('supporting_documents2')->nullable();
            $table->string('par_ics_rrsp_rs_wmr_no')->nullable();

            $table->timestamps();

            // Primary access pattern: "give me this equipment's full
            // history in chronological order."
            $table->index(['equipment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_transactions');
    }
};
