import {
  Building2,
  History,
  LayoutDashboard,
  Library,
  LogOut,
  Menu,
  Monitor,
  School,
  Settings,
  ShieldCheck,
  Signal,
  UserCog,
  UserRound,
  Users,
  Wifi,
  type LucideIcon,
} from 'lucide-react'
import { Suspense, useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom'

import { BrandMarks } from '@/components/BrandMarks'
import { ErrorBoundary, RouteErrorFallback } from '@/components/ErrorBoundary'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Spinner } from '@/components/ui/Spinner'
import { ThemeToggle } from '@/components/ThemeToggle'
import { useAuth } from '@/context/AuthContext'
import { usePortalTheme } from '@/hooks/usePortalTheme'
import { BRANDING as branding } from '@/lib/branding'
import { SETTINGS_PERMISSIONS, type PermissionName } from '@/lib/permissions'
import { cn } from '@/lib/utils'
import type { AuthUser } from '@/types/auth'

type Can = (permission: PermissionName) => boolean

interface NavItem {
  title: string
  /**
   * Either a fixed path, or a function of the signed-in user for the
   * office-scoped items: it returns the path to link to, or null to hide the
   * item (e.g. an office-scoped permission holder with no office assigned).
   */
  to: string | ((user: AuthUser, can: Can) => string | null)
  icon: LucideIcon
  /** Text color class for the icon specifically — stays on the icon even
      when the row is active, so the rail reads as a set of distinct
      colored icons rather than one flat gray column (the active/hover
      state still shows via the row's own background + label color). */
  color: string
  permission?: PermissionName | PermissionName[]
}

interface NavGroup {
  title: string | null
  items: NavItem[]
}

/**
 * Office-scoped records (one row per office): cross-office admins (`view_all`)
 * get the all-offices list; an office-scoped user (`view` plus their own
 * `office_id`) goes straight to their own office's record.
 */
function officeScopedPath(base: string, viewAll: PermissionName, view: PermissionName) {
  return (user: AuthUser, can: Can): string | null => {
    if (can(viewAll)) return base
    if (can(view) && typeof user.office_id === 'number') return `${base}/${user.office_id}`
    return null
  }
}

const navGroups: NavGroup[] = [
  {
    title: null,
    items: [{ title: 'Dashboard', to: '/dashboard', icon: LayoutDashboard, color: 'text-primary-600' }],
  },
  {
    title: 'Records',
    items: [
      { title: 'Personnel', to: '/personnel', icon: Users, color: 'text-violet-500', permission: 'personnel.view' },
      { title: 'Equipment', to: '/equipment', icon: Monitor, color: 'text-sky-500', permission: 'equipment.view' },
      { title: 'ISP Accounts', to: '/isp-accounts', icon: Wifi, color: 'text-emerald-600', permission: 'isp_accounts.view' },
    ],
  },
  {
    title: 'Office Profiles',
    items: [
      {
        title: 'Stakeholder Profile',
        to: officeScopedPath('/stakeholder-profiles', 'stakeholder_profile.view_all', 'stakeholder_profile.view'),
        icon: School,
        color: 'text-amber-600',
        permission: ['stakeholder_profile.view', 'stakeholder_profile.view_all'],
      },
      {
        title: 'Internet Connectivity',
        to: officeScopedPath('/internet-connectivity-surveys', 'internet_connectivity.view_all', 'internet_connectivity.view'),
        icon: Signal,
        color: 'text-teal-600',
        permission: ['internet_connectivity.view', 'internet_connectivity.view_all'],
      },
    ],
  },
  {
    title: 'Libraries',
    items: [
      { title: 'Reference Data', to: '/reference-data', icon: Library, color: 'text-cyan-500', permission: 'reference-data.manage' },
      { title: 'Offices', to: '/offices', icon: Building2, color: 'text-orange-500', permission: 'office.view' },
    ],
  },
  {
    title: 'Administration',
    items: [
      { title: 'Users', to: '/users', icon: UserCog, color: 'text-violet-500', permission: 'users.manage' },
      { title: 'Roles', to: '/roles', icon: ShieldCheck, color: 'text-emerald-500', permission: 'roles.manage' },
      { title: 'Audit Log', to: '/audit-log', icon: History, color: 'text-indigo-500', permission: 'audit_log.view' },
      { title: 'Settings', to: '/settings', icon: Settings, color: 'text-fuchsia-500', permission: SETTINGS_PERMISSIONS },
    ],
  },
]

// Matches the OCIS admin sidebar's collapse behavior: the hamburger toggles
// the whole rail between full width (icon + label) and icon-only — it never
// hides the sidebar outright. While collapsed, hovering a row shows its
// label in a small flyout. The flyout is portaled to <body> (not just
// absolutely positioned inside the rail) because the nav list scrolls
// vertically, and anything positioned inside a scrolling box gets clipped
// horizontally too — same reason OCIS's Vue version teleports its flyout.
// Full literal class names (not built with string interpolation) so
// Tailwind's static scanner can actually see and generate them.
const EXPANDED_WIDTH = 'lg:w-64'
const COLLAPSED_WIDTH = 'lg:w-20'
// Must match the `lg:` prefix's threshold above — the hover flyout should
// only appear when the sidebar is actually rendered icon-only, which is
// only true at this width regardless of what `collapsed` itself says (see
// handleItemEnter).
const LG_BREAKPOINT_QUERY = '(min-width: 1024px)'

