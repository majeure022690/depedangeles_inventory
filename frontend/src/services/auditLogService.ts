import { api } from '@/lib/api'
import type { ApiResponse, PaginatedResponse } from '@/types/api'
import type { AuditLogEntry, AuditLogOptions } from '@/types/auditLog'

export const auditLogService = {
  list: (params: Record<string, string | number>) =>
    api.get<PaginatedResponse<AuditLogEntry>>('/audit-log', { params }).then((r) => r.data),
  // Not cached via lookupService: the distinct events/subject types grow as activity is logged.
  options: () => api.get<ApiResponse<AuditLogOptions>>('/audit-log/options').then((r) => r.data.data),
}
