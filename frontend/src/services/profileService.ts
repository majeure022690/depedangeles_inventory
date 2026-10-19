import { api } from '@/lib/api'
import type { ApiResponse } from '@/types/api'
import type { AuthUser } from '@/types/auth'

export interface UpdateProfilePayload {
  name: string
  email: string
  current_password?: string
  password?: string
  password_confirmation?: string
  avatar?: File | null
  remove_avatar?: boolean
}

export const profileService = {
  update: (payload: UpdateProfilePayload) => {
    const formData = new FormData()
    formData.append('name', payload.name)
    formData.append('email', payload.email)
    if (payload.current_password) formData.append('current_password', payload.current_password)
    if (payload.password) formData.append('password', payload.password)
    if (payload.password_confirmation) formData.append('password_confirmation', payload.password_confirmation)
    if (payload.avatar) formData.append('avatar', payload.avatar)
    if (payload.remove_avatar) formData.append('remove_avatar', '1')

    return api.post<ApiResponse<AuthUser>>('/profile', formData).then((response) => response.data.data)
  },
}
