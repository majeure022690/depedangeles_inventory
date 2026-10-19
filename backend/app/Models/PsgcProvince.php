<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Region III PSGC province reference data (see create_psgc_provinces_table
 * for why this deviates from the usual Tier 1 name/sort_order/is_active
 * shape). Bare-bones structural model — fillable/relation only.
 */
#[Fillable(['code', 'name'])]
class PsgcProvince extends Model
{
    /**
     * @return HasMany<PsgcMunicipality, $this>
     */
    public function municipalities(): HasMany
    {
        return $this->hasMany(PsgcMunicipality::class, 'province_id');
    }
}
