import type { Option } from '@/types/lookup'
import type { PaginatedResponse, PaginationMeta } from '@/types/api'

export type ReferenceDataTier = 1 | 2

/** One entry of GET /api/reference-data `tables`. `key` is the registry key used in URLs (e.g. `item-types`). */
export interface ReferenceDataTableSummary {
  key: string
  label: string
  tier: ReferenceDataTier
  row_count: number
}

export interface ReferenceDataOverview {
  tables: ReferenceDataTableSummary[]
  office_count: number
}

interface ReferenceDataRowBase {
  id: number
  description: string | null
  sort_order: number
  is_active: boolean
}

/** Tier 1 (dedicated table) row: `name` is editable. */
export interface ReferenceDataTier1Row extends ReferenceDataRowBase {
  name: string
}

/** Tier 2 (library table) row: `type` and `value` are fixed after creation. */
export interface ReferenceDataTier2Row extends ReferenceDataRowBase {
  type: string
  value: string
}

export type ReferenceDataRow = ReferenceDataTier1Row | ReferenceDataTier2Row

/** `meta.table` on GET /api/reference-data/{table}. `types` is null for Tier 1. */
export interface ReferenceDataTableMeta {
  key: string
  label: string
  tier: ReferenceDataTier
  types: Option<string>[] | null
}

export interface ReferenceDataPage extends PaginatedResponse<ReferenceDataRow> {
  meta: PaginationMeta & { table: ReferenceDataTableMeta }
}

/** POST body. Tier 1 sends `name`; Tier 2 sends `type` + `value`. */
export interface ReferenceDataCreatePayload {
  name?: string
  type?: string
  value?: string
  description: string | null
  sort_order: number
  is_active: boolean
}

/** PATCH body. `name` is only accepted for Tier 1; Tier 2 type/value are never editable. */
export interface ReferenceDataUpdatePayload {
  name?: string
  description: string | null
  sort_order: number
  is_active: boolean
}
