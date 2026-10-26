import type { Option } from '@/types/lookup'

/**
 * `PersonnelResource`. Tier 1 fields ship the FK id the form binds to
 * (`position_id`, `ro_office_id`, `sdo_office_id`) and the resolved
 * display name (`position`, `ro_division`, `division_unit`). `fund_source`
 * is an array of `personnel_libraries` row ids.
 */
export interface Personnel {
  id: number
  employee_id: string
  full_name: string
  last_name: string
  first_name: string
  middle_name: string | null
  suffix: string | null
  position_id: number | null
  position: string | null
  ro_office_id: number | null
  ro_division: string | null
  sdo_office_id: number | null
  division_unit: string | null
  office_id: number | null
  office: string | null
  oic: boolean
  oic_office: string | null
  mobile_1: string | null
  mobile_2: string | null
  deped_email: string | null
  personal_email: string | null
  date_hired: string | null
  non_deped_funded: boolean
  fund_source: number[] | null
  inactive: boolean
  separation_date: string | null
  separation_cause: string | null
  transferred_from: string | null
  transferred_to: string | null
  created_at: string | null
  updated_at: string | null
}

/**
 * `GET /api/personnel/options`. Tier 1 (`position`, `ro_office`,
 * `sdo_office`) and `teachers_funding_source` are id-valued; the Tier 2
 * lists are string-valued.
 */
export interface PersonnelOptions {
  position: Option<number>[]
  ro_office: Option<number>[]
  sdo_office: Option<number>[]
  office: Option<number>[]
  name_suffix: Option<string>[]
  cause_of_separation: Option<string>[]
  teachers_funding_source: Option<number>[]
}

export type PersonnelStatusFilter = 'active' | 'inactive' | 'all'

/** Form state for create/edit, mirroring Store/UpdatePersonnelRequest. */
export interface PersonnelFormData {
  employee_id: string
  last_name: string
  first_name: string
  middle_name: string
  suffix: string
  ro_office_id: number | null
  sdo_office_id: number | null
  office_id: number | null
  position_id: number | null
  oic: boolean
  oic_office: string
  mobile_1: string
  mobile_2: string
  deped_email: string
  personal_email: string
  date_hired: string
  non_deped_funded: boolean
  fund_source: number[]
  inactive: boolean
  separation_date: string
  separation_cause: string
  transferred_from: string
  transferred_to: string
}

export type PersonnelPayload = { [K in keyof PersonnelFormData]: PersonnelFormData[K] | null }
