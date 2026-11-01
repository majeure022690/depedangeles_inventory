import type { Option } from '@/types/lookup'

/** A personnel picker entry (`Personnel::toOption()`). */
export type PersonOption = Option<number> & { position: string | null }

export type SchoolLevel = 'elementary' | 'junior_high' | 'senior_high'

export const RADIO_GRADES = ['grade_1', 'grade_2', 'grade_3', 'grade_4', 'grade_5', 'grade_6'] as const

export type RadioGrade = (typeof RADIO_GRADES)[number]

/** `GspIctProfileResource`. */
export interface GspProfile {
  id: number
  office_id: number
  office?: { id: number; office_name: string; school_id: number | null } | null
  school_year: string
  as_of: string
  level: SchoolLevel
  level_label: string
  radio_teachers: Partial<Record<RadioGrade, { literacy?: number | null; numeracy?: number | null }>>
  dict_free_wifi: string | null
  dict_free_wifi_installed: string | null
  isp_reliability: Record<string, string>
  school_head_id: number | null
  school_head?: PersonOption | null
  ito_id: number | null
  ito?: PersonOption | null
  planning_officer_id: number | null
  planning_officer?: PersonOption | null
  created_at: string | null
  updated_at: string | null
}

/** `GET /api/gsp-profiles/options`; `isp_accounts` only comes back when `office_id` is sent. */
export interface GspProfileOptions {
  office: (Option<number> & { default_level: SchoolLevel })[]
  level: Option<SchoolLevel>[]
  dict_free_wifi: Option<string>[]
  reliability: Option<string>[]
  itos: PersonOption[]
  planning_officers: PersonOption[]
  school_heads?: PersonOption[]
  isp_accounts?: (Option<number> & { connection_type: string | null })[]
}

export interface GspProfileFormData {
  office_id: string
  school_year: string
  as_of: string
  level: SchoolLevel
  radio_teachers: Record<RadioGrade, { literacy: string; numeracy: string }>
  dict_free_wifi: string
  dict_free_wifi_installed: string
  isp_reliability: Record<string, string>
  school_head_id: string
  ito_id: string
  planning_officer_id: string
}

export type FundKey = 'dcp' | 'deped_non_dcp' | 'lgu_sef' | 'pta_private' | 'other_government' | 'others'

/** `GET /api/gsp-profiles/preview`: what the workbook reads from IRIS records. */
export interface GspIrisData {
  table_1: {
    funds: { key: FundKey; label: string }[]
    types: { key: string; label: string }[]
    /** group (learner, teacher, personnel) -> level (or `all`) -> computer type -> fund -> count; missing means 0. */
    counts: Partial<Record<string, Partial<Record<string, Partial<Record<string, Partial<Record<FundKey, number>>>>>>>>
  }
  table_2: { label: string; dcp: number; deped_non_dcp: number; others: number }[]
  table_3: { years: number[]; rows: { label: string; counts: Record<string, number> }[] }
  box_1: {
    available_isps: { label: string; checked: boolean }[]
    coverage: { label: string; checked: boolean }[]
    subscriptions: {
      account_id: number
      provider: string | null
      connection_type: string | null
      level: string | null
      purpose: string | null
      monthly_expense: number | null
      funding_source: string | null
      subscribed_download: number | null
      subscribed_upload: number | null
      ping: number | null
      download: number | null
      upload: number | null
      service_quality: string | null
    }[]
    has_survey: boolean
  }
}
