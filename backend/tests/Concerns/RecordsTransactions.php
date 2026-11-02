<?php

namespace Tests\Concerns;

use App\Models\EquipmentTransactionDocument;
use App\Models\User;

trait RecordsTransactions
{
    /**
     * A pending supporting-document upload owned by $uploader (the acting user by default).
     *
     * @return array{document_type: string, document_no: string, upload_id: int}
     */
    protected function supportingDocument(?User $uploader = null, string $type = 'Property Acknowledgment Receipt (PAR)'): array
    {
        $uploader ??= auth()->user();

        $upload = EquipmentTransactionDocument::create([
            'original_filename' => 'par.pdf',
            'disk' => 'local',
            'path' => 'equipment-transaction-documents/test-'.uniqid().'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1,
            'file_hash' => str_repeat('0', 64),
            'uploaded_by_user_id' => $uploader?->id,
        ]);

        return ['document_type' => $type, 'document_no' => 'PAR-2026-001', 'upload_id' => $upload->id];
    }

    /**
     * A transaction body the type's rules accept: dates for every named person, a receiver for
     * Issuance/Transfer and Return (the new holder unless given), and one supporting document.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    protected function transactionPayload(array $fields, ?User $uploader = null): array
    {
        $payload = $fields;
        $type = $payload['transaction_type'] ?? null;

        if (in_array($type, ['Issuance/Transfer', 'Return'], true) && ! array_key_exists('received_by_id', $payload)) {
            $payload['received_by_id'] = $payload['accountable_officer_id'] ?? $payload['end_user_id'] ?? null;
        }

        foreach (['accountable_officer_id' => 'date_assigned_accountable_officer', 'end_user_id' => 'date_assigned_end_user', 'received_by_id' => 'date_received_new_accountable'] as $person => $date) {
            if (($payload[$person] ?? null) !== null) {
                $payload[$date] ??= '2026-11-02';
            }
        }

        if ($type !== null && $type !== 'Beginning Inventory' && ! array_key_exists('documents', $payload)) {
            $payload['documents'] = [$this->supportingDocument($uploader)];
        }

        return $payload;
    }
}
