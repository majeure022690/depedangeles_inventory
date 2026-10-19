import { api } from '@/lib/api'
import type { PermissionInfo } from '@/lib/permissions'
import type { ApiResponse } from '@/types/api'
import type { Role } from '@/types/role'

export interface RolePayload {
  name: string
  label: string
  description: string | null
  permissions: string[]
}

export const roleService = {
  list: () => api.get<ApiResponse<Role[]>>('/roles').then((r) => r.data.data),
  show: (id: number) => api.get<ApiResponse<Role>>(`/roles/${id}`).then((r) => r.data.data),
  create: (payload: RolePayload) => api.post<ApiResponse<Role>>('/roles', payload).then((r) => r.data.data),
  update: (id: number, payload: RolePayload) => api.put<ApiResponse<Role>>(`/roles/${id}`, payload).then((r) => r.data.data),
  remove: (id: number) => api.delete(`/roles/${id}`),
}

export const permissionService = {
  list: () => api.get<ApiResponse<PermissionInfo[]>>('/permissions').then((r) => r.data.data),
}
