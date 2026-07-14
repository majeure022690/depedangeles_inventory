<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bare-bones structural model — fillable/casts/relation only.
 *
 * ONE ROW PER OFFICE: every school and division-level office/unit
 * (App\Models\Office) has at most one stakeholder profile, shared by every
 * user whose `office_id` matches it (StakeholderProfilePolicy). This is
 * NOT a global singleton anymore — Backend fetches/creates a specific
 * office's row with `StakeholderProfile::firstOrCreate(['office_id' =>
 * $office->id])`, never a bare `firstOrCreate([])`. No factory is
 * provided — profiles are created lazily per office on first access, not
 * seeded/faked in bulk.
 *
 * `complete_address` is a MySQL STORED generated column (see the
 * create_stakeholder_profiles_table migration) and is therefore
 * deliberately excluded from $fillable — it can never be mass-assigned,
 * only read.
 *
 * No relationships to Equipment/Personnel/IspAccount — this table is
 * standalone survey/profile data, scoped only to its owning Office.
 */
#[Fillable([
    'office_id', 'governance_level', 'ro', 'sdo', 'school_district', 'school_name',
    'school_id', 'province', 'city_municipality', 'legislative_district',
    'barangay', 'street', 'psgc', 'notes_corrections',
    'notes_recent_development', 'mobile_1', 'mobile_2', 'landline',
    'chief_name', 'chief_position', 'chief_email', 'chief_mobile',
    'admin_staff_name', 'admin_staff_position', 'admin_staff_email',
    'admin_staff_mobile', 'network_administrator_name', 'longitude',
    'latitude', 'nearby_institutions', 'nearby_institutions_other',
    'travel_time_to_center_minutes', 'access_paths', 'transportation_options',
    'transportation_other', 'transportation_difficult',
    'considered_very_remote', 'remote_context_notes', 'gidca', 'lms',
    'community_engagement', 'community_context_remarks', 'submitted_at',
    'transaction_type',
])]
class StakeholderProfile extends Model
{
    /**
     * @return BelongsTo<Office, $this>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    protected function casts(): array
    {
        return [
            'longitude' => 'decimal:7',
            'latitude' => 'decimal:7',
            'nearby_institutions' => 'array',
            'access_paths' => 'array',
            'transportation_options' => 'array',
            'transportation_difficult' => 'boolean',
            'considered_very_remote' => 'boolean',
            'gidca' => 'boolean',
            'lms' => 'boolean',
            'community_engagement' => 'array',
            'travel_time_to_center_minutes' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }
}
