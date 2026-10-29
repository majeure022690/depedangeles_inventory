<?php

namespace App\Models;

use App\Enums\EquipmentLibraryType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'document_type', 'document_no', 'original_filename', 'disk', 'path',
    'mime_type', 'file_size', 'file_hash',
])]
class EquipmentAttachment extends Model
{
    public const string PMP_DOCUMENT_TYPE = 'Procurement Management Plan (PMP)';

    public const int MAX_KILOBYTES = 5120;

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    /**
     * @return list<string>
     */
    public static function documentTypes(): array
    {
        return [
            self::PMP_DOCUMENT_TYPE,
            ...EquipmentLibrary::activeValues(EquipmentLibraryType::SupportingDocumentType),
        ];
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
