<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use BackedEnum;

/**
 * Shared helper for controllers that need {value, label} dropdown options,
 * sourced from the lookup-normalization ADR's tiered reference tables (the
 * old global Lookup/LookupType system and its lookupOptions() helper have
 * been fully removed — every resource is cut over).
 *
 * `referenceOptions()`/`libraryOptions()` (lookup-normalization ADR, Step 2)
 * are the two main helpers: `referenceOptions()` serves Tier 1 (dedicated
 * entity tables, id-valued options) and `libraryOptions()` serves Tier 2
 * (domain-grouped library tables, string-valued options — `{value, label}`,
 * both the row's `value` string).
 *
 * `libraryOptionsById()` is a further Step 2 addition, added for
 * Personnel's cutover: it serves the narrow case of a Tier 2 library type
 * backing a JSON-array multi-select column (ADR Question 4 — e.g.
 * Personnel.fund_source against PersonnelLibraryType::TeachersFundingSource),
 * where the *stored* value is the library row's own id, not its `value`
 * string, unlike every other Tier 2 field.
 */
trait HasLookupOptions
{
    /**
     * Tier 1 reference-table options — each entry ships
     * `{value: id, label: name}`, matching the ADR's Step 2.3 prop-building
     * note (Tier 1 fields are real FKs, so the option value IS the row
     * id). Keyed by whatever string the caller chooses (typically the
     * field's conceptual name, e.g. 'item_type', 'brand') so Frontend can
     * destructure `options.item_type`, `options.brand`, etc.
     *
     * @param  array<string, class-string>  $modelsByKey  e.g. ['item_type' => ItemType::class]
     * @return array<string, array<int, array{value: int, label: string}>>
     */
    protected function referenceOptions(array $modelsByKey): array
    {
        $options = [];

        foreach ($modelsByKey as $key => $modelClass) {
            $options[$key] = $modelClass::activeOptions();
        }

        return $options;
    }

    /**
     * Tier 2 library-table options — each entry ships `{value, label}`
     * (both the row's `value` string), sourced from a domain-grouped
     * `{Domain}Library` table + its own small backed enum.
     *
     * @param  class-string  $libraryClass  e.g. EquipmentLibrary::class
     * @param  array<string, BackedEnum>  $typesByKey  e.g. ['unit_of_measure' => EquipmentLibraryType::UnitOfMeasure]
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    protected function libraryOptions(string $libraryClass, array $typesByKey): array
    {
        $options = [];

        foreach ($typesByKey as $key => $type) {
            $options[$key] = $libraryClass::activeOptions($type);
        }

        return $options;
    }

    /**
     * Id-valued Tier 2 library options for a single library type — for the
     * narrow case of a JSON-array multi-select column whose stored
     * elements are library row ids rather than value strings (ADR
     * Question 4). Ships `{value: id, label: value}`, active rows only, in
     * display order — the same active()/sort_order ordering
     * HasActiveLibraryOptions uses elsewhere, just selecting `id` instead
     * of building the plain value list.
     *
     * @param  class-string  $libraryClass  e.g. PersonnelLibrary::class
     * @return array<int, array{value: int, label: string}>
     */
    protected function libraryOptionsById(string $libraryClass, BackedEnum $type): array
    {
        return $libraryClass::query()
            ->where('type', $type->value)
            ->active()
            ->get(['id', 'value'])
            ->map(static fn ($row): array => ['value' => $row->id, 'label' => $row->value])
            ->all();
    }
}
