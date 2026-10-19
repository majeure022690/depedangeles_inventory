import { api } from '@/lib/api'
import type { ApiResponse } from '@/types/api'
import type { DashboardSummary } from '@/types/dashboard'

export const dashboardService = {
  summary: () => api.get<ApiResponse<DashboardSummary>>('/dashboard/summary').then((response) => response.data.data),
}
