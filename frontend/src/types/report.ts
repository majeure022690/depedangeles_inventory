import type { Option, PersonOption } from '@/types/lookup'

/** `GET /api/reports/options`; `accountable_officers` only comes back when `office_id` is sent. */
export interface ReportOptions {
  offices: Option<number>[]
  supply_officers: PersonOption[]
  people?: PersonOption[]
  accountable_officers?: PersonOption[]
}

export interface RpcppeReportParams {
  school_year: string
  accountable_officer_id: number | null
  assumed_on: string
  prepared_by_id: string
  school_head_id: string
  supply_officer_id: string
}
