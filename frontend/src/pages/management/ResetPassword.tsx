import { isAxiosError } from 'axios'
import { ArrowLeft } from 'lucide-react'
import { type FormEvent, useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'

import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { AuthLayout } from '@/components/management/AuthLayout'
import { usePageTitle } from '@/hooks/usePageTitle'
import { ensureCsrfCookie, api } from '@/lib/api'
import type { ApiValidationError } from '@/types/api'

export function ResetPassword() {
  usePageTitle('Reset Password')
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const [token] = useState(() => searchParams.get('token') ?? '')
  const [email] = useState(() => searchParams.get('email') ?? '')

  useEffect(() => {
    if (searchParams.has('token') || searchParams.has('email')) setSearchParams({}, { replace: true })
  }, [searchParams, setSearchParams])

  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [loading, setLoading] = useState(false)
  const [errorMessage, setErrorMessage] = useState<string | null>(null)

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setLoading(true)
    setErrorMessage(null)

    try {
      await ensureCsrfCookie()
      await api.post('/reset-password', {
        token,
        email,
        password,
        password_confirmation: passwordConfirmation,
      })
      navigate('/login', { state: { resetSuccess: true } })
    } catch (error) {
      if (isAxiosError<ApiValidationError>(error) && error.response?.status === 422) {
        setErrorMessage(Object.values(error.response.data.errors).flat()[0] ?? 'This reset link is invalid or has expired.')
      } else {
        setErrorMessage('Something went wrong. Please try again.')
      }
    } finally {
      setLoading(false)
    }
  }

  if (!token || !email) {
    return (
      <AuthLayout backTo="/login" backLabel="Back to Sign In">
        <Alert type="error">This reset link is missing or invalid. Open the link from your email again, or request a new one.</Alert>
        <Link to="/forgot-password" className="mt-4 inline-flex items-center gap-1 text-sm text-primary-600 hover:underline">
          <ArrowLeft className="h-4 w-4" />
          Request a new link
        </Link>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout backTo="/login" backLabel="Back to Sign In">
      <div className="mb-5 text-center lg:mb-6 lg:text-left">
        <h2 className="text-2xl font-bold text-gray-900">Reset your password</h2>
        <p className="mt-1 text-sm text-gray-500">Choose a new password for {email}.</p>
      </div>

      {errorMessage && (
        <Alert type="error" className="mb-4">
          {errorMessage}
        </Alert>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <Input
          label="New password"
          type="password"
          autoComplete="new-password"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          hint="At least 12 characters, with upper and lower case, a number, and a symbol."
          required
          autoFocus
        />
        <Input
          label="Confirm new password"
          type="password"
          autoComplete="new-password"
          value={passwordConfirmation}
          onChange={(event) => setPasswordConfirmation(event.target.value)}
          required
        />
        <Button type="submit" className="w-full" size="lg" isLoading={loading}>
          Reset password
        </Button>
      </form>
    </AuthLayout>
  )
}
