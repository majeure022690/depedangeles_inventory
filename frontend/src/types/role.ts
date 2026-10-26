export type DataScope = 'own' | 'school' | 'all'

export const DATA_SCOPE_OPTIONS: { value: DataScope; label: string }[] = [
  { value: 'own', label: 'Own records only' },
  { value: 'school', label: 'Own office' },
  { value: 'all', label: 'All records' },
]

/** `RoleResource`. `permissions` is only present on show/store/update, not on the list. */
export interface Role {
  id: number
  name: string
  label: string
  description: string | null
  data_scope: DataScope
  is_protected: boolean
  user_count?: number
  permission_count?: number
  permissions?: string[]
  created_at: string | null
  updated_at: string | null
}
