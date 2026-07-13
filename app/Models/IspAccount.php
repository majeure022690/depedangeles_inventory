<?php

namespace App\Models;

use Database\Factories\IspAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fillable/casts/relationships plus the small set of query scopes the
 * index page's search/filter bar needs — mirrors Equipment's scope shape
 * (search + a couple of equality filters), sized to what the index
 * actually uses rather than speculative reporting scopes.
 *
 * TIER 1 FK CUTOVER (lookup-normalization ADR, Step 2+3 — IspAccount
 * only): `isp_provider_id` is the only write path (see
 * IspAccountStoreRequest/IspAccountUpdateRequest). The legacy `isp`
 * string column it replaced was dropped outright in the ADR's Step 4
 * cleanup — it no longer exists in the schema or in $fillable.
 *
 * The remaining lookup-backed fields on this model (school_area_coverage,
 * subscription_type, isp_connection_type, purpose_of_subscription,
 * overall_signal_quality, rate_connectivity_admin_areas,
 * rate_connectivity_classroom, mode_of_acquisition, source_of_acquisition,
 * fund_source) are Tier 2 — still validated strings, now repointed at
 * ConnectivityLibrary/ConnectivityLibraryType (this resource's own domain
 * table) or, for the three fields shared with Equipment
 * (mode_of_acquisition, source_of_acquisition, fund_source), at the
 * already-migrated EquipmentLibrary/EquipmentLibraryType — a deliberate
 * cross-domain read the ADR calls out as expected and fine, not a
 * duplication of that data into connectivity_libraries.
 *
 * RELATIONSHIP NAMING: `isp` used to be a real, fillable string *column*
 * on this table (dropped in the ADR's Step 4 cleanup — see above), so —
 * same trap Equipment's category()/classification() and Personnel's
 * position() hit (see those models' doc-comments) — a relation literally
 * named isp() would have silently been unreachable via $ispAccount->isp
 * while that column still existed (the magic accessor would always
 * resolve to the legacy string column instead). Unlike those cases,
 * though, there was no need to route around it here: the natural relation
 * name for `isp_provider_id` is ispProvider(), which never collided with
 * the `isp` column at all. No local scope on this model is named
 * ispProvider either, so there's no second
 * (Equipment::equipmentCondition()-style) trap to dodge.
 */
#[Fillable([
    'cost_per_month', 'isp_billing_account_no', 'package_purchased_inclusion',
    'school_area_coverage', 'min_speed', 'max_speed', 'subscription_type',
    'isp_connection_type', 'contract_start_date', 'contract_end_date',
    'inactive_contract', 'purpose_of_subscription', 'mode_of_acquisition',
    'source_of_acquisition', 'donor', 'fund_source', 'number_access_points_linked',
    'locations_access_points', 'number_admin_area_covered', 'rate_connectivity_admin_areas',
    'number_classrooms_covered', 'rate_connectivity_classroom', 'overall_signal_quality',
    'rate_isp_service', 'isp_provider_id',
])]
class IspAccount extends Model
{
    /** @use HasFactory<IspAccountFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'cost_per_month' => 'decimal:2',
            'min_speed' => 'decimal:2',
            'max_speed' => 'decimal:2',
            'contract_start_date' => 'date',
            'contract_end_date' => 'date',
            'inactive_contract' => 'boolean',
            'number_access_points_linked' => 'integer',
            'number_admin_area_covered' => 'integer',
            'number_classrooms_covered' => 'integer',
            'rate_isp_service' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<IspProvider, $this>
     */
    public function ispProvider(): BelongsTo
    {
        return $this->belongsTo(IspProvider::class);
    }

    /**
     * @return HasMany<IspSpeedTest, $this>
     */
    public function speedTests(): HasMany
    {
        return $this->hasMany(IspSpeedTest::class);
    }

    /**
     * @return HasMany<IspSubscriptionCost, $this>
     */
    public function subscriptionCosts(): HasMany
    {
        return $this->hasMany(IspSubscriptionCost::class);
    }

    /**
     * Free-text match against the identifiers people actually search by.
     * Blank/null terms are a no-op so this can be chained unconditionally.
     *
     * Matches against `ispProvider.name` via whereHas(), not the legacy
     * `isp` string column — the pre-cutover implementation searched `isp`
     * directly, but that column goes stale (null) on every record created
     * post-cutover (see the model doc-comment's "TIER 1 FK CUTOVER" note),
     * so leaving this unchanged would silently stop matching any new ISP
     * account by provider name. This is the same "screen reading the old
     * raw string column" risk the lookup-normalization ADR flags in its
     * rollout-risk section — fixed here rather than deferred, since it's
     * this exact model's own scope.
     *
     * @param  Builder<IspAccount>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $query->where(function (Builder $query) use ($term) {
            $query->whereHas('ispProvider', fn (Builder $query) => $query->where('name', 'like', "%{$term}%"))
                ->orWhere('isp_billing_account_no', 'like', "%{$term}%");
        });
    }

    /**
     * Filters by `isp_provider_id` (Tier 1 FK, lookup-normalization ADR) —
     * no longer the legacy `isp` string column.
     *
     * @param  Builder<IspAccount>  $query
     */
    public function scopeProvider(Builder $query, ?int $ispProviderId): void
    {
        if (filled($ispProviderId)) {
            $query->where('isp_provider_id', $ispProviderId);
        }
    }

    /**
     * Filter by the boolean `inactive_contract` state via a two-value
     * "active"/"inactive" string — not lookup-backed (it's a plain
     * boolean column, not a shared vocabulary list), so the frontend
     * ships this as a hardcoded two-option dropdown rather than an
     * `options.*` lookup list.
     *
     * @param  Builder<IspAccount>  $query
     */
    public function scopeStatus(Builder $query, ?string $status): void
    {
        if ($status === 'active') {
            $query->where('inactive_contract', false);
        } elseif ($status === 'inactive') {
            $query->where('inactive_contract', true);
        }
    }
}
