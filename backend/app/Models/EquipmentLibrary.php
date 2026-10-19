<?php

namespace App\Models;

use App\Concerns\HasActiveLibraryOptions;
use App\Enums\EquipmentLibraryType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tier 2 domain-grouped library row (lookup-normalization ADR). Bare-bones
 * structural model — fillable/casts/activeValues()/activeOptions() only.
 * Backend owns: Form Request validation
 * (Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::X))) and
 * any admin CRUD for managing these rows (Step 2).
 */
#[Fillable(['type', 'value', 'description', 'sort_order', 'is_active'])]
class EquipmentLibrary extends Model
{
    use HasActiveLibraryOptions;

    protected function casts(): array
    {
        return [
            'type' => EquipmentLibraryType::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
