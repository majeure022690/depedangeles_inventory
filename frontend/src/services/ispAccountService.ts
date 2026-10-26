import { api } from '@/lib/api'
import { lookupService } from '@/services/lookupService'
import type { ApiResponse, PaginatedResponse } from '@/types/api'
import type {
  IspAccount,
  IspAccountFormData,
  IspAccountOptions,
  IspSpeedTest,
  IspSpeedTestFormData,
  IspSubscriptionCost,
  IspSubscriptionCostFormData,
} from '@/types/isp-account'

const OPTIONS_URL = '/isp-accounts/options'

function text(value: string): string | null {
  return value.trim() === '' ? null : value
}

/** Empty -> null; anything non-numeric is passed through so the server's 422 message explains it. */
function numeric(value: string): number | string | null {
  if (value.trim() === '') return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : value
}

/** `datetime-local` (browser time) -> ISO-8601 UTC, which is what the API stores (app timezone is UTC). */
function fromDatetimeLocal(value: string): string | null {
  if (!value) return null
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toISOString()
}

export function emptyIspAccountForm(): IspAccountFormData {
  return {
    isp_provider_id: '',
    office_id: '',
    cost_per_month: '',
    isp_billing_account_no: '',
    package_purchased_inclusion: '',
    school_area_coverage: '',
    min_speed: '',
    max_speed: '',
    subscription_type: '',
    isp_connection_type: '',
    contract_start_date: '',
    contract_end_date: '',
    inactive_contract: false,
    purpose_of_subscription: '',
    mode_of_acquisition: '',
    source_of_acquisition: '',
    donor: '',
    fund_source: '',
    number_access_points_linked: '',
    locations_access_points: '',
    number_admin_area_covered: '',
    rate_connectivity_admin_areas: '',
    number_classrooms_covered: '',
    rate_connectivity_classroom: '',
    overall_signal_quality: '',
    rate_isp_service: '',
  }
}

const str = (value: number | string | null | undefined) => (value === null || value === undefined ? '' : String(value))

export function ispAccountFormFrom(account: IspAccount): IspAccountFormData {
  return {
    isp_provider_id: str(account.isp_provider_id),
    office_id: str(account.office_id),
    cost_per_month: str(account.cost_per_month),
    isp_billing_account_no: account.isp_billing_account_no ?? '',
    package_purchased_inclusion: account.package_purchased_inclusion ?? '',
    school_area_coverage: account.school_area_coverage ?? '',
    min_speed: str(account.min_speed),
    max_speed: str(account.max_speed),
    subscription_type: account.subscription_type ?? '',
    isp_connection_type: account.isp_connection_type ?? '',
    contract_start_date: account.contract_start_date ?? '',
    contract_end_date: account.contract_end_date ?? '',
    inactive_contract: account.inactive_contract,
    purpose_of_subscription: account.purpose_of_subscription ?? '',
    mode_of_acquisition: account.mode_of_acquisition ?? '',
    source_of_acquisition: account.source_of_acquisition ?? '',
    donor: account.donor ?? '',
    fund_source: account.fund_source ?? '',
    number_access_points_linked: str(account.number_access_points_linked),
    locations_access_points: account.locations_access_points ?? '',
    number_admin_area_covered: str(account.number_admin_area_covered),
    rate_connectivity_admin_areas: account.rate_connectivity_admin_areas ?? '',
    number_classrooms_covered: str(account.number_classrooms_covered),
    rate_connectivity_classroom: account.rate_connectivity_classroom ?? '',
    overall_signal_quality: account.overall_signal_quality ?? '',
    rate_isp_service: str(account.rate_isp_service),
  }
}

function accountPayload(form: IspAccountFormData) {
  return {
    isp_provider_id: numeric(form.isp_provider_id),
    office_id: numeric(form.office_id),
    cost_per_month: numeric(form.cost_per_month),
    isp_billing_account_no: text(form.isp_billing_account_no),
    package_purchased_inclusion: text(form.package_purchased_inclusion),
    school_area_coverage: text(form.school_area_coverage),
    min_speed: numeric(form.min_speed),
    max_speed: numeric(form.max_speed),
    subscription_type: text(form.subscription_type),
    isp_connection_type: text(form.isp_connection_type),
    contract_start_date: text(form.contract_start_date),
    contract_end_date: text(form.contract_end_date),
    inactive_contract: form.inactive_contract,
    purpose_of_subscription: text(form.purpose_of_subscription),
    mode_of_acquisition: text(form.mode_of_acquisition),
    source_of_acquisition: text(form.source_of_acquisition),
    donor: text(form.donor),
    fund_source: text(form.fund_source),
    number_access_points_linked: numeric(form.number_access_points_linked),
    locations_access_points: text(form.locations_access_points),
    number_admin_area_covered: numeric(form.number_admin_area_covered),
    rate_connectivity_admin_areas: text(form.rate_connectivity_admin_areas),
    number_classrooms_covered: numeric(form.number_classrooms_covered),
    rate_connectivity_classroom: text(form.rate_connectivity_classroom),
    overall_signal_quality: text(form.overall_signal_quality),
    rate_isp_service: numeric(form.rate_isp_service),
  }
}

export function emptySpeedTestForm(): IspSpeedTestFormData {
  return { download_speed: '', upload_speed: '', ping: '', tested_at: '', signal_quality: '', rate_isp_service: '' }
}

export function emptySubscriptionCostForm(): IspSubscriptionCostFormData {
  return {
    contract_start_date: '',
    contract_end_date: '',
    total_amount_spent: '',
    contract_projected_start_date: '',
    contract_projected_end_date: '',
    total_projected_expenditure: '',
  }
}

export const ispAccountService = {
  list: (params: Record<string, string | number>) =>
    api.get<PaginatedResponse<IspAccount>>('/isp-accounts', { params }).then((r) => r.data),

  options: () => lookupService.options<IspAccountOptions>(OPTIONS_URL),

  show: (id: number) => api.get<ApiResponse<IspAccount>>(`/isp-accounts/${id}`).then((r) => r.data.data),

  create: (form: IspAccountFormData) =>
    api.post<ApiResponse<IspAccount>>('/isp-accounts', accountPayload(form)).then((r) => r.data),

  update: (id: number, form: IspAccountFormData) =>
    api.put<ApiResponse<IspAccount>>(`/isp-accounts/${id}`, accountPayload(form)).then((r) => r.data),

  remove: (id: number) => api.delete(`/isp-accounts/${id}`),

  recordSpeedTest: (id: number, form: IspSpeedTestFormData) =>
    api
      .post<ApiResponse<IspSpeedTest>>(`/isp-accounts/${id}/speed-tests`, {
        download_speed: numeric(form.download_speed),
        upload_speed: numeric(form.upload_speed),
        ping: numeric(form.ping),
        tested_at: fromDatetimeLocal(form.tested_at),
        signal_quality: text(form.signal_quality),
        rate_isp_service: numeric(form.rate_isp_service),
      })
      .then((r) => r.data),

  recordSubscriptionCost: (id: number, form: IspSubscriptionCostFormData) =>
    api
      .post<ApiResponse<IspSubscriptionCost>>(`/isp-accounts/${id}/subscription-costs`, {
        contract_start_date: text(form.contract_start_date),
        contract_end_date: text(form.contract_end_date),
        total_amount_spent: numeric(form.total_amount_spent),
        contract_projected_start_date: text(form.contract_projected_start_date),
        contract_projected_end_date: text(form.contract_projected_end_date),
        total_projected_expenditure: numeric(form.total_projected_expenditure),
      })
      .then((r) => r.data),
}
