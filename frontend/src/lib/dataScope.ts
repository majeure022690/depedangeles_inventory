import { useAuth } from '@/context/AuthContext'
import type { AuthUser } from '@/types/auth'
import type { DataScope } from '@/types/role'

export type ScopedArea = 'personnel' | 'equipment' | 'stakeholder_profile' | 'internet_connectivity'

const TITLES: Record<ScopedArea, Record<DataScope, string>> = {
  personnel: { own: 'My Record', school: 'Office Personnel', all: 'Personnel' },
  equipment: { own: 'My Equipment', school: 'Office Equipment', all: 'Equipment' },
  stakeholder_profile: { own: 'Our Office Profile', school: 'Our Office Profile', all: 'Stakeholder Profiles' },
  internet_connectivity: { own: 'Our Office Connectivity', school: 'Our Office Connectivity', all: 'Internet Connectivity' },
}

export const SCOPE_LOCK_HINT = 'Only administrators with access to all records can change this.'

export function scopedTitle(area: ScopedArea, scope: DataScope | undefined): string {
  return TITLES[area][scope ?? 'all']
}

export function hasAllScope(user: AuthUser | null): boolean {
  return user?.data_scope === 'all'
}

/** One-row-per-office records: the cross-office list for `all`, otherwise the user's own office row (null when they have none). */
export function officeRecordPath(base: string, user: AuthUser): string | null {
  if (user.data_scope === 'all') return base
  return typeof user.office_id === 'number' ? `${base}/${user.office_id}` : null
}

export function useDataScope() {
  const { user } = useAuth()
  const scope = user?.data_scope
  return {
    scope,
    isAll: scope === 'all',
    title: (area: ScopedArea) => scopedTitle(area, scope),
  }
}

const SCOPE_RANK: Record<DataScope, number> = { own: 0, school: 1, all: 2 }

export function scopeWithin(scope: DataScope, ceiling: DataScope | undefined): boolean {
  return ceiling !== undefined && SCOPE_RANK[scope] <= SCOPE_RANK[ceiling]
}
