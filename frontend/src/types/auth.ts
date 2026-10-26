import type { DataScope } from '@/types/role'

export interface UserOffice {
  id: number
  office_name: string
  school_id: string | null
}

/** Shape of the backend `UserResource` (plan §2d), as returned by `GET /api/user` and the login/2FA endpoints. */
export interface AuthUser {
  id: number
  name: string
  email: string
  avatar_url: string | null
  employee_no: string | null
  office_id: number | null
  office?: UserOffice | null
  personnel_id: number | null
  personnel?: { id: number; full_name: string } | null
  data_scope: DataScope
  is_active: boolean
  two_factor_enabled: boolean
  roles: string[]
  permissions: string[]
  is_self?: boolean
  created_at: string
  updated_at: string
}
