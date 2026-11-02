<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_PAIRS = [
        ['supporting_documents1', 'or_si_dr_iar_no'],
        ['supporting_documents2', 'par_ics_rrsp_rs_wmr_no'],
    ];

    public function up(): void
    {
        Schema::create('equipment_transaction_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_transaction_id')->nullable()->constrained('equipment_transactions')->cascadeOnDelete();
            $table->string('document_type')->nullable();
            $table->string('document_no')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->char('file_hash', 64)->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['equipment_transaction_id', 'id']);
            $table->index(['uploaded_by_user_id', 'equipment_transaction_id']);
        });

        DB::table('equipment_transactions')
            ->where(fn ($query) => $query->whereNotNull('supporting_documents1')->orWhereNotNull('or_si_dr_iar_no')
                ->orWhereNotNull('supporting_documents2')->orWhereNotNull('par_ics_rrsp_rs_wmr_no'))
            ->orderBy('id')
            ->chunkById(500, function ($transactions) {
                $rows = [];

                foreach ($transactions as $transaction) {
                    foreach (self::LEGACY_PAIRS as [$type, $number]) {
                        if ($transaction->{$type} !== null || $transaction->{$number} !== null) {
                            $rows[] = [
                                'equipment_transaction_id' => $transaction->id,
                                'document_type' => $transaction->{$type},
                                'document_no' => $transaction->{$number},
                                'created_at' => $transaction->created_at,
                                'updated_at' => $transaction->created_at,
                            ];
                        }
                    }
                }

                DB::table('equipment_transaction_documents')->insert($rows);
            });

        Schema::table('equipment_transactions', function (Blueprint $table) {
            $table->dropColumn(['supporting_documents1', 'or_si_dr_iar_no', 'supporting_documents2', 'par_ics_rrsp_rs_wmr_no']);
        });
    }

    public function down(): void
    {
        Schema::table('equipment_transactions', function (Blueprint $table) {
            $table->string('supporting_documents1')->nullable();
            $table->string('or_si_dr_iar_no')->nullable();
            $table->string('supporting_documents2')->nullable();
            $table->string('par_ics_rrsp_rs_wmr_no')->nullable();
        });

        Schema::dropIfExists('equipment_transaction_documents');
    }
};
