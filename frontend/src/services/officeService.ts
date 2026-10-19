import { api } from '@/lib/api'
import { lookupService } from '@/services/lookupService'
import type { ApiResponse, PaginatedResponse } from '@/types/api'
import type { Office, OfficePayload } from '@/types/office'

// Offices feed the office pickers on other forms (users, office profiles),
// so every change drops the cached options payloads.
export const officeService = {
  list: (params: Record<string, string | number>) =>
    api.get<PaginatedResponse<Office>>('/offices', { params }).then((r) => r.data),
  show: (id: number) => api.get<ApiResponse<Office>>(`/offices/${id}`).then((r) => r.data.data),
  create: (payload: OfficePayload) =>
    api.post<ApiResponse<Office>>('/offices', payload).then((r) => {
      lookupService.forget()
      return r.data.data
    }),
  update: (id: number, payload: OfficePayload) =>
    api.put<ApiResponse<Office>>(`/offices/${id}`, payload).then((r) => {
      lookupService.forget()
      return r.data.data
    }),
  remove: (id: number) =>
    api.delete(`/offices/${id}`).then(() => {
      lookupService.forget()
    }),
}
