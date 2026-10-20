import { isAxiosError } from 'axios'
import { ArrowLeft, ShieldCheck } from 'lucide-react'
import { type FormEvent, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'

import { AuthLayout } from '@/components/management/AuthLayout'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Checkbox } from '@/components/ui/Checkbox'
import { Input } from '@/components/ui/Input'
import { OtpInput } from '@/components/ui/OtpInput'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import type { ApiValidationError } from '@/types/api'

export function TwoFactorVerify() {
  usePageTitle('Verify Two-Factor Authentication')
  const { verifyTwoFactor } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const state = location.state as { twoFaToken?: string; trustDeviceDays?: number } | null
  const twoFaToken = state?.twoFaToken ?? null
  const trustDeviceDays = state?.trustDeviceDays ?? 0

  const [useRecoveryCode, setUseRecoveryCode] = useState(false)
  const [code, setCode] = useState('')
  const [rememberDevice, setRememberDevice] = useState(false)
  const [loading, setLoading] = useState(false)
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [warning, setWarning] = useState<string | null>(null)

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    if (!twoFaToken) return
    setLoading(true)
    setErrorMessage(null)

    try {
      const result = await verifyTwoFactor(twoFaToken, {
        ...(useRecoveryCode ? { recoveryCode: code } : { code }),
        rememberDevice: trustDeviceDays > 0 && rememberDevice,
      })
      if (result) {
        setWarning(result)
      } else {
        navigate('/dashboard')
      }
    } catch (error) {
      if (isAxiosError<ApiValidationError>(error) && error.response?.status === 422) {
        setErrorMessage(Object.values(error.response.data.errors).flat()[0] ?? 'Invalid code.')
      } else {
        setErrorMessage('Something went wrong. Please try again.')
      }
    } finally {
      setLoading(false)
    }
  }

  if (!twoFaToken) {
    return (
      <AuthLayout backTo="/login" backLabel="Back to Sign In">
        <Alert type="error">Your session has expired. Please log in again.</Alert>
        <Link to="/login" className="mt-4 inline-flex items-center gap-1 text-sm text-primary-600 hover:underline">
          <ArrowLeft className="h-4 w-4" />
          Back to Sign In
        </Link>
      </AuthLayout>
    )
  }

  if (warning) {
    return (
      <AuthLayout backTo="/login" backLabel="Back to Sign In">
        <Alert type="warning" className="mb-4">
          {warning}
        </Alert>
        <Button className="w-full" size="lg" onClick={() => navigate('/dashboard')}>
          Continue to Dashboard
        </Button>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout backTo="/login" backLabel="Back to Sign In">
      <div className="mb-5">
        <h2 className="text-2xl font-bold text-gray-900">Two-factor verification</h2>
        <p className="mt-1 text-sm text-gray-500">
          {useRecoveryCode ? 'Enter one of your saved recovery codes.' : 'Enter the 6-digit code from your authenticator app.'}
        </p>
      </div>

      {errorMessage && (
        <Alert id="two-factor-error" type="error" className="mb-4">
          {errorMessage}
        </Alert>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        {useRecoveryCode ? (
          <Input
            label="Recovery code"
            type="text"
            autoComplete="one-time-code"
            autoCapitalize="characters"
            spellCheck={false}
            placeholder="XXXXXXXX"
            className="font-mono uppercase tracking-widest"
            value={code}
            // Recovery codes are 8 hex characters; drop spaces and anything else pasted along with them.
            onChange={(e) => setCode(e.target.value.toUpperCase().replace(/[^0-9A-F]/g, '').slice(0, 8))}
            required
            autoFocus
            aria-invalid={errorMessage ? true : undefined}
            aria-describedby={errorMessage ? 'two-factor-error' : undefined}
          />
        ) : (
          <OtpInput
            label="Verification code"
            value={code}
            onChange={setCode}
            error={!!errorMessage}
            aria-describedby="two-factor-error"
            autoFocus
          />
        )}
        {trustDeviceDays > 0 && (
          <div className="rounded-lg border border-gray-200 bg-gray-50 p-3">
            <Checkbox
              id="remember-device"
              label={`Trust this device for ${trustDeviceDays} days`}
              checked={rememberDevice}
              onChange={(e) => setRememberDevice(e.target.checked)}
            />
            <p className="mt-1 pl-6 text-xs text-gray-500">
              You won't be asked for a code on this browser until then. Your password is still required. Don't check this on a shared or public computer.
            </p>
          </div>
        )}
        <Button
          type="submit"
          className="w-full"
          size="lg"
          isLoading={loading}
          disabled={code.length < (useRecoveryCode ? 8 : 6)}
          leftIcon={<ShieldCheck className="h-4 w-4" />}
        >
          Verify
        </Button>
      </form>

      <button
        type="button"
        className="mt-4 text-sm font-medium text-primary-600 hover:underline"
        onClick={() => {
          setUseRecoveryCode((v) => !v)
          setCode('')
          setErrorMessage(null)
        }}
      >
        {useRecoveryCode ? 'Use an authenticator code instead' : 'Use a recovery code instead'}
      </button>
    </AuthLayout>
  )
}
