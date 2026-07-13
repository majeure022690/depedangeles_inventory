<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Shared read pattern for every Tier 1 reference-data table introduced by
 * the lookup-normalization ADR (docs/architecture-decisions/
 * lookup-normalization.md) — item_types, brands, equipment_categories,
 * equipment_classifications, equipment_conditions, positions, ro_offices,
 * sdo_offices, isp_providers. Each is its own dedicated entity table (no
 * `type` discriminator — that's the whole point of Tier 1), so the shape
 * mirrors App\Models\Lookup's activeValues()/optionsFor() pair without a
 * type parameter.
 *
 * `activeOptions()` ships `{value: id, label: name}` (id-typed, matching
 * the ADR's Step 2.3 prop-building note for Tier 1 fields — these become
 * real FK columns, so the option value IS the row id, not the name).
 */
trait HasActiveReferenceOptions
{
    /**
     * Active rows only, in display order. The primary read pattern for
     * every Tier 1 dropdown in the app.
     *
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * Plain list of active names, in display order.
     *
     * @return array<int, string>
     */
    public static function activeValues(): array
    {
        return static::query()->active()->pluck('name')->all();
    }

    /**
     * {value, label} pairs for every active row, in display order — value
     * is the row id (this is a real FK target), label is the display name.
     *
     * @return array<int, array{value: int, label: string}>
     */
    public static function activeOptions(): array
    {
        return static::query()
            ->active()
            ->get(['id', 'name'])
            ->map(static fn ($row): array => ['value' => $row->id, 'label' => $row->name])
            ->all();
    }
}
