import type { Option, PersonOption } from '@/types/lookup'

export type InventoryCountStatus = 'draft' | 'open' | 'closed' | 'cancelled'

export type CountResult = 'pending' | 'found' | 'not_found'

export type CountBucket = 'pending' | 'matched' | 'condition_changed' | 'holder_changed' | 'not_found'

export interface CountOfficeProgress {
  office_id: number
  total: number
  found: number
  not_found: number
  pending: number
  found_at_station: number
}

/** `InventoryCountResource`. `offices` and `summary` only on the detail endpoint. */
export interface InventoryCount {
  id: number
  name: string
  as_of_date: string
  status: InventoryCountStatus
  office_ids: number[]
  offices?: { id: number; office_name: string; school_id: number | null }[]
  remarks: string | null
  items_count?: number
  counted_count?: number
  summary?: {
    offices: CountOfficeProgress[]
    buckets: Record<CountBucket, number>
    found_at_station: number
  }
  opened_at: string | null
  closed_at: string | null
  created_at: string | null
  updated_at: string | null
}

/** `InventoryCountItemResource`: `recorded` is the Equipment record when the count opened, `found` what the counter saw. */
export interface InventoryCountItem {
  id: number
  equipment: {
    id: number
    property_no: string | null
    old_property_no: string | null
    serial_number: string | null
    item_type: string | null
    description: string
  }
  office: { id: number; office_name: string } | null
  recorded: {
    condition_id: number | null
    condition: string | null
    accountable_officer_id: number | null
    accountable_officer: string | null
    location: string | null
  }
  result: CountResult
  found: {
    condition_id: number | null
    condition: string | null
    holder_id: number | null
    holder: string | null
    location: string | null
  }
  remarks: string | null
  bucket: CountBucket
  condition_changed: boolean
  holder_changed: boolean
  unserviceable: boolean
  counted_by: string | null
  counted_at: string | null
}

/** `InventoryCountFoundItemResource`: PPE found at station. */
export interface InventoryCountFoundItem {
  id: number
  office: { id: number; office_name: string } | null
  equipment: { id: number; property_no: string | null; description: string } | null
  description: string | null
  property_no: string | null
  serial_no: string | null
  condition: string | null
  location: string | null
  remarks: string | null
  counted_by: string | null
  counted_at: string | null
}

/** `GET /api/inventory-counts/options`; `people` only for the `office_ids` sent. */
export interface InventoryCountOptions {
  office: Option<number>[]
  condition: Option<number>[]
  name_suggestions: string[]
  people: PersonOption[]
}

export interface InventoryCountLookup {
  item: InventoryCountItem | null
  equipment: { id: number; property_no: string | null; serial_number: string | null; description: string } | null
}

export interface InventoryCountFormData {
  name: string
  as_of_date: string
  office_ids: number[]
  remarks: string
}

export interface CountItemUpdate {
  result: CountResult
  found_condition_id?: number | null
  found_holder_id?: number | null
  found_location?: string | null
  remarks?: string | null
}

export interface FoundItemInput {
  office_id: number
  equipment_id?: number | null
  description?: string | null
  property_no?: string | null
  serial_no?: string | null
  condition_id?: number | null
  location?: string | null
  remarks?: string | null
}

export const STATUS_LABELS: Record<InventoryCountStatus, string> = {
  draft: 'Draft',
  open: 'Open',
  closed: 'Closed',
  cancelled: 'Cancelled',
}

export const STATUS_COLORS: Record<InventoryCountStatus, 'gray' | 'primary' | 'success' | 'error'> = {
  draft: 'gray',
  open: 'primary',
  closed: 'success',
  cancelled: 'error',
}

export const BUCKET_LABELS: Record<CountBucket, string> = {
  pending: 'Not yet counted',
  matched: 'Matched',
  condition_changed: 'Condition changed',
  holder_changed: 'Different holder or location',
  not_found: 'Not found',
}

export const BUCKET_COLORS: Record<CountBucket, 'gray' | 'success' | 'warning' | 'error'> = {
  pending: 'gray',
  matched: 'success',
  condition_changed: 'warning',
  holder_changed: 'warning',
  not_found: 'error',
}
