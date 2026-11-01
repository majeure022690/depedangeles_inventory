import type { Option } from '@/types/lookup'

/** `GET /api/reports/options`; `accountable_officers` only comes back when `office_id` is sent. */
export interface ReportOptions {
  offices: Option<number>[]
  accountable_officers?: (Option<number> & { position: string | null })[]
}

export interface RpcppeReportParams {
  school_year: string
  accountable_officer_id: number | null
  assumed_on: string
  prepared_by: string
  school_head: string
  supply_officer: string
}
