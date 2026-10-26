import { api } from '@/lib/api'
import type { ApiResponse, PaginatedResponse } from '@/types/api'
import type { ManagedUser, UserOptions } from '@/types/user'

export interface UpdateUserPayload {
  is_active: boolean
  role_id: number
}

export const userService = {
  // Not cached via lookupService: assignable roles change whenever roles are edited.
  options: () => api.get<ApiResponse<UserOptions>>('/users/options').then((r) => r.data.data),
  list: (params: Record<string, string | number>) =>
    api.get<PaginatedResponse<ManagedUser>>('/users', { params }).then((r) => r.data),
  update: (id: number, payload: UpdateUserPayload) =>
    api.put<ApiResponse<ManagedUser>>(`/users/${id}`, payload).then((r) => r.data.data),
  remove: (id: number) => api.delete(`/users/${id}`),
  resetTwoFactor: (id: number, currentPassword: string) =>
    api.post<{ message: string }>(`/users/${id}/2fa/reset`, { current_password: currentPassword }).then((r) => r.data),
}
