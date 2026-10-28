<?php

namespace App\Models;

use App\Concerns\HasActiveReferenceOptions;
use App\Concerns\LogsReferenceDataActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tier 1 reference-data entity (lookup-normalization ADR). The `default_*`
 * columns fill blank equipment fields on save (DerivesEquipmentFields).
 */
#[Fillable(['name', 'description', 'default_gl_sl_code', 'default_uacs', 'default_useful_life', 'sort_order', 'is_active'])]
class EquipmentClassification extends Model
{
    use HasActiveReferenceOptions, LogsReferenceDataActivity;

    protected function casts(): array
    {
        return [
            'default_useful_life' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
