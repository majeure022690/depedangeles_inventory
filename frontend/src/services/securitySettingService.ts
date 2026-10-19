import { api } from '@/lib/api'
import type { ApiResponse } from '@/types/api'

export interface SecuritySetting {
  key: string
  value: number
  label: string
  description: string
  min: number
  max: number
}

export const securitySettingService = {
  list: () => api.get<ApiResponse<SecuritySetting[]>>('/settings/security').then((response) => response.data.data),
  update: (settingKey: string, value: number) =>
    api.put<ApiResponse<SecuritySetting>>('/settings/security', { setting_key: settingKey, value }).then((response) => response.data.data),
}
