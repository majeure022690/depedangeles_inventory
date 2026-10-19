/**
 * Mirrors `App\Enums\Permission` (backend/app/Enums/Permission.php), which is
 * the single source of truth. This union only exists so `can()` call sites,
 * route guards and the sidebar get a compile error on a typo'd permission
 * string; the server re-checks every request regardless of what the UI shows.
 */
export const PERMISSIONS = [
  'personnel.view',
  'personnel.create',
  'personnel.edit',
  'personnel.delete',
  'equipment.view',
  'equipment.create',
  'equipment.edit',
  'equipment.delete',
  'equipment.transactions.create',
  'isp_accounts.view',
  'isp_accounts.create',
  'isp_accounts.edit',
  'isp_accounts.delete',
  'reference-data.manage',
  'users.manage',
  'roles.manage',
  'stakeholder_profile.view',
  'stakeholder_profile.edit',
  'stakeholder_profile.view_all',
  'internet_connectivity.view',
  'internet_connectivity.edit',
  'internet_connectivity.view_all',
  'office.view',
  'office.create',
  'office.edit',
  'office.delete',
  'audit_log.view',
  'security_settings.manage',
] as const

export type PermissionName = (typeof PERMISSIONS)[number]

export const SETTINGS_PERMISSIONS: PermissionName[] = ['security_settings.manage']

/** One row of `GET /api/permissions` (`PermissionResource`); label and group come from the enum server-side. */
export interface PermissionInfo {
  name: string
  label: string
  group: string
}

export interface PermissionGroup {
  key: string
  title: string
  permissions: (PermissionInfo & { description: string })[]
}

/** Display order for the server-provided groups (`Permission::group()`); unknown groups sort after these. */
const GROUP_ORDER = ['Personnel', 'Equipment', 'ISP Accounts', 'Offices', 'Stakeholder Profile', 'Internet Connectivity', 'Administration']

/**
 * Help text shown under each permission in the role editor. UI copy only:
 * which permissions exist, their labels and their groups all come from the server.
 */
const PERMISSION_DESCRIPTIONS: Record<PermissionName, string> = {
  'personnel.view': 'See the personnel list and each person’s record.',
  'personnel.create': 'Add new personnel records.',
  'personnel.edit': 'Edit existing personnel records.',
  'personnel.delete': 'Delete personnel records.',
  'equipment.view': 'See the equipment inventory, its transaction history and QR codes.',
  'equipment.create': 'Add new equipment to the inventory.',
  'equipment.edit': 'Edit an item’s descriptive details.',
  'equipment.delete': 'Delete equipment records.',
  'equipment.transactions.create': 'Record transfers and returns that change who is accountable for an item.',
  'isp_accounts.view': 'See ISP accounts, speed tests and subscription costs.',
  'isp_accounts.create': 'Add new ISP accounts.',
  'isp_accounts.edit': 'Edit ISP accounts and log speed tests and subscription costs.',
  'isp_accounts.delete': 'Delete ISP accounts.',
  'reference-data.manage': 'Manage the lookup tables behind every dropdown (brands, positions, ISP providers, …).',
  'users.manage': 'Create, edit and deactivate user accounts and assign existing roles.',
  'roles.manage': 'Create and edit roles and decide which permissions they grant.',
  'stakeholder_profile.view': 'See the stakeholder profile of their own office.',
  'stakeholder_profile.edit': 'Edit the stakeholder profile of their own office.',
  'stakeholder_profile.view_all': 'See and edit every office’s stakeholder profile.',
  'internet_connectivity.view': 'See the internet connectivity survey of their own office.',
  'internet_connectivity.edit': 'Edit the internet connectivity survey of their own office.',
  'internet_connectivity.view_all': 'See and edit every office’s internet connectivity survey.',
  'office.view': 'See the list of schools and division offices.',
  'office.create': 'Add new offices.',
  'office.edit': 'Edit office details.',
  'office.delete': 'Delete offices that nothing else refers to.',
  'audit_log.view': 'See who changed what across the system.',
  'security_settings.manage': 'Change sign-in, rate-limit and session security settings.',
}

function describe(name: string): string {
  return (PERMISSION_DESCRIPTIONS as Record<string, string | undefined>)[name] ?? ''
}

/** Groups the server's permission catalog for display, keeping the server's order within each group. */
export function groupPermissions(available: PermissionInfo[]): PermissionGroup[] {
  const byGroup = new Map<string, PermissionGroup>()
  for (const permission of available) {
    const title = permission.group || 'Other'
    let group = byGroup.get(title)
    if (!group) {
      group = { key: title.toLowerCase().replace(/[^a-z0-9]+/g, '_'), title, permissions: [] }
      byGroup.set(title, group)
    }
    group.permissions.push({ ...permission, description: describe(permission.name) })
  }

  const rank = (title: string) => {
    const index = GROUP_ORDER.indexOf(title)
    return index === -1 ? GROUP_ORDER.length : index
  }

  return [...byGroup.values()].sort((a, b) => rank(a.title) - rank(b.title))
}
