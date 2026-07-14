<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Region III PSGC city/municipality reference data (see
 * create_psgc_provinces_table for why this deviates from the usual
 * Tier 1 name/sort_order/is_active shape). Bare-bones structural model —
 * fillable/relations only.
 */
#[Fillable(['code', 'province_id', 'name'])]
class PsgcMunicipality extends Model
{
    /**
     * @return BelongsTo<PsgcProvince, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(PsgcProvince::class);
    }

    /**
     * @return HasMany<PsgcBarangay, $this>
     */
    public function barangays(): HasMany
    {
        return $this->hasMany(PsgcBarangay::class, 'municipality_id');
    }
}
