import { createContext, type ReactNode, useCallback, useContext, useEffect, useState } from 'react'
import { useLocation } from 'react-router-dom'

import { api, ensureCsrfCookie, IDLE_TIMEOUT_MESSAGE, registerUnauthorizedHandler } from '@/lib/api'
import type { ApiResponse } from '@/types/api'
import type { AuthUser } from '@/types/auth'

export type LoginResult =
  | { status: 'authenticated' }
  | { status: 'requires_2fa'; twoFaToken: string; trustDeviceDays: number }
  | { status: 'requires_2fa_setup'; setupToken: string }

interface AuthContextValue {
  user: AuthUser | null
  initialized: boolean
  isAuthenticated: boolean
  login: (email: string, password: string, recaptchaToken: string) => Promise<LoginResult>
  confirmTwoFactorSetup: (setupToken: string, code: string) => Promise<{ recoveryCodes: string[]; completeSignIn: () => void }>
  verifyTwoFactor: (twoFaToken: string, payload: { code?: string; recoveryCode?: string; rememberDevice?: boolean }) => Promise<string | undefined>
  logout: () => Promise<void>
  fetchUser: () => Promise<boolean>
  can: (permission: string) => boolean
  hasRole: (role: string) => boolean
}

const AuthContext = createContext<AuthContextValue | null>(null)

const GUEST_ONLY_PATHS = ['/login', '/forgot-password', '/reset-password', '/2fa/setup', '/2fa/verify']

const isGuestOnlyPath = (pathname: string) => GUEST_ONLY_PATHS.includes(pathname.replace(/\/+$/, '') || '/')

let leavingForLogin = false

/**
 * Mid-session 401 (deactivated, 2FA reset by an admin, server-side idle
 * timeout): hard-navigate to /login so no stale in-memory data survives.
 */
function leaveForLogin() {
  if (leavingForLogin) return
  leavingForLogin = true
  window.location.replace('/login')
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [initialized, setInitialized] = useState(false)

  const fetchUser = useCallback(async (): Promise<boolean> => {
    try {
      const { data } = await api.get<ApiResponse<AuthUser> | { message?: string }>('/user', {
        // Silence the console 401 on the boot probe — when the user is not
        // authenticated this is expected and handled by the catch below.
        validateStatus: (status) => status < 500,
      })
      if (data && 'data' in data) {
        setUser(data.data)
        return true
      }
      // The boot probe never runs on guest pages; an app page that finds the
      // session idled out server-side says why on the login screen.
      if (data?.message === IDLE_TIMEOUT_MESSAGE) {
        leaveForLogin()
        return false
      }
      setUser(null)
      return false
    } catch {
      setUser(null)
      return false
    } finally {
      setInitialized(true)
    }
  }, [])

  const login = useCallback(async (email: string, password: string, recaptchaToken: string): Promise<LoginResult> => {
    await ensureCsrfCookie()
    const { data } = await api.post<
      | { requires_2fa: true; two_fa_token: string; trust_device_days?: number }
      | { requires_2fa_setup: true; setup_token: string }
      | ApiResponse<AuthUser>
    >('/login', {
      email,
      password,
      recaptcha_token: recaptchaToken,
    })

    if ('requires_2fa' in data) {
      return { status: 'requires_2fa', twoFaToken: data.two_fa_token, trustDeviceDays: data.trust_device_days ?? 0 }
    }
    if ('requires_2fa_setup' in data) {
      return { status: 'requires_2fa_setup', setupToken: data.setup_token }
    }
    setUser(data.data)
    setInitialized(true)
    return { status: 'authenticated' }
  }, [])

  const confirmTwoFactorSetup = useCallback(async (setupToken: string, code: string) => {
    const { data } = await api.post<ApiResponse<AuthUser & { recovery_codes: string[] }>>('/2fa/setup/confirm', {
      setup_token: setupToken,
      code,
    })
    const { recovery_codes, ...userData } = data.data
    // Setting the user here would let the guest-only setup route redirect to the
    // dashboard before the recovery codes are shown, so the caller finishes sign-in.
    return {
      recoveryCodes: recovery_codes,
      completeSignIn: () => {
        setUser(userData)
        setInitialized(true)
      },
    }
  }, [])

  const verifyTwoFactor = useCallback(async (twoFaToken: string, payload: { code?: string; recoveryCode?: string; rememberDevice?: boolean }) => {
    const { data } = await api.post<ApiResponse<AuthUser> & { warning?: string }>('/2fa/verify', {
      two_fa_token: twoFaToken,
      code: payload.code,
      recovery_code: payload.recoveryCode,
      remember_device: payload.rememberDevice ?? false,
    })
    setUser(data.data)
    setInitialized(true)
    return data.warning
  }, [])

  const logout = useCallback(async () => {
    try {
      await api.post('/logout')
    } finally {
      setUser(null)
    }
  }, [])

  useEffect(() => {
    registerUnauthorizedHandler(() => {
      if (isGuestOnlyPath(window.location.pathname)) {
        setUser(null)
        setInitialized(true)
        return
      }
      leaveForLogin()
    })
  }, [])

  useEffect(() => {
    ensureCsrfCookie().catch(() => {})
  }, [])

  const location = useLocation()

  // Every route except the guest-only auth pages is an app route, so probe the
  // session there (hard refresh / deep link). Guest pages skip it to avoid an
  // expected-but-noisy /api/user 401. Track whether we've already fetched so
  // navigating from a guest page into the app doesn't skip the check.
  const [fetched, setFetched] = useState(false)

  useEffect(() => {
    if (isGuestOnlyPath(location.pathname)) {
      setInitialized(true)
      return
    }
    if (fetched) return
    setFetched(true)
    fetchUser()
  }, [fetchUser, fetched, location.pathname])

  const can = useCallback((permission: string) => user?.permissions.includes(permission) ?? false, [user])
  const hasRole = useCallback((role: string) => user?.roles.includes(role) ?? false, [user])

  return (
    <AuthContext.Provider
      value={{ user, initialized, isAuthenticated: user !== null, login, confirmTwoFactorSetup, verifyTwoFactor, logout, fetchUser, can, hasRole }}
    >
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within an AuthProvider')
  return ctx
}
