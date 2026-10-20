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
 * `can_manage` is false on your own row and on anyone holding permissions you
 * don't; edit, deactivate, delete and 2FA reset all 403 for those rows.
 */
export interface ManagedUser extends Omit<AuthUser, 'permissions' | 'is_self'> {
  role_details: RoleSummary[]
  is_self: boolean
  can_manage: boolean
}

/** `GET /api/users/options`: only roles the acting user may assign, and active offices. */
export interface UserOptions {
  roles: RoleSummary[]
  offices: Option<number>[]
}
