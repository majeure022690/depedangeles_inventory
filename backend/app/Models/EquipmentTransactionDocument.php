<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supporting document of a transaction. A file is uploaded first as a pending row (no
 * transaction yet, owned by its uploader) and claimed when the transaction is recorded; the
 * same file then backs copies on bundled peripherals and bulk-transferred items.
 */
#[Fillable([
    'equipment_transaction_id', 'document_type', 'document_no', 'original_filename',
    'disk', 'path', 'mime_type', 'file_size', 'file_hash', 'uploaded_by_user_id',
])]
class EquipmentTransactionDocument extends Model
{
    public const int MAX_PER_TRANSACTION = 10;

    public const int PENDING_HOURS = 24;

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }

    /**
     * @return BelongsTo<EquipmentTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(EquipmentTransaction::class, 'equipment_transaction_id');
    }

    /**
     * @param  Builder<EquipmentTransactionDocument>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('equipment_transaction_id');
    }

    public function hasFile(): bool
    {
        return $this->path !== null;
    }
}
