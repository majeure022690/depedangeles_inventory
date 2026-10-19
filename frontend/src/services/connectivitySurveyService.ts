import { api } from '@/lib/api'
import { lookupService } from '@/services/lookupService'
import type { ApiResponse, PaginatedResponse } from '@/types/api'
import type {
  ConnectivitySurveyEnvelope,
  ConnectivitySurveyFormData,
  ConnectivitySurveyOfficeRow,
  ConnectivitySurveyOptions,
  InternetConnectivitySurvey,
} from '@/types/internet-connectivity-survey'

const OPTIONS_URL = '/internet-connectivity-surveys/options'

function text(value: string): string | null {
  return value.trim() === '' ? null : value
}

/** Empty -> null; anything non-numeric is passed through so the server's 422 message explains it. */
function numeric(value: string): number | string | null {
  if (value.trim() === '') return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : value
}

const ids = (value: (number | string)[] | null | undefined) => (value ?? []).map(Number).filter(Number.isFinite)

export function connectivitySurveyFormFrom(survey: InternetConnectivitySurvey): ConnectivitySurveyFormData {
  return {
    has_isp_in_area: survey.has_isp_in_area ?? false,
    available_isps: ids(survey.available_isps),
    available_isps_other: survey.available_isps_other ?? '',
    mobile_signal_types: ids(survey.mobile_signal_types),
    has_mobile_data_connectivity: survey.has_mobile_data_connectivity ?? false,
    mobile_data_quality: survey.mobile_data_quality ?? '',
    subscribes_to_isp: survey.subscribes_to_isp ?? false,
    subscribed_isps: ids(survey.subscribed_isps),
    insufficient_bandwidth_explanation: survey.insufficient_bandwidth_explanation ?? '',
    coverage_areas: ids(survey.coverage_areas),
    coverage_areas_other: survey.coverage_areas_other ?? '',
    dict_free_wifi_recipient: survey.dict_free_wifi_recipient ?? false,
    dict_free_wifi_rating: survey.dict_free_wifi_rating !== null ? String(survey.dict_free_wifi_rating) : '',
    has_sufficient_bandwidth: survey.has_sufficient_bandwidth ?? false,
    no_subscription_reason: survey.no_subscription_reason ?? '',
    has_electricity_source: survey.has_electricity_source ?? false,
    electricity_sources: ids(survey.electricity_sources),
    primarily_solar_powered: survey.primarily_solar_powered ?? false,
    frequent_brownouts: survey.frequent_brownouts ?? false,
    rooms_other_use: survey.rooms_other_use !== null ? String(survey.rooms_other_use) : '',
  }
}

function payload(form: ConnectivitySurveyFormData) {
  return {
    ...form,
    available_isps_other: text(form.available_isps_other),
    mobile_data_quality: text(form.mobile_data_quality),
    insufficient_bandwidth_explanation: text(form.insufficient_bandwidth_explanation),
    coverage_areas_other: text(form.coverage_areas_other),
    dict_free_wifi_rating: numeric(form.dict_free_wifi_rating),
    no_subscription_reason: text(form.no_subscription_reason),
    rooms_other_use: numeric(form.rooms_other_use),
  }
}

export const connectivitySurveyService = {
  list: (params: Record<string, string | number>) =>
    api.get<PaginatedResponse<ConnectivitySurveyOfficeRow>>('/internet-connectivity-surveys', { params }).then((r) => r.data),

  options: () => lookupService.options<ConnectivitySurveyOptions>(OPTIONS_URL),

  show: (officeId: number) =>
    api.get<ApiResponse<ConnectivitySurveyEnvelope>>(`/internet-connectivity-surveys/${officeId}`).then((r) => r.data.data),

  update: (officeId: number, form: ConnectivitySurveyFormData) =>
    api.put<ApiResponse<ConnectivitySurveyEnvelope>>(`/internet-connectivity-surveys/${officeId}`, payload(form)).then((r) => r.data),
}
