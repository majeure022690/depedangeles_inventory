import { api } from '@/lib/api'
import { lookupService } from '@/services/lookupService'
import type { ApiResponse } from '@/types/api'
import type {
  ReferenceDataCreatePayload,
  ReferenceDataOverview,
  ReferenceDataPage,
  ReferenceDataRow,
  ReferenceDataUpdatePayload,
} from '@/types/reference-data'

// Every mutation drops the cached `/<resource>/options` payloads so the next
// form that opens picks up the added/renamed/deactivated value.
export const referenceDataService = {
  overview: () => api.get<ApiResponse<ReferenceDataOverview>>('/reference-data').then((r) => r.data.data),
  list: (table: string, params: Record<string, string | number>) =>
    api.get<ReferenceDataPage>(`/reference-data/${encodeURIComponent(table)}`, { params }).then((r) => r.data),
  create: (table: string, payload: ReferenceDataCreatePayload) =>
    api.post<ApiResponse<ReferenceDataRow>>(`/reference-data/${encodeURIComponent(table)}`, payload).then((r) => {
      lookupService.forget()
      return r.data.data
    }),
  update: (table: string, id: number, payload: ReferenceDataUpdatePayload) =>
    api.patch<ApiResponse<ReferenceDataRow>>(`/reference-data/${encodeURIComponent(table)}/${id}`, payload).then((r) => {
      lookupService.forget()
      return r.data.data
    }),
  remove: (table: string, id: number) =>
    api.delete(`/reference-data/${encodeURIComponent(table)}/${id}`).then(() => {
      lookupService.forget()
    }),
}
