import { api } from '@/lib/api'
import type { PaginationMeta } from '@/types/api'

export interface EmailLogEntry {
  id: number
  recipients: string
  subject: string | null
  type: string | null
  status: 'sending' | 'sent'
  provider_message_id: string | null
  created_at: string
}

export interface EmailLogPage {
  data: EmailLogEntry[]
  meta: PaginationMeta
}

export const emailLogService = {
  list: (page: number) => api.get<EmailLogPage>('/settings/email-log', { params: { page } }).then((r) => r.data),
  clear: (currentPassword: string) => api.delete('/settings/email-log', { data: { current_password: currentPassword } }),
}
