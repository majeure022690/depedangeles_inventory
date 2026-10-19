import { api } from '@/lib/api'

export interface TrustedDevice {
  id: number
  label: string
  ip_address: string | null
  trusted_at: string
  last_used_at: string | null
  expires_at: string
  is_current: boolean
}

export const trustedDeviceService = {
  list: () =>
    api
      .get<{ data: TrustedDevice[]; meta: { trust_device_days: number } }>('/trusted-devices')
      .then((r) => r.data),
  revoke: (id: number) => api.delete(`/trusted-devices/${id}`),
  revokeAll: () => api.delete('/trusted-devices'),
}
