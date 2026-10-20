import type { AuthUser } from '@/types/auth'
import type { Option } from '@/types/lookup'

/** A role as the users admin screen shows it (`UserResource.role_details`, `GET /api/users/options`). */
export interface RoleSummary {
  id: number
  name: string
  label: string
}

/**
 * One row of `GET /api/users` (`UserResource` with roles and office loaded).
 * List rows carry no `permissions`; `is_self` marks the acting user's own row.
 * `can_manage` (delete, 2FA reset) is false on your own row; `can_edit` is not.
 */
export interface ManagedUser extends Omit<AuthUser, 'permissions' | 'is_self'> {
  role_details: RoleSummary[]
  is_self: boolean
  can_edit: boolean
  can_manage: boolean
}

/** `GET /api/users/options`: only roles the acting user may assign, and active offices. */
export interface UserOptions {
  roles: RoleSummary[]
  offices: Option<number>[]
}