export function ManagementLayout() {
  const { user, can, logout } = useAuth()
  const theme = usePortalTheme()
  const navigate = useNavigate()
  const [collapsed, setCollapsed] = useState(false)
  // Below the `lg` breakpoint there's no room to push page content over —
  // the sidebar has to float above it instead (overlay + backdrop), same
  // as it would need to at a desktop width the browser's own zoom has
  // shrunk down into that range. `collapsed` and `mobileOpen` are toggled
  // together by the one hamburger button; each only has a visual effect
  // at its own breakpoint (`lg:` prefixed classes below), so nothing extra
  // has to track which breakpoint is currently active.
  const [mobileOpen, setMobileOpen] = useState(false)
  const [userMenuOpen, setUserMenuOpen] = useState(false)
  const [logoutConfirmOpen, setLogoutConfirmOpen] = useState(false)
  const [loggingOut, setLoggingOut] = useState(false)
  const [hovered, setHovered] = useState<{ title: string; top: number; height: number } | null>(null)
  const asideRef = useRef<HTMLElement>(null)

  // `collapsed` is a plain click toggle with no idea which breakpoint is
  // active — below `lg` the sidebar always renders at full width via CSS
  // regardless of `collapsed`, but nothing in the CSS stops the *labels*
  // from also needing to hide when it's genuinely icon-only. So track the
  // breakpoint itself (reactively, since it can change from a window
  // resize or the browser's own zoom, not just at mount) and only treat
  // the rail as icon-only when both are true.
  const [isDesktop, setIsDesktop] = useState(() => window.matchMedia(LG_BREAKPOINT_QUERY).matches)
  useEffect(() => {
    const mql = window.matchMedia(LG_BREAKPOINT_QUERY)
    const handleChange = (e: MediaQueryListEvent) => setIsDesktop(e.matches)
    mql.addEventListener('change', handleChange)
    return () => mql.removeEventListener('change', handleChange)
  }, [])
  const iconOnly = collapsed && isDesktop
  const userInitials = user?.name
    .split(' ')
    .map((part) => part[0])
    .filter(Boolean)
    .slice(0, 2)
    .join('')
    .toUpperCase()

  const visibleNavGroups = navGroups
    .map((group) => ({
      ...group,
      items: group.items
        .filter((item) => !item.permission || (Array.isArray(item.permission) ? item.permission.some((p) => can(p)) : can(item.permission)))
        .map((item) => ({ ...item, to: typeof item.to === 'string' ? item.to : user ? item.to(user, can) : null }))
        .filter((item): item is typeof item & { to: string } => item.to !== null),
    }))
    .filter((group) => group.items.length > 0)

  async function handleLogout() {
    setLoggingOut(true)
    await logout()
    setLoggingOut(false)
    setLogoutConfirmOpen(false)
    navigate('/login')
  }

  function handleItemEnter(title: string, e: React.MouseEvent<HTMLElement>) {
    if (!iconOnly) return
    const rect = e.currentTarget.getBoundingClientRect()
    setHovered({ title, top: rect.top, height: rect.height })
  }

  function handleItemLeave() {
    setHovered(null)
  }

  function toggleSidebar() {
    setCollapsed((v) => !v)
    setMobileOpen((v) => !v)
  }

  return (
    <div className="flex min-h-screen bg-gray-100">
      {mobileOpen && <div className="fixed inset-0 z-20 bg-black/40 lg:hidden" onClick={() => setMobileOpen(false)} />}

      <aside
        ref={asideRef}
        className={cn(
          'fixed inset-y-0 left-0 z-30 flex w-64 flex-col border-r border-gray-200 bg-white transition-transform duration-200 lg:translate-x-0 lg:transition-[width] print:hidden',
          mobileOpen ? 'translate-x-0' : '-translate-x-full',
          collapsed ? COLLAPSED_WIDTH : EXPANDED_WIDTH,
        )}
      >
        <div className={cn('flex h-16 items-center gap-3 border-b border-gray-200', iconOnly ? 'justify-center px-2' : 'px-4')}>
          {iconOnly ? (
            <img src={branding.seal} alt={branding.sealAlt} className="h-10 w-10 shrink-0" />
          ) : (
            <BrandMarks className="gap-1" imageClassName="h-9 w-9" />
          )}
          {!iconOnly && (
            <div className="min-w-0 leading-tight" title={branding.fullName}>
              <p className="text-base font-bold text-gray-900">{branding.appName}</p>
              <p className="truncate text-xs text-gray-600">{branding.fullName}</p>
            </div>
          )}
        </div>
        <nav className="scrollbar-hover flex-1 overflow-y-auto overflow-x-hidden px-3 py-4">
          {visibleNavGroups.map((group, index) => (
            <div key={group.title ?? 'main'} className={cn(index > 0 && (iconOnly ? 'mt-2 border-t border-gray-100 pt-2' : 'mt-4'))}>
              {group.title && !iconOnly && (
                <p className="mb-1 px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-gray-400">{group.title}</p>
              )}
              <div className="space-y-1">
                {group.items.map((item) => (
                  <NavLink
                    key={item.title}
                    to={item.to}
                    onMouseEnter={(e) => handleItemEnter(item.title, e)}
                    onMouseLeave={handleItemLeave}
                    className={({ isActive }) =>
                      cn(
                        'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-100',
                        iconOnly && 'justify-center px-0',
                        isActive && 'bg-primary-50 text-primary-700',
                      )
                    }
                    aria-label={iconOnly ? item.title : undefined}
                  >
                    <item.icon className={cn('h-4 w-4 shrink-0', item.color)} />
                    {!iconOnly && item.title}
                  </NavLink>
                ))}
              </div>
            </div>
          ))}
        </nav>
      </aside>

      {iconOnly &&
        hovered &&
        createPortal(
          <div
            className="pointer-events-none fixed z-50 -translate-y-1/2 whitespace-nowrap rounded-md bg-gray-900 px-2.5 py-1.5 text-xs font-medium text-gray-50 shadow-lg"
            style={{ left: (asideRef.current?.getBoundingClientRect().right ?? 80) + 8, top: hovered.top + hovered.height / 2 }}
          >
            {hovered.title}
          </div>,
          document.body,
        )}

      {/* No left margin below `lg` — the sidebar floats above content there
          instead of pushing it, since there's no room to spare. */}
      <div className={cn('flex min-w-0 flex-1 flex-col transition-[margin] duration-200 print:ml-0', collapsed ? 'lg:ml-20' : 'lg:ml-64')}>
        <header className="sticky top-0 z-20 flex h-16 items-center gap-2 border-b border-gray-200 bg-white px-4 print:hidden">
          <button
            type="button"
            className="rounded-md p-2 text-gray-600 hover:bg-gray-100"
            onClick={toggleSidebar}
            aria-label="Toggle sidebar"
          >
            <Menu className="h-5 w-5" />
          </button>

          <div className="flex-1" />

          <ThemeToggle preference={theme.preference} onChange={theme.choose} />

          {user && (
            <div className="relative">
              <button
                type="button"
                className="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100"
                onClick={() => setUserMenuOpen((v) => !v)}
              >
                {user.avatar_url ? (
                  <img src={user.avatar_url} alt="" className="h-8 w-8 rounded-full object-cover" />
                ) : (
                  <span className="flex h-8 w-8 items-center justify-center rounded-full bg-primary-100 text-xs font-semibold text-primary-700">
                    {userInitials || <UserRound className="h-4 w-4" />}
                  </span>
                )}
                <span className="hidden max-w-36 truncate sm:inline">{user.name}</span>
              </button>
              {userMenuOpen && (
                <>
                  <div className="fixed inset-0 z-10" onClick={() => setUserMenuOpen(false)} />
                  <div className="fixed inset-x-4 top-16 z-20 overflow-hidden rounded-lg border border-gray-100 bg-white py-1 shadow-lg sm:absolute sm:inset-x-auto sm:top-full sm:right-0 sm:mt-1 sm:w-56">
                    <div className="flex items-center gap-3 border-b border-gray-100 px-4 py-3">
                      {user.avatar_url ? (
                        <img src={user.avatar_url} alt="" className="h-10 w-10 rounded-full object-cover" />
                      ) : (
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-100 text-sm font-semibold text-primary-700">
                          {userInitials || <UserRound className="h-5 w-5" />}
                        </span>
                      )}
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium text-gray-900">{user.name}</p>
                        <p className="truncate text-xs text-gray-500">{user.email}</p>
                        <p className="truncate text-[11px] text-gray-400">{user.roles.join(', ')}</p>
                      </div>
                    </div>
                    <Link
                      to="/profile"
                      className="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
                      onClick={() => setUserMenuOpen(false)}
                    >
                      <UserRound className="h-4 w-4" />
                      Profile settings
                    </Link>
                    <button
                      type="button"
                      className="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50"
                      onClick={() => { setUserMenuOpen(false); setLogoutConfirmOpen(true) }}
                    >
                      <LogOut className="h-4 w-4" />
                      Log out
                    </button>
                  </div>
                </>
              )}
            </div>
          )}
        </header>

        <main className="flex-1 p-[clamp(1rem,0.85rem_+_0.75vw,1.5rem)]">
          <ErrorBoundary fallback={<RouteErrorFallback />}>
            <Suspense fallback={<Spinner full />}>
              <Outlet />
            </Suspense>
          </ErrorBoundary>
        </main>
      </div>

      <ConfirmDialog
        open={logoutConfirmOpen}
        onOpenChange={setLogoutConfirmOpen}
        onConfirm={handleLogout}
        title="Log out?"
        description="Are you sure you want to log out of the management portal?"
        confirmText="Log out"
        confirmVariant="danger"
        loading={loggingOut}
      />
    </div>
  )
}

