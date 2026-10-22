import { RefreshCw } from 'lucide-react'
import { type ReactNode, useEffect, useId, useRef, useState } from 'react'
import { Navigate, Outlet } from 'react-router-dom'

import { IdleLogout } from '@/components/IdleLogout'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Spinner } from '@/components/ui/Spinner'
import { type SessionStatus, useAuth } from '@/context/AuthContext'
import type { PermissionName } from '@/lib/permissions'

function SessionCheckNotice({ status, onRetry }: { status: Exclude<SessionStatus, 'ok'>; onRetry: () => void }) {
  const headingRef = useRef<HTMLHeadingElement>(null)
  const retryButtonRef = useRef<HTMLButtonElement>(null)
  const messageId = useId()
  // Live regions only announce changes, so the message is filled in after the empty region has mounted.
  const [announce, setAnnounce] = useState(false)

  useEffect(() => {
    const frame = window.requestAnimationFrame(() => setAnnounce(true))
    return () => window.cancelAnimationFrame(frame)
  }, [])

  useEffect(() => {
    if (status === 'unavailable') retryButtonRef.current?.focus()
  }, [status])

  const handleRetry = () => {
    headingRef.current?.focus()
    onRetry()
  }

  return (
    <main className="flex min-h-screen items-center justify-center bg-gray-50 px-4">
      <div className="w-full max-w-md rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <h1 ref={headingRef} tabIndex={-1} className="mb-4 text-lg font-semibold text-gray-900 focus:outline-none">
          Confirming your session
        </h1>
        <div id={messageId} role="status">
          {announce &&
            (status === 'retrying' ? (
              <div className="flex items-center gap-3 text-sm text-gray-700">
                <Spinner className="h-5 w-5 shrink-0" />
                <span>The server is busy right now. Checking your session again…</span>
              </div>
            ) : (
              <Alert type="warning">We couldn't confirm your session because the server is busy. You haven't been signed out.</Alert>
            ))}
        </div>
        {status === 'unavailable' && (
          <Button
            ref={retryButtonRef}
            className="mt-4"
            aria-describedby={messageId}
            leftIcon={<RefreshCw className="h-4 w-4" aria-hidden="true" />}
            onClick={handleRetry}
          >
            Try again
          </Button>
        )}
      </div>
    </main>
  )
}

/** Redirects to /login unless the user is authenticated. */
export function RequireAuth() {
  const { isAuthenticated, initialized, sessionStatus, retrySession } = useAuth()

  if (sessionStatus !== 'ok' && !isAuthenticated) return <SessionCheckNotice status={sessionStatus} onRetry={retrySession} />

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

