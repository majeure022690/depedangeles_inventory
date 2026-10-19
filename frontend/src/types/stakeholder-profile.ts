import type { Option } from '@/types/lookup'

/** The office a one-per-office form belongs to, as returned alongside it. */
export interface ProfileOffice {
  id: number
  office_name: string
  school_id: string | number | null
}

/** One row of `GET /api/stakeholder-profiles` (`StakeholderProfileOfficeResource`). */
export interface StakeholderProfileOfficeRow {
  id: number
  office_name: string
  office_type: string | null
  school_id: string | number | null
  has_profile: boolean
  updated_at: string | null
}

/**
 * `StakeholderProfileResource`. The four checkbox-group fields are arrays of
 * `stakeholder_libraries` ids; `complete_address` is read-only, built
 * server-side from street + PSGC barangay/municipality/province.
 */
export interface StakeholderProfile {
  id: number
  office_id: number
  governance_level: string | null
  ro: string | null
  sdo: string | null
  school_district: string | null
  school_name: string | null
  school_id: string | null
  province_id: number | null
  municipality_id: number | null
  legislative_district: string | null
  barangay_id: number | null
  street: string | null
  complete_address: string | null
  notes_corrections: string | null
  notes_recent_development: string | null
  mobile_1: string | null
  mobile_2: string | null
  landline: string | null
  chief_name: string | null
  chief_position: string | null
  chief_email: string | null
  chief_mobile: string | null
  admin_staff_name: string | null
  admin_staff_position: string | null
  admin_staff_email: string | null
  admin_staff_mobile: string | null
  network_administrator_name: string | null
  longitude: number | null
  latitude: number | null
  nearby_institutions: number[] | null
  nearby_institutions_other: string | null
  travel_time_to_center_minutes: number | null
  access_paths: number[] | null
  transportation_options: number[] | null
  transportation_other: string | null
  transportation_difficult: boolean | null
  considered_very_remote: boolean | null
  remote_context_notes: string | null
  gidca: boolean | null
  lms: boolean | null
  community_engagement: number[] | null
  community_context_remarks: string | null
  /** ISO-8601 timestamp. */
  submitted_at: string | null
  transaction_type: string | null
  updated_at: string | null
}

/** `GET/PUT /api/stakeholder-profiles/{officeId}`. */
export interface StakeholderProfileEnvelope {
  office: ProfileOffice
  profile: StakeholderProfile
}

/**
 * `GET /api/stakeholder-profiles/options`. The PSGC lists are the whole
 * Region III tree, filtered client-side by `parent_id`.
 */
export interface StakeholderProfileOptions {
  governance_level: Option<string>[]
  transaction_type: Option<string>[]
  nearby_institution: Option<number>[]
  type_of_access_road: Option<number>[]
  by_transportation: Option<number>[]
  community_engagement: Option<number>[]
  province: Option<number>[]
  municipality: Option<number>[]
  barangay: Option<number>[]
}

/** Form state: text inputs as strings (`''` = empty), ids as `number | null`. */
export interface StakeholderProfileFormData {
  governance_level: string
  ro: string
  sdo: string
  school_district: string
  school_name: string
  school_id: string
  province_id: number | null
  municipality_id: number | null
  legislative_district: string
  barangay_id: number | null
  street: string
  notes_corrections: string
  notes_recent_development: string
  mobile_1: string
  mobile_2: string
  landline: string
  chief_name: string
  chief_position: string
  chief_email: string
  chief_mobile: string
  admin_staff_name: string
  admin_staff_position: string
  admin_staff_email: string
  admin_staff_mobile: string
  network_administrator_name: string
  longitude: string
  latitude: string
  nearby_institutions: number[]
  nearby_institutions_other: string
  travel_time_to_center_minutes: string
  access_paths: number[]
  transportation_options: number[]
  transportation_other: string
  transportation_difficult: boolean
  considered_very_remote: boolean
  remote_context_notes: string
  gidca: boolean
  lms: boolean
  community_engagement: number[]
  community_context_remarks: string
  /** `datetime-local` value in the browser's time zone. */
  submitted_at: string
  transaction_type: string
}
