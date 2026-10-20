import { type ReactNode } from 'react'
import { Navigate, Outlet } from 'react-router-dom'

import { IdleLogout } from '@/components/IdleLogout'
import { Spinner } from '@/components/ui/Spinner'
import { useAuth } from '@/context/AuthContext'
import type { PermissionName } from '@/lib/permissions'

/** Redirects to /login unless the user is authenticated. */
export function RequireAuth() {
  const { isAuthenticated, initialized } = useAuth()

  if (!initialized) return <Spinner full />

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />
  }

  return (
    <>
      <IdleLogout />
      <Outlet />
    </>
  )
}

/** Redirects authenticated users away from guest-only routes like /login. */
export function GuestOnly({ children }: { children: ReactNode }) {
  const { isAuthenticated, initialized } = useAuth()

  if (!initialized) return <Spinner full />
  if (isAuthenticated) return <Navigate to="/dashboard" replace />

  return <>{children}</>
}

/** Gates a route behind one or more permissions (any-of), redirecting to the dashboard otherwise. */
export function RequirePermission({ permission }: { permission: PermissionName | PermissionName[] }) {
  const { can } = useAuth()
  const required = Array.isArray(permission) ? permission : [permission]

  if (!required.some((p) => can(p))) {
    return <Navigate to="/dashboard" replace />
  }

  return <Outlet />
}

