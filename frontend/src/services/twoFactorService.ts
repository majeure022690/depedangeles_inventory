import { api } from '@/lib/api'
import type { ApiResponse } from '@/types/api'

export interface TwoFactorSetupData {
  qr_code: string
  secret: string
}

export const twoFactorService = {
  setup: (setupToken: string) =>
    api.post<ApiResponse<TwoFactorSetupData>>('/2fa/setup', { setup_token: setupToken }).then((r) => r.data.data),
  regenerateRecoveryCodes: (currentPassword: string) =>
    api
      .post<ApiResponse<{ recovery_codes: string[] }>>('/2fa/recovery-codes/regenerate', { current_password: currentPassword })
      .then((r) => r.data.data),
}
