import type { AuthUser } from '@/types/auth'
import type { Option } from '@/types/lookup'

/** A role as the users admin screen shows it (`UserResource.role`, `GET /api/users/options`). */
export interface RoleSummary {
  id: number
  name: string
  label: string
}

/**
 * One row of `GET /api/users`. List rows carry no `permissions`; `is_self` marks the acting user's own row.
 * `can_delete` and `can_reset_two_factor` are false on your own row; `can_edit` is not.
 */
export interface ManagedUser extends Omit<AuthUser, 'permissions' | 'is_self'> {
  role: RoleSummary | null
  is_self: boolean
  can_edit: boolean
  can_delete: boolean
  can_reset_two_factor: boolean
}

/** `GET /api/users/options`: only roles the acting user may assign, and active offices. */
export interface UserOptions {
  roles: RoleSummary[]
  offices: Option<number>[]
}
