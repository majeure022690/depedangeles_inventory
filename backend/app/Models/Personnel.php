<?php

namespace App\Models;

use Database\Factories\PersonnelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bare-bones structural model — fillable/casts/relationships only.
 * Backend owns: accessors/scopes (e.g. active(), search-by-name), any
 * "unknown accountable officer" display logic (see migration/report note:
 * no employee_id=9999 placeholder row was seeded — nullable FKs on
 * Equipment/EquipmentTransaction represent "unknown" instead).
 *
 * TIER 1 FK CUTOVER (lookup-normalization ADR, Step 2 — Personnel only):
 * `position_id`/`ro_office_id`/`sdo_office_id` are the only write path
 * (see PersonnelStoreRequest/PersonnelUpdateRequest). The legacy string
 * columns they replaced (`position`, `ro_division`, `division_unit`) were
 * dropped outright in the ADR's Step 4 cleanup — they no longer exist in
 * the schema or in $fillable.
 *
 * RELATIONSHIP NAMING (collision avoidance, historical — same trap
 * Equipment hit, see that model's doc-comment for the general shape of
 * the problem): `position` used to be a real, fillable string *column* on
 * this table (dropped in the ADR's Step 4 cleanup — see above). While it
 * still existed, Eloquent's getAttribute() checked the raw $attributes
 * array before it ever considered a same-named relationship method, so a
 * relation literally named position() would have silently been
 * unreachable via $personnel->position (the magic accessor would always
 * resolve to the legacy string column instead), even though
 * with('position') would still *load* it under the hood — a trap, not a
 * fix. It was therefore named personnelPosition() instead, mirroring
 * Equipment's equipmentCategory()/equipmentClassification() precedent
 * (prefix with the model name to dodge the column, since "position" alone
 * has no collision-free synonym the way item_type -> itemType did).
 * `ro_division` and `division_unit` were real columns too, but neither
 * collided with the natural relation names (`roOffice`, `sdoOffice`), so
 * those kept the obvious names, same as Equipment's
 * item/brand_manufacturer -> itemType/brand. These relation names are now
 * permanent regardless of the legacy columns' removal — Backend/Frontend
 * already depend on them. No scope on this model shares a name with any
 * of the three relations (checked scopeActive()/scopeSearch() above —
 * neither is position/roOffice/sdoOffice), so unlike Equipment's
 * equipmentCondition()/scopeCondition() collision, there was never a
 * second trap here to route around.
 */
#[Fillable([
    'employee_id', 'last_name', 'first_name', 'middle_name', 'suffix',
    'oic', 'oic_office',
    'mobile_1', 'mobile_2', 'deped_email', 'personal_email', 'date_hired',
    'non_deped_funded', 'fund_source', 'inactive', 'separation_date',
    'separation_cause', 'transferred_from', 'transferred_to',
    'position_id', 'ro_office_id', 'sdo_office_id',
])]
class Personnel extends Model
{
    /** @use HasFactory<PersonnelFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'personnel';

    protected function casts(): array
    {
        return [
            'oic' => 'boolean',
            'non_deped_funded' => 'boolean',
            'inactive' => 'boolean',
            'date_hired' => 'date',
            'separation_date' => 'date',
            'fund_source' => 'array',
        ];
    }

    /**
     * @return HasMany<Equipment, $this>
     */
    public function currentlyAccountableForEquipment(): HasMany
    {
        return $this->hasMany(Equipment::class, 'current_accountable_officer_id');
    }

    /**
     * @return HasMany<Equipment, $this>
     */
    public function currentlyEndUserForEquipment(): HasMany
    {
        return $this->hasMany(Equipment::class, 'current_end_user_id');
    }

    /**
     * @return HasMany<EquipmentTransaction, $this>
     */
    public function equipmentTransactionsAsAccountableOfficer(): HasMany
    {
        return $this->hasMany(EquipmentTransaction::class, 'accountable_officer_id');
    }

    /**
     * @return HasMany<EquipmentTransaction, $this>
     */
    public function equipmentTransactionsAsEndUser(): HasMany
    {
        return $this->hasMany(EquipmentTransaction::class, 'end_user_id');
    }

    /**
     * @return HasMany<EquipmentTransaction, $this>
     */
    public function equipmentTransactionsReceived(): HasMany
    {
        return $this->hasMany(EquipmentTransaction::class, 'received_by_id');
    }

    /**
     * Named personnelPosition(), not the shorter position() — that name
     * collides with the still-live legacy `position` string column. See
     * the model doc-comment's "RELATIONSHIP NAMING" note.
     *
     * @return BelongsTo<Position, $this>
     */
    public function personnelPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    /**
     * @return BelongsTo<RoOffice, $this>
     */
    public function roOffice(): BelongsTo
    {
        return $this->belongsTo(RoOffice::class);
    }

    /**
     * @return BelongsTo<SdoOffice, $this>
     */
    public function sdoOffice(): BelongsTo
    {
        return $this->belongsTo(SdoOffice::class);
    }

    /**
     * Currently-employed personnel only (excludes separated/transferred-out
     * records, which are retained for historical accountability lookups).
     *
     * @param  Builder<Personnel>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('inactive', false);
    }

    /**
     * Free-text match against name and identifying fields. Blank/null
     * terms are a no-op so this can be chained unconditionally from a
     * controller without an if/else around it.
     *
     * Deliberately NOT extended to match against position_id/ro_office_id/
     * sdo_office_id (or their related tables' names) as part of the
     * lookup-normalization ADR's Tier 1 cutover — this scope never
     * searched the legacy `position`/`ro_division`/`division_unit` string
     * columns either (confirmed against the pre-cutover implementation),
     * so there is no existing behavior to preserve. Adding a whereHas()
     * join here would be new scope, not parity, and nothing in the
     * Personnel index UI has asked for "search by office/position" — YAGNI
     * per CLAUDE.md until that's a real request.
     *
     * @param  Builder<Personnel>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $query->where(function (Builder $query) use ($term) {
            $query->where('full_name', 'like', "%{$term}%")
                ->orWhere('employee_id', 'like', "%{$term}%")
                ->orWhere('deped_email', 'like', "%{$term}%");
        });
    }
}
