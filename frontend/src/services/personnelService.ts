import { api } from '@/lib/api'
import { lookupService } from '@/services/lookupService'
import type { ApiResponse, PaginatedResponse } from '@/types/api'
import type { Personnel, PersonnelFormData, PersonnelOptions, PersonnelPayload } from '@/types/personnel'

/** Blank text inputs are sent as `null` so nullable columns stay null rather than ''. */
export function toPersonnelPayload(form: PersonnelFormData): PersonnelPayload {
  return Object.fromEntries(Object.entries(form).map(([key, value]) => [key, value === '' ? null : value])) as PersonnelPayload
}

export const personnelService = {
  list: (params: Record<string, string | number>) =>
    api.get<PaginatedResponse<Personnel>>('/personnel', { params }).then((r) => r.data),
  options: () => lookupService.options<PersonnelOptions>('/personnel/options'),
  show: (id: number | string) => api.get<ApiResponse<Personnel>>(`/personnel/${id}`).then((r) => r.data.data),
  create: (payload: PersonnelPayload) => api.post<ApiResponse<Personnel>>('/personnel', payload).then((r) => r.data),
  update: (id: number, payload: PersonnelPayload) =>
    api.put<ApiResponse<Personnel>>(`/personnel/${id}`, payload).then((r) => r.data),
  remove: (id: number) => api.delete(`/personnel/${id}`),
}
