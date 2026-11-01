import { api } from '@/lib/api'
import type { ApiResponse } from '@/types/api'
import type { DashboardFilterOptions, DashboardFilters, DashboardSummary } from '@/types/dashboard'

export const EMPTY_DASHBOARD_FILTERS: DashboardFilters = { office_id: '', office_type: '', district: '' }

export const dashboardService = {
  summary: (filters?: Partial<DashboardFilters>) =>
    api
      .get<ApiResponse<DashboardSummary>>('/dashboard/summary', {
        params: Object.fromEntries(Object.entries(filters ?? {}).filter(([, value]) => value !== '')),
      })
      .then((response) => response.data.data),
  filters: () => api.get<ApiResponse<DashboardFilterOptions>>('/dashboard/filters').then((response) => response.data.data),
}
