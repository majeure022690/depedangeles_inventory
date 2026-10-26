import { isAxiosError } from 'axios'
import { Eye, EyeOff, Lock, Mail } from 'lucide-react'
import { type FormEvent, useEffect, useRef, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'

import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { AuthLayout } from '@/components/management/AuthLayout'
import { clearNoAccessNotice, NO_ACCESS_REASON, readNoAccessNotice, useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { useRecaptcha } from '@/hooks/useRecaptcha'
import { useShowRecaptchaBadge } from '@/hooks/useShowRecaptchaBadge'
import { isNoAccessError } from '@/lib/api'
import { isTooManyRequests, TOO_MANY_ATTEMPTS_MESSAGE } from '@/lib/errors'
import type { ApiValidationError } from '@/types/api'

const PRIME_NOTE_ID = 'login-prime-note'

const NO_ACCESS_FALLBACK ="Your account doesn't have access to DICTIS yet. Please contact the Division ICT Unit."

export function Login() {
  usePageTitle('User Login')
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const executeRecaptcha = useRecaptcha()
  useShowRecaptchaBadge()
  const navState = location.state as { signInError?: string; noAccess?: string } | null
  const reason = new URLSearchParams(location.search).get('reason')
  const signedOutIdle = reason === 'idle'

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [loading, setLoading] = useState(false)
  // Seeded by the 2FA pages when their pending sign-in token is rejected.
  const [errorMessage, setErrorMessage] = useState<string | null>(navState?.signInError ?? null)
  const signedOutNoAccess = reason === NO_ACCESS_REASON
  const [noAccessNotice, setNoAccessNotice] = useState<string | null>(
    () => navState?.noAccess ?? (signedOutNoAccess ? (readNoAccessNotice() ?? NO_ACCESS_FALLBACK) : null),
  )

  const noAccessRef = useRef<HTMLDivElement>(null)
  const noAccessOnMount = useRef(noAccessNotice !== null)

  useEffect(() => {
    if (signedOutNoAccess) clearNoAccessNotice()
  }, [signedOutNoAccess])

  useEffect(() => {
    if (noAccessOnMount.current) noAccessRef.current?.focus()
  }, [])

  useEffect(() => {
    if (navState?.noAccess) navigate(`${location.pathname}${location.search}`, { replace: true, state: null })
  }, [location.pathname, location.search, navState?.noAccess, navigate])

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setLoading(true)
    setErrorMessage(null)
    setNoAccessNotice(null)

    try {
      const recaptchaToken = await executeRecaptcha('admin_login')
      const result = await login(email, password, recaptchaToken ?? '')

      if (result.status === 'authenticated') {
        navigate('/dashboard')
      } else if (result.status === 'requires_2fa_setup') {
        navigate('/2fa/setup', { state: { setupToken: result.setupToken } })
      } else {
        navigate('/2fa/verify', { state: { twoFaToken: result.twoFaToken, trustDeviceDays: result.trustDeviceDays } })
      }
    } catch (error) {
      if (isNoAccessError(error)) {
        setNoAccessNotice(error.response.data.message)
      } else if (isTooManyRequests(error)) {
        setErrorMessage(TOO_MANY_ATTEMPTS_MESSAGE)
      } else if (isAxiosError<ApiValidationError>(error) && error.response?.status === 422) {
        setErrorMessage(Object.values(error.response.data.errors).flat()[0] ?? 'Invalid credentials.')
      } else {
        setErrorMessage('Something went wrong. Please try again.')
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <AuthLayout>
      <div className="mb-5 text-center lg:mb-6 lg:text-left">
        <h2 className="text-2xl font-bold text-gray-900">Welcome back</h2>
        <p className="mt-1 text-sm text-gray-500">Sign in to the management portal</p>
      </div>

      {noAccessNotice && (
        <Alert ref={noAccessRef} tabIndex={-1} type="info" role="alert" className="mb-4 focus:outline-none">
          {noAccessNotice}
        </Alert>
      )}

      {signedOutIdle && !errorMessage && !noAccessNotice && (
        <Alert type="info" className="mb-4">
          You were signed out because there was no activity for a while. Please sign in again.
        </Alert>
      )}

      {errorMessage && (
        <Alert type="error" className="mb-4">
          {errorMessage}
        </Alert>
      )}

      <form onSubmit={handleSubmit} className="space-y-3.5 lg:space-y-4">
        <p id={PRIME_NOTE_ID} className="rounded-lg border border-primary-200 bg-primary-50 px-3 py-2 text-sm text-primary-800">
          Sign in with your PRIME account: your DepEd email and PRIME password.
        </p>

        <div>
          <label htmlFor="login-email" className="mb-1 block text-sm font-medium text-gray-700">
            Email
          </label>
          <div className="relative">
            <Mail aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <input
              id="login-email"
              type="email"
              autoComplete="username"
              aria-describedby={PRIME_NOTE_ID}
              required
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className="w-full rounded-lg border border-gray-300 py-2.5 pl-9 pr-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20"
            />
          </div>
        </div>

        <div>
          <label htmlFor="login-password" className="mb-1 block text-sm font-medium text-gray-700">
            Password
          </label>
          <div className="relative">
            <Lock aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <input
              id="login-password"
              type={showPassword ? 'text' : 'password'}
              autoComplete="current-password"
              aria-describedby={PRIME_NOTE_ID}
              required
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="w-full rounded-lg border border-gray-300 py-2.5 pl-9 pr-9 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20"
            />
            <button
              type="button"
              className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
              onClick={() => setShowPassword((v) => !v)}
              aria-label={showPassword ? 'Hide password' : 'Show password'}
            >
              {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
            </button>
          </div>
        </div>

        <Button type="submit" className="w-full" size="lg" isLoading={loading}>
          Sign In
        </Button>
      </form>
    </AuthLayout>
  )
}
