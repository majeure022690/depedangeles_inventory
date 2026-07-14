<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Region III PSGC barangay reference data (see create_psgc_provinces_table
 * for why this deviates from the usual Tier 1 name/sort_order/is_active
 * shape). Bare-bones structural model — fillable/relation only. `code` is
 * the full PSGC barangay code (e.g. "030801001") — the granular code a
 * StakeholderProfile's address ultimately resolves to.
 */
#[Fillable(['code', 'municipality_id', 'name'])]
class PsgcBarangay extends Model
{
    /**
     * @return BelongsTo<PsgcMunicipality, $this>
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(PsgcMunicipality::class);
    }
}
