<?php

namespace App\Models;

use App\Concerns\HasActiveReferenceOptions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tier 1 reference-data entity (lookup-normalization ADR). Bare-bones
 * structural model — fillable/casts/activeValues()/activeOptions() only.
 * Backend owns: `belongsTo` wiring on Equipment (`equipment_condition_id`),
 * Form Request validation, reworking Equipment::scopeCondition to filter
 * by id, and any admin CRUD for managing these rows (Step 2).
 */
#[Fillable(['name', 'description', 'sort_order', 'is_active'])]
class EquipmentCondition extends Model
{
    use HasActiveReferenceOptions;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
