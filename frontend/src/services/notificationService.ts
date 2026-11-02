import { api } from '@/lib/api'
import type { ApiResponse } from '@/types/api'
import type { BellNotification } from '@/types/notification'

export const notificationService = {
  list: () => api.get<ApiResponse<BellNotification[]>>('/notifications').then((r) => r.data.data),
}
