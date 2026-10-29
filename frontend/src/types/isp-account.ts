import type { Option } from '@/types/lookup'

/** `IspSpeedTestResource`. Append-only: there is no edit or delete. */
export interface IspSpeedTest {
  id: number
  isp_account_id: number
  download_speed: number
  upload_speed: number
  ping: number | null
  /** ISO-8601 timestamp. */
  tested_at: string | null
  signal_quality: string
  rate_isp_service: number | null
  created_at: string | null
}

/** `IspSubscriptionCostResource`. Append-only, and every field is optional. */
export interface IspSubscriptionCost {
  id: number
  isp_account_id: number
  contract_start_date: string | null
  contract_end_date: string | null
  total_amount_spent: number | null
  contract_projected_start_date: string | null
  contract_projected_end_date: string | null
  total_projected_expenditure: number | null
  created_at: string | null
}

/**
 * `IspAccountResource`. `isp_provider_id` is the FK the form binds to and
 * `isp` is the provider's display name. `speed_tests`/`subscription_costs`
 * are only present on the detail endpoint, newest logged first.
 */
export interface IspAccount {
  id: number
  isp_provider_id: number
  office_id: number | null
  office?: { id: number; office_name: string } | null
  isp?: string | null
  cost_per_month: number
  isp_billing_account_no: string
  package_purchased_inclusion: string | null
  school_area_coverage: string
  min_speed: number | null
  max_speed: number | null
  subscriber_speed_upload: number | null
  subscriber_speed_download: number | null
  subscription_type: string
  isp_connection_type: string
  contract_start_date: string | null
  contract_end_date: string | null
  inactive_contract: boolean
  purpose_of_subscription: string
  mode_of_acquisition: string
  source_of_acquisition: string
  donor: string | null
  fund_source: string
  number_access_points_linked: number | null
  locations_access_points: string | null
  number_admin_area_covered: number | null
  rate_connectivity_admin_areas: string | null
  number_classrooms_covered: number | null
  rate_connectivity_classroom: string | null
  overall_signal_quality: string
  rate_isp_service: number | null
  speed_tests?: IspSpeedTest[]
  subscription_costs?: IspSubscriptionCost[]
  created_at: string | null
  updated_at: string | null
}

/** `GET /api/isp-accounts/options`. */
export interface IspAccountOptions {
  form: {
    isp_provider: Option<number>[]
    office: Option<number>[]
    school_level_coverage: Option<string>[]
    subscription_type: Option<string>[]
    isp_connection_type: Option<string>[]
    purpose_of_subscription: Option<string>[]
    signal_quality: Option<string>[]
    mode_of_acquisition: Option<string>[]
    source_of_acquisition: Option<string>[]
    source_of_funds: Option<string>[]
  }
  filters: {
    isp_provider: Option<number>[]
  }
  speed_test: {
    signal_quality: Option<string>[]
  }
}

/**
 * Account form state. Inputs are held as strings (`''` = empty) and converted
 * to the request payload by `ispAccountService`.
 */
export interface IspAccountFormData {
  isp_provider_id: string
  office_id: string
  cost_per_month: string
  isp_billing_account_no: string
  package_purchased_inclusion: string
  school_area_coverage: string
  min_speed: string
  max_speed: string
  subscriber_speed_upload: string
  subscriber_speed_download: string
  subscription_type: string
  isp_connection_type: string
  contract_start_date: string
  contract_end_date: string
  inactive_contract: boolean
  purpose_of_subscription: string
  mode_of_acquisition: string
  source_of_acquisition: string
  donor: string
  fund_source: string
  number_access_points_linked: string
  locations_access_points: string
  number_admin_area_covered: string
  rate_connectivity_admin_areas: string
  number_classrooms_covered: string
  rate_connectivity_classroom: string
  overall_signal_quality: string
  rate_isp_service: string
}

export interface IspSpeedTestFormData {
  download_speed: string
  upload_speed: string
  ping: string
  /** `datetime-local` value in the browser's time zone. */
  tested_at: string
  signal_quality: string
  rate_isp_service: string
}

export interface IspSubscriptionCostFormData {
  contract_start_date: string
  contract_end_date: string
  total_amount_spent: string
  contract_projected_start_date: string
  contract_projected_end_date: string
  total_projected_expenditure: string
}

export type IspAccountStatusFilter = '' | 'active' | 'inactive'
