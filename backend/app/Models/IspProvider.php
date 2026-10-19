<?php

namespace App\Models;

use App\Concerns\HasActiveReferenceOptions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tier 1 reference-data entity (lookup-normalization ADR). Bare-bones
 * structural model — fillable/casts/activeValues()/activeOptions() only.
 * Backend owns: `belongsTo` wiring on IspAccount (`isp_provider_id`), Form
 * Request validation, reworking IspAccount::scopeProvider to filter by id,
 * and any admin CRUD for managing these rows (Step 2). Referenced from
 * isp_accounts.isp_provider_id and, once the Question 4 JSON-array data
 * migration lands, from internet_connectivity_surveys.available_isps/
 * .subscribed_isps as arrays of this table's row ids.
 */
#[Fillable(['name', 'description', 'sort_order', 'is_active'])]
class IspProvider extends Model
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
