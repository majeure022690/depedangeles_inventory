import type { LookupOption } from '@/types/lookup';

/**
 * One entry per physical reference table, as surfaced by
 * ReferenceDataController::index() from config/reference-data.php (see
 * docs/architecture-decisions/lookup-normalization.md).
 */
export type ReferenceDataTableSummary = {
    key: string;
    label: string;
    tier: 1 | 2;
    row_count: number;
};

/**
 * Tier 1 row shape (dedicated entity table, e.g. item_types, brands) — has
 * a real `name` column, no `type` discriminator.
 */
export type ReferenceDataTier1Row = {
    id: number;
    name: string;
    description: string | null;
    sort_order: number;
    is_active: boolean;
};

/**
 * Tier 2 row shape (domain-grouped library table, e.g. equipment_libraries)
 * — discriminated by `type`, value is permanently read-only (mirrors the
 * old lookups table's `value` immutability, see ReferenceDataUpdateRequest).
 */
export type ReferenceDataTier2Row = {
    id: number;
    type: string;
    value: string;
    description: string | null;
    sort_order: number;
    is_active: boolean;
};

export type ReferenceDataFilters = {
    search: string | null;
    type: string | null;
};

export type { LookupOption as ReferenceDataTypeOption };

/**
 * Shape handed to Inertia's useForm() for the edit dialog. `name` is only
 * ever submitted for Tier 1 rows — ReferenceDataUpdateRequest ignores it
 * entirely for Tier 2 (type/value stay immutable).
 */
export type ReferenceDataFormData = {
    name?: string;
    description: string;
    sort_order: string;
    is_active: boolean;
};

/**
 * Shape handed to Inertia's useForm() for the create dialog. Mirrors
 * ReferenceDataStoreRequest's tier split: Tier 1 submits `name`, Tier 2
 * submits `type` (picked once, at create time, from the table's own enum —
 * see the `types` prop) + `value`. Both tiers share description/sort_order/
 * is_active.
 */
export type ReferenceDataCreateFormData = {
    name?: string;
    type?: string;
    value?: string;
    description: string;
    sort_order: string;
    is_active: boolean;
};
