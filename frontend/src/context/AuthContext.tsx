import { isAxiosError } from 'axios'
import { createContext, type ReactNode, useCallback, useContext, useEffect, useState } from 'react'
import { useLocation } from 'react-router-dom'

import {
  api,
  ensureCsrfCookie,
  IDLE_TIMEOUT_MESSAGE,
  isNoAccessResponse,
  registerNoAccessHandler,
  registerUnauthorizedHandler,
} from '@/lib/api'
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
  sessionStatus: SessionStatus
  retrySession: () => void
  can: (permission: string) => boolean
  hasRole: (role: string) => boolean
}

const AuthContext = createContext<AuthContextValue | null>(null)

const GUEST_ONLY_PATHS = ['/login', '/forgot-password', '/reset-password', '/2fa/setup', '/2fa/verify']

const isGuestOnlyPath = (pathname: string) => GUEST_ONLY_PATHS.includes(pathname.replace(/\/+$/, '') || '/')

const NO_ACCESS_NOTICE_KEY = 'dictis.noAccessNotice'

export const NO_ACCESS_REASON = 'no_access'

export function readNoAccessNotice(): string | null {
  try {
    return window.sessionStorage.getItem(NO_ACCESS_NOTICE_KEY)
  } catch {
    return null
  }
}

export function clearNoAccessNotice(): void {
  try {
    window.sessionStorage.removeItem(NO_ACCESS_NOTICE_KEY)
  } catch {
    return
  }
}

function storeNoAccessNotice(message: string): void {
  try {
    window.sessionStorage.setItem(NO_ACCESS_NOTICE_KEY, message)
  } catch {
    return
  }
}

let leavingForLogin = false
let leavingForNoAccess = false

/**
 * Mid-session 401 (deactivated, 2FA reset by an admin, server-side idle
 * timeout): hard-navigate to /login so no stale in-memory data survives.
 */
function leaveForLogin() {
  if (leavingForLogin) return
  leavingForLogin = true
  window.location.replace('/login')
}

/** Like leaveForLogin, but wins over a plain 401 redirect already in flight so the notice is shown. */
function leaveForLoginWithoutAccess(message: string) {
  storeNoAccessNotice(message)
  if (leavingForNoAccess) return
  leavingForNoAccess = true
  leavingForLogin = true
  window.location.replace(`/login?reason=${NO_ACCESS_REASON}`)
}

type SessionProbe =
  | { kind: 'user'; user: AuthUser }
  | { kind: 'signed_out' }
  | { kind: 'leaving' }
  | { kind: 'unavailable'; retryAfterMs: number }

export type SessionStatus = 'ok' | 'retrying' | 'unavailable'

const BOOT_PROBE_RETRIES = 2
const DEFAULT_RETRY_MS = 3000
const MAX_RETRY_MS = 10000

function retryDelayMs(retryAfter: unknown): number {
  const seconds = Number(retryAfter)
  if (!Number.isFinite(seconds) || seconds <= 0) return DEFAULT_RETRY_MS
  return Math.min(Math.max(seconds * 1000, 1000), MAX_RETRY_MS)
}

const wait = (ms: number) => new Promise<void>((resolve) => window.setTimeout(resolve, ms))

async function probeSession(): Promise<SessionProbe> {
  try {
    const response = await api.get<ApiResponse<AuthUser> | { message?: string }>('/user', {
      // Silence the console 401 on the boot probe; an unauthenticated visitor is expected here.
      validateStatus: (status) => status < 500,
    })
    const { data, status } = response
    if (data && 'data' in data) {
      return { kind: 'user', user: data.data }
    }
    if (isNoAccessResponse(data)) {
      if (isGuestOnlyPath(window.location.pathname)) return { kind: 'signed_out' }
      leaveForLoginWithoutAccess(data.message)
      return { kind: 'leaving' }
    }
    // The boot probe never runs on guest pages; an app page that finds the
    // session idled out server-side says why on the login screen.
    if (data?.message === IDLE_TIMEOUT_MESSAGE) {
      leaveForLogin()
      return { kind: 'leaving' }
    }
    if (status === 401) return { kind: 'signed_out' }
    return { kind: 'unavailable', retryAfterMs: retryDelayMs(response.headers['retry-after']) }
  } catch (error) {
    const retryAfter: unknown = isAxiosError(error) ? error.response?.headers['retry-after'] : undefined
    return { kind: 'unavailable', retryAfterMs: retryDelayMs(retryAfter) }
  }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [initialized, setInitialized] = useState(false)
  const [sessionStatus, setSessionStatus] = useState<SessionStatus>('ok')

  const applyProbe = useCallback((result: SessionProbe) => {
    if (result.kind === 'user') setUser(result.user)
    else if (result.kind === 'signed_out') setUser(null)
  }, [])

  const fetchUser = useCallback(async (): Promise<boolean> => {
    const result = await probeSession()
    applyProbe(result)
    if (result.kind !== 'unavailable') setSessionStatus('ok')
    setInitialized(true)
    return result.kind === 'user'
  }, [applyProbe])

  const bootSession = useCallback(async () => {
    for (let attempt = 0; ; attempt++) {
      const result = await probeSession()
      if (result.kind !== 'unavailable') {
        applyProbe(result)
        setSessionStatus('ok')
        setInitialized(true)
        return
      }
      if (attempt >= BOOT_PROBE_RETRIES) {
        setSessionStatus('unavailable')
        setInitialized(true)
        return
      }
      setSessionStatus('retrying')
      await wait(result.retryAfterMs)
    }
  }, [applyProbe])

  const retrySession = useCallback(() => {
    setSessionStatus('retrying')
    setInitialized(false)
    void bootSession()
  }, [bootSession])

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
    setSessionStatus('ok')
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
        setSessionStatus('ok')
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
    setSessionStatus('ok')
    setInitialized(true)
    return data.warning
  }, [])

  const logout = useCallback(async () => {
    try {
      await api.post('/logout')
    } finally {
      setSessionStatus('ok')
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
    registerNoAccessHandler((message) => {
      if (isGuestOnlyPath(window.location.pathname)) {
        setUser(null)
        setInitialized(true)
        return false
      }
      leaveForLoginWithoutAccess(message)
      return true
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
    void bootSession()
  }, [bootSession, fetched, location.pathname])

  const can = useCallback((permission: string) => user?.permissions.includes(permission) ?? false, [user])
  const hasRole = useCallback((role: string) => user?.roles.includes(role) ?? false, [user])

  return (
    <AuthContext.Provider
      value={{ user, initialized, isAuthenticated: user !== null, login, confirmTwoFactorSetup, verifyTwoFactor, logout, fetchUser, sessionStatus, retrySession, can, hasRole }}
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
