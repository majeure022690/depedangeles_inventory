<?php

namespace App\Concerns;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared read pattern for every Tier 2 domain-grouped library table
 * introduced by the lookup-normalization ADR (docs/architecture-decisions/
 * lookup-normalization.md) — equipment_libraries, personnel_libraries,
 * stakeholder_libraries, connectivity_libraries. Each stays discriminated
 * by a `type` column (backed by that table's own small per-domain enum),
 * so the shape mirrors App\Models\Lookup's activeValues()/optionsFor()
 * pair exactly, just repointed at a domain-scoped table.
 *
 * `activeOptions()` ships `{value, label}` where both are the row's
 * `value` string — Tier 2 consumer columns stay validated strings, not FK
 * ids, per the ADR's Question 2.
 */
trait HasActiveLibraryOptions
{
    /**
     * Active rows only, in display order.
     *
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * Plain list of active values for a given library type, in display
     * order — e.g. for building a Rule::in() validation list.
     *
     * @return array<int, string>
     */
    public static function activeValues(BackedEnum $type): array
    {
        return static::query()
            ->where('type', $type->value)
            ->active()
            ->pluck('value')
            ->all();
    }

    /**
     * {value, label} pairs for a given library type, in display order —
     * the shape every dropdown/select component consumes.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function activeOptions(BackedEnum $type): array
    {
        return array_map(
            static fn (string $value): array => ['value' => $value, 'label' => $value],
            static::activeValues($type)
        );
    }
}
