import { isAxiosError } from 'axios'
import { type FormEvent, useState } from 'react'

import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { AuthLayout } from '@/components/management/AuthLayout'
import { usePageTitle } from '@/hooks/usePageTitle'
import { ensureCsrfCookie, api } from '@/lib/api'
import type { ApiValidationError } from '@/types/api'

export function ForgotPassword() {
  usePageTitle('Forgot Password')

  const [email, setEmail] = useState('')
  const [loading, setLoading] = useState(false)
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [sent, setSent] = useState(false)

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setLoading(true)
    setErrorMessage(null)

    try {
      await ensureCsrfCookie()
      await api.post('/forgot-password', { email })
      setSent(true)
    } catch (error) {
      if (isAxiosError<ApiValidationError>(error) && error.response?.status === 422) {
        setErrorMessage(Object.values(error.response.data.errors).flat()[0] ?? 'Something went wrong. Please try again.')
      } else if (isAxiosError(error) && error.response?.status === 429) {
        setErrorMessage('Too many requests. Please wait a moment before trying again.')
      } else {
        setErrorMessage('Something went wrong. Please try again.')
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <AuthLayout backTo="/portal/login" backLabel="Back to Sign In">
      <div className="mb-5 text-center lg:mb-6 lg:text-left">
        <h2 className="text-2xl font-bold text-gray-900">Forgot your password?</h2>
        <p className="mt-1 text-sm text-gray-500">Enter your account email and we'll send you a link to reset it.</p>
      </div>

      {errorMessage && (
        <Alert type="error" className="mb-4">
          {errorMessage}
        </Alert>
      )}

      {sent ? (
        <Alert type="success">
          If that email is registered, a password reset link has been sent to it. The link expires in 60 minutes.
        </Alert>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-4">
          <Input
            label="Email"
            type="email"
            autoComplete="username"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            required
            autoFocus
          />
          <Button type="submit" className="w-full" size="lg" isLoading={loading}>
            Send reset link
          </Button>
        </form>
      )}
    </AuthLayout>
  )
}
