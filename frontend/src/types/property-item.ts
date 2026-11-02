import type { Option } from '@/types/lookup'

/** Query filters shared by `GET /api/property-items/summary` and `/equipment`; blanks are omitted. */
export type SchoolPropertyFilters = {
  search: string
  office_id: string
  item_type_id: string
  brand_id: string
  functional: '' | 'functional' | 'non_functional'
  equipment_condition_id: string
  disposition_status: string
  estimated_useful_life: string
  transaction_type: string
  accountable_officer_id: string
  received_by_id: string
}

/** `GET /api/property-items/options`. */
export interface SchoolPropertyOptions {
  office: Option<number>[]
  item_type: Option<number>[]
  brand: Option<number>[]
  equipment_condition: Option<number>[]
  disposition_status: Option<string>[]
  transaction_type: Option<string>[]
  estimated_useful_life: Option<number>[]
  accountable_officer: Option<number>[]
  received_by: Option<number>[]
}

export interface SchoolPropertyOfficeRow {
  office_id: number | null
  office_name: string | null
  on_hand: number
  missing: number
  total_cost: number
}

/** `GET /api/property-items/summary`. */
export interface SchoolPropertySummary {
  offices: SchoolPropertyOfficeRow[]
  totals: { on_hand: number; missing: number; total_cost: number }
}

/** `GET /api/property-items/equipment`: one item on hand behind an office row. */
export interface PropertyItemEquipment {
  id: number
  article: string | null
  description: string
  property_no: string | null
  office: string | null
  unit_value: number | null
  semi_expendable: boolean
  on_hand: number
  condition: string | null
  disposition_status: string | null
  accountable_officer: string | null
  end_user: string | null
}
