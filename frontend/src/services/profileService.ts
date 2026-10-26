import { api } from '@/lib/api'
import type { ApiResponse } from '@/types/api'
import type { AuthUser } from '@/types/auth'

/** Name, email and password come from PRIME; only the avatar is editable here. */
export interface UpdateProfilePayload {
  avatar?: File | null
  remove_avatar?: boolean
}

export const profileService = {
  update: (payload: UpdateProfilePayload) => {
    const formData = new FormData()
    if (payload.avatar) formData.append('avatar', payload.avatar)
    if (payload.remove_avatar) formData.append('remove_avatar', '1')

    return api.post<ApiResponse<AuthUser>>('/profile', formData).then((response) => response.data.data)
  },
}
