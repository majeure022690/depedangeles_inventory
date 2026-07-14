export type LookupOption = {
    value: string;
    label: string;
};

/**
 * Tier 1 reference-table option (lookup-normalization ADR) — the option's
 * `value` is the row's real integer id, not a string. Used for fields that
 * have cut over to a dedicated reference table with a real FK
 * (`item_type_id`, `brand_id`, etc. on Equipment; other resources cut over
 * later per the ADR's Step 5 sequencing).
 */
export type ReferenceOption = {
    value: number;
    label: string;
};

/**
 * A ReferenceOption scoped to a parent row (e.g. a municipality scoped to
 * its province) — used for cascading dropdowns, where the frontend filters
 * the full option list down to `parent_id === <selected parent's id>`
 * client-side rather than round-tripping per selection.
 */
export type ScopedReferenceOption = ReferenceOption & {
    parent_id: number;
};
