/** `RoleResource`. `permissions` is only present on show/store/update, not on the list. */
export interface Role {
  id: number
  name: string
  label: string
  description: string | null
  is_protected: boolean
  user_count?: number
  permission_count?: number
  permissions?: string[]
  created_at: string | null
  updated_at: string | null
}
