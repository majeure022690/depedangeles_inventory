<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bare-bones structural model — fillable/casts/relations/accessor only.
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
 * `province`/`municipality`/`barangay` are real FKs into the PSGC
 * reference tables (province_id/municipality_id/barangay_id) — cascading
 * dropdowns, not free text. There is no stored `psgc` column anymore: the
 * granular PSGC code is simply the selected barangay's own `code`.
 *
 * `complete_address` used to be a MySQL STORED generated column, but
 * generated columns can't JOIN across tables to read the PSGC names — it's
 * now a PHP accessor built from the three relations below. This also
 * permanently resolves the old firstOrCreate()-doesn't-refetch-generated-
 * columns gotcha, since there's no longer a DB-side value to refetch.
 *
 * No relationships to Equipment/Personnel/IspAccount — this table is
 * standalone survey/profile data, scoped only to its owning Office.
 */
#[Fillable([
    'office_id', 'governance_level', 'ro', 'sdo', 'school_district', 'school_name',
    'school_id', 'province_id', 'municipality_id', 'legislative_district',
    'barangay_id', 'street', 'notes_corrections',
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
     * True once any field beyond office_id has actually been filled in —
     * firstOrCreate() persists an empty row on first edit-page view, so
     * row-existence alone can't tell "started" from "just opened". The 4
     * booleans below default to false (not null) at the DB level, so they
     * can't count as "answered" on their own.
     */
    public function hasAnswers(): bool
    {
        return collect($this->getAttributes())
            ->except(['id', 'office_id', 'created_at', 'updated_at', 'transportation_difficult', 'considered_very_remote', 'gidca', 'lms'])
            ->contains(fn ($value) => $value !== null);
    }

    /**
     * @return BelongsTo<Office, $this>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * @return BelongsTo<PsgcProvince, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(PsgcProvince::class);
    }

    /**
     * @return BelongsTo<PsgcMunicipality, $this>
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(PsgcMunicipality::class);
    }

    /**
     * @return BelongsTo<PsgcBarangay, $this>
     */
    public function barangay(): BelongsTo
    {
        return $this->belongsTo(PsgcBarangay::class);
    }

    /**
     * Mirrors the old STORED generated column's CONCAT_WS behavior:
     * blank/missing components are skipped rather than leaving stray
     * ", " separators.
     *
     * @return Attribute<string, never>
     */
    protected function completeAddress(): Attribute
    {
        return Attribute::get(fn () => implode(', ', array_filter([
            $this->street,
            $this->barangay?->name,
            $this->municipality?->name,
            $this->province?->name,
        ])));
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
