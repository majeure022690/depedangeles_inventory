<?php

namespace App\Models;

use App\Exceptions\AccountabilitySyncRequiredException;
use Closure;
use Database\Factories\EquipmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bare-bones structural model — fillable/casts/relationships only.
 * Backend owns: the logic (service method or model Observer) that
 * appends an equipment_transactions row and syncs
 * current_accountable_officer_id / current_end_user_id whenever
 * accountability changes — NOT implemented here. Also owns any
 * condition/category/disposition query scopes and QR code derivation.
 *
 * ACCOUNTABILITY SYNC GUARD: current_accountable_officer_id /
 * current_end_user_id must only ever change via
 * App\Services\EquipmentAccountabilityService::recordTransaction(), which
 * writes the corresponding equipment_transactions row in the same DB
 * transaction. An `updating` hook below throws if either column is dirty
 * outside that path, so a controller can't drift the "current holder"
 * pointers out of sync with the audit log by updating them directly.
 * Only guarded on UPDATE (not CREATE) — initial creation (factories,
 * seeders, an import pipeline) has no prior holder to reconcile against,
 * so it's not a "change of hands" the audit log needs to capture.
 *
 * QR CODE: `qr_code` is deliberately NOT in $fillable (below) — it's
 * entirely system-derived, never user input. App\Observers\EquipmentObserver
 * populates it automatically (via forceFill()+saveQuietly(), bypassing both
 * mass-assignment protection and the ACCOUNTABILITY SYNC GUARD above,
 * neither of which applies to this column) the moment a record has an id
 * to encode a URL for. See that Observer's doc-comment for what it encodes
 * and why.
 *
 * TIER 1 FK CUTOVER (lookup-normalization ADR, Step 2 — Equipment only):
 * `item_type_id`/`brand_id`/`equipment_category_id`/
 * `equipment_classification_id`/`equipment_condition_id` are the only
 * write path (see EquipmentStoreRequest/EquipmentUpdateRequest). The
 * legacy string columns they replaced (`item`, `brand_manufacturer`,
 * `category`, `classification`, `equipment_condition`) were dropped
 * outright in the ADR's Step 4 cleanup — they no longer exist in the
 * schema or in $fillable.
 *
 * RELATIONSHIP NAMING (collision avoidance, historical): `category` and
 * `classification` used to be real, fillable string *columns* on this
 * table (dropped in the ADR's Step 4 cleanup — see above). While they
 * still existed, Eloquent's getAttribute() checked the raw $attributes
 * array before it ever considered a same-named relationship method, so a
 * relation literally named category()/classification() would have
 * silently been unreachable via $equipment->category / ->classification
 * (the magic accessor would always resolve to the legacy string column
 * instead) even though with('category') would still *load* it under the
 * hood — a trap, not a fix. Both were therefore named after their FK
 * column instead: equipmentCategory() (`equipment_category_id`) and
 * equipmentClassification() (`equipment_classification_id`). `item` and
 * `brand_manufacturer` were real columns too, but neither collided with
 * the natural relation names (`itemType`, `brand`), so those kept the
 * obvious names. These relation names are now permanent regardless of the
 * legacy columns' removal — Backend/Frontend already depend on them.
 *
 * A second, less obvious collision ruled out `condition()` as the
 * relation name for `equipment_condition_id`, even though the legacy
 * `equipment_condition` column itself didn't collide with it: this
 * model already exposes a `scopeCondition()` local scope, callable as
 * the static-looking `Equipment::condition(...)`. Query builder scope
 * dispatch only works through Eloquent's __callStatic/__call magic when
 * no *real* method of that name exists on the model — once a genuine
 * `condition()` instance method (the relation) is added, PHP resolves
 * `Equipment::condition(...)` to that real method first and fatals with
 * "Non-static method ... cannot be called statically" instead of ever
 * reaching the magic scope dispatch. The relation is therefore named
 * equipmentCondition() (`equipment_condition_id`) to keep both the scope
 * and the relation independently callable.
 */
#[Fillable([
    'property_no', 'old_property_no', 'serial_number', 'uom',
    'model', 'specifications', 'non_dcp',
    'dcp_package', 'dcp_year', 'gl_sl_code',
    'uacs', 'acquisition_cost', 'received_date', 'estimated_useful_life',
    'mode_acquisition', 'source_acquisition', 'donor', 'source_fund',
    'allotment_class', 'pm_plan', 'equipment_location', 'non_functional',
    'disposition_status', 'remarks',
    'under_warranty', 'end_warranty_date', 'supplier',
    'current_accountable_officer_id', 'current_end_user_id',
    'item_type_id', 'brand_id', 'equipment_category_id',
    'equipment_classification_id', 'equipment_condition_id',
])]
class Equipment extends Model
{
    /** @use HasFactory<EquipmentFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'non_dcp' => 'boolean',
            'non_functional' => 'boolean',
            'under_warranty' => 'boolean',
            'acquisition_cost' => 'decimal:2',
            'received_date' => 'date',
            'end_warranty_date' => 'date',
            'dcp_year' => 'integer',
            'estimated_useful_life' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Personnel, $this>
     */
    public function currentAccountableOfficer(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'current_accountable_officer_id');
    }

    /**
     * @return BelongsTo<Personnel, $this>
     */
    public function currentEndUser(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'current_end_user_id');
    }

    /**
     * @return HasMany<EquipmentTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(EquipmentTransaction::class);
    }

    /**
     * @return BelongsTo<ItemType, $this>
     */
    public function itemType(): BelongsTo
    {
        return $this->belongsTo(ItemType::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Named after the FK column, not the concept, to avoid colliding with
     * the still-live legacy `category` string column — see the model
     * doc-comment's "RELATIONSHIP NAMING" note.
     *
     * @return BelongsTo<EquipmentCategory, $this>
     */
    public function equipmentCategory(): BelongsTo
    {
        return $this->belongsTo(EquipmentCategory::class);
    }

    /**
     * Named after the FK column, not the concept, to avoid colliding with
     * the still-live legacy `classification` string column — see the
     * model doc-comment's "RELATIONSHIP NAMING" note.
     *
     * @return BelongsTo<EquipmentClassification, $this>
     */
    public function equipmentClassification(): BelongsTo
    {
        return $this->belongsTo(EquipmentClassification::class);
    }

    /**
     * Named equipmentCondition(), not the shorter condition() — that name
     * collides with this model's scopeCondition() local scope (callable
     * as `Equipment::condition(...)`), not with the legacy string column.
     * See the model doc-comment's "RELATIONSHIP NAMING" note.
     *
     * @return BelongsTo<EquipmentCondition, $this>
     */
    public function equipmentCondition(): BelongsTo
    {
        return $this->belongsTo(EquipmentCondition::class, 'equipment_condition_id');
    }

    /**
     * @var bool Flips true only inside withAccountabilitySync(), which is
     *           the sole caller allowed to touch current_* pointers on an
     *           existing (already-persisted) Equipment row.
     */
    private static bool $syncingAccountability = false;

    protected static function booted(): void
    {
        static::updating(function (Equipment $equipment) {
            if (self::$syncingAccountability) {
                return;
            }

            if ($equipment->isDirty(['current_accountable_officer_id', 'current_end_user_id'])) {
                throw new AccountabilitySyncRequiredException;
            }
        });
    }

    /**
     * The only sanctioned way to mutate current_accountable_officer_id /
     * current_end_user_id on an existing Equipment row. Used exclusively
     * by EquipmentAccountabilityService.
     */
    public static function withAccountabilitySync(Closure $callback): mixed
    {
        self::$syncingAccountability = true;

        try {
            return $callback();
        } finally {
            self::$syncingAccountability = false;
        }
    }

    /**
     * Free-text match against the identifiers people actually search by.
     * Blank/null terms are a no-op so this can be chained unconditionally.
     *
     * @param  Builder<Equipment>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $query->where(function (Builder $query) use ($term) {
            $query->where('property_no', 'like', "%{$term}%")
                ->orWhere('serial_number', 'like', "%{$term}%")
                ->orWhere('model', 'like', "%{$term}%");
        });
    }

    /**
     * Filters by `equipment_condition_id` (Tier 1 FK, lookup-normalization
     * ADR) — no longer the legacy `equipment_condition` string column.
     *
     * @param  Builder<Equipment>  $query
     */
    public function scopeCondition(Builder $query, ?int $conditionId): void
    {
        if (filled($conditionId)) {
            $query->where('equipment_condition_id', $conditionId);
        }
    }

    /**
     * Filters by `equipment_category_id` (Tier 1 FK, lookup-normalization
     * ADR) — no longer the legacy `category` string column.
     *
     * @param  Builder<Equipment>  $query
     */
    public function scopeCategory(Builder $query, ?int $categoryId): void
    {
        if (filled($categoryId)) {
            $query->where('equipment_category_id', $categoryId);
        }
    }

    /**
     * @param  Builder<Equipment>  $query
     */
    public function scopeDispositionStatus(Builder $query, ?string $status): void
    {
        if (filled($status)) {
            $query->where('disposition_status', $status);
        }
    }
}
