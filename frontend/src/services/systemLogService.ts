import { api } from '@/lib/api'
import type { ApiResponse } from '@/types/api'

export interface ErrorLogEntry {
  summary: string
  trace: string
  level: string
}

export interface ErrorLogStats {
  total_lines: number
  error_count: number
  warning_count: number
  last_update: string | null
  log_path: string
}

export interface SlowQueryStats {
  total_count: number
  slowest: string
  avg_time: string
  last_update: string | null
  log_path: string
  long_query_time: string | null
  environment: string
}

export interface SlowQueryVariables {
  slow_query_log: string
  slow_query_log_file: string
  long_query_time: string
  log_queries_not_using_indexes: string
}

async function downloadFile(path: string, filename: string): Promise<void> {
  const response = await api.get(path, { responseType: 'blob' })
  const url = window.URL.createObjectURL(new Blob([response.data]))
  const link = document.createElement('a')
  link.href = url
  link.setAttribute('download', filename)
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(url)
}

function timestampedFilename(prefix: string): string {
  return `${prefix}_${new Date().toISOString().replace(/[:.]/g, '-')}.txt`
}

export const systemLogService = {
  errorLogs: () =>
    api.get<ApiResponse<{ logs: ErrorLogEntry[]; stats: ErrorLogStats }>>('/settings/system-logs/errors').then((r) => r.data.data),
  clearErrorLogs: (currentPassword: string) =>
    api.delete<{ message: string }>('/settings/system-logs/errors', { data: { current_password: currentPassword } }).then((r) => r.data),
  downloadErrorLogs: () => downloadFile('/settings/system-logs/errors/download', timestampedFilename('laravel_error_log')),

  slowQueryLogs: () =>
    api.get<ApiResponse<{ logs: string[]; enabled: boolean; stats: SlowQueryStats }>>('/settings/system-logs/slow-queries').then((r) => r.data.data),
  slowQueryStatus: () =>
    api.get<ApiResponse<{ variables: SlowQueryVariables }>>('/settings/system-logs/slow-queries/status').then((r) => r.data.data),
  clearSlowQueryLogs: (currentPassword: string) =>
    api.delete<{ message: string }>('/settings/system-logs/slow-queries', { data: { current_password: currentPassword } }).then((r) => r.data),
  downloadSlowQueryLogs: () => downloadFile('/settings/system-logs/slow-queries/download', timestampedFilename('slow_query_log')),
}
