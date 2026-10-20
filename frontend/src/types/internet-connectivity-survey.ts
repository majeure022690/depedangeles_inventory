import type { Option } from '@/types/lookup'
import type { ProfileOffice } from '@/types/stakeholder-profile'

/** One row of `GET /api/internet-connectivity-surveys` (`ConnectivitySurveyOfficeResource`). */
export interface ConnectivitySurveyOfficeRow {
  id: number
  office_name: string
  office_type: string | null
  school_id: string | number | null
  has_survey: boolean
  updated_at: string | null
}

/**
 * `InternetConnectivitySurveyResource`. `available_isps`/`subscribed_isps`
 * are isp_providers ids; `mobile_signal_types`/`coverage_areas` are
 * connectivity_libraries ids; `electricity_sources` stakeholder_libraries ids.
 * Before the first save, GET returns an unsaved blank: `id` and `updated_at` are null.
 */
export interface InternetConnectivitySurvey {
  id: number | null
  office_id: number
  has_isp_in_area: boolean | null
  available_isps: number[] | null
  available_isps_other: string | null
  mobile_signal_types: number[] | null
  has_mobile_data_connectivity: boolean | null
  mobile_data_quality: string | null
  subscribes_to_isp: boolean | null
  subscribed_isps: number[] | null
  insufficient_bandwidth_explanation: string | null
  coverage_areas: number[] | null
  coverage_areas_other: string | null
  dict_free_wifi_recipient: boolean | null
  dict_free_wifi_rating: number | null
  has_sufficient_bandwidth: boolean | null
  no_subscription_reason: string | null
  has_electricity_source: boolean | null
  electricity_sources: number[] | null
  primarily_solar_powered: boolean | null
  frequent_brownouts: boolean | null
  rooms_other_use: number | null
  updated_at: string | null
}

/** `GET/PUT /api/internet-connectivity-surveys/{officeId}`. */
export interface ConnectivitySurveyEnvelope {
  office: ProfileOffice
  survey: InternetConnectivitySurvey
}

/** `GET /api/internet-connectivity-surveys/options`. */
export interface ConnectivitySurveyOptions {
  isp_provider: Option<number>[]
  signal_quality: Option<string>[]
  mobile_network_signal: Option<number>[]
  coverage_area: Option<number>[]
  source_of_electricity: Option<number>[]
}

/** Form state: text/number inputs as strings (`''` = empty). */
export interface ConnectivitySurveyFormData {
  has_isp_in_area: boolean
  available_isps: number[]
  available_isps_other: string
  mobile_signal_types: number[]
  has_mobile_data_connectivity: boolean
  mobile_data_quality: string
  subscribes_to_isp: boolean
  subscribed_isps: number[]
  insufficient_bandwidth_explanation: string
  coverage_areas: number[]
  coverage_areas_other: string
  dict_free_wifi_recipient: boolean
  dict_free_wifi_rating: string
  has_sufficient_bandwidth: boolean
  no_subscription_reason: string
  has_electricity_source: boolean
  electricity_sources: number[]
  primarily_solar_powered: boolean
  frequent_brownouts: boolean
  rooms_other_use: string
}
