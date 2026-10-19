import { isAxiosError } from 'axios'
import { ArrowLeft, Check, Copy, Download, ShieldCheck } from 'lucide-react'
import { type FormEvent, useEffect, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'

import { AuthLayout } from '@/components/management/AuthLayout'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { OtpInput } from '@/components/ui/OtpInput'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { twoFactorService } from '@/services/twoFactorService'
import type { ApiValidationError } from '@/types/api'

export function TwoFactorSetup() {
  usePageTitle('Set Up Two-Factor Authentication')
  const { confirmTwoFactorSetup } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const setupToken = (location.state as { setupToken?: string } | null)?.setupToken ?? null

  const [qrCode, setQrCode] = useState<string | null>(null)
  const [secret, setSecret] = useState<string | null>(null)
  const [loadingSetup, setLoadingSetup] = useState(true)
  const [code, setCode] = useState('')
  const [confirming, setConfirming] = useState(false)
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null)
  const [completeSignIn, setCompleteSignIn] = useState<(() => void) | null>(null)
  const [codesSaved, setCodesSaved] = useState(false)
  const [copied, setCopied] = useState(false)

  useEffect(() => {
    if (!setupToken) return
    twoFactorService
      .setup(setupToken)
      .then((result) => {
        setQrCode(result.qr_code)
        setSecret(result.secret)
      })
      .catch(() => setErrorMessage('Unable to start two-factor setup. Please log in again.'))
      .finally(() => setLoadingSetup(false))
  }, [setupToken])

  async function handleConfirm(e: FormEvent) {
    e.preventDefault()
    if (!setupToken) return
    setConfirming(true)
    setErrorMessage(null)

    try {
      const result = await confirmTwoFactorSetup(setupToken, code)
      setCompleteSignIn(() => result.completeSignIn)
      setRecoveryCodes(result.recoveryCodes)
    } catch (error) {
      if (isAxiosError<ApiValidationError>(error) && error.response?.status === 422) {
        setErrorMessage(Object.values(error.response.data.errors).flat()[0] ?? 'Invalid verification code.')
      } else {
        setErrorMessage('Something went wrong. Please try again.')
      }
    } finally {
      setConfirming(false)
    }
  }

  function copySecret() {
    if (!secret) return
    navigator.clipboard.writeText(secret).then(() => {
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    })
  }

  function downloadRecoveryCodes() {
    if (!recoveryCodes) return
    const blob = new Blob([recoveryCodes.join('\n')], { type: 'text/plain' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = 'dictis-recovery-codes.txt'
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  }

  if (!setupToken) {
    return (
      <AuthLayout backTo="/portal/login" backLabel="Back to Sign In">
        <Alert type="error">Your setup session has expired. Please log in again.</Alert>
        <Link to="/portal/login" className="mt-4 inline-flex items-center gap-1 text-sm text-primary-600 hover:underline">
          <ArrowLeft className="h-4 w-4" />
          Back to Sign In
        </Link>
      </AuthLayout>
    )
  }

  if (recoveryCodes) {
    return (
      <AuthLayout backTo="/portal/login" backLabel="Back to Sign In">
        <div className="mb-5">
          <h2 className="text-2xl font-bold text-gray-900">Save your recovery codes</h2>
          <p className="mt-1 text-sm text-gray-500">
            Each code can be used once to sign in if you lose access to your authenticator app. Store them somewhere safe.
          </p>
        </div>

        <div className="mb-4 grid grid-cols-2 gap-2 rounded-lg border border-gray-200 bg-gray-50 p-4 font-mono text-sm">
          {recoveryCodes.map((recoveryCode) => (
            <div key={recoveryCode}>{recoveryCode}</div>
          ))}
        </div>

        <Button variant="outline" className="mb-4 w-full" leftIcon={<Download className="h-4 w-4" />} onClick={downloadRecoveryCodes}>
          Download codes
        </Button>

        <label className="mb-4 flex items-start gap-2 text-sm text-gray-700">
          <input type="checkbox" className="mt-0.5" checked={codesSaved} onChange={(e) => setCodesSaved(e.target.checked)} />
          I've saved these recovery codes somewhere safe.
        </label>

        <Button className="w-full" size="lg" disabled={!codesSaved} onClick={() => {
            completeSignIn?.()
            navigate('/portal/dashboard')
          }}>
          Continue to Dashboard
        </Button>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout backTo="/portal/login" backLabel="Back to Sign In">
      <div className="mb-5">
        <h2 className="text-2xl font-bold text-gray-900">Set up two-factor authentication</h2>
        <p className="mt-1 text-sm text-gray-500">
          Two-factor authentication is required for every account. Scan the QR code below with an authenticator app
          (Google Authenticator, Authy, 1Password, etc.).
        </p>
      </div>

      {errorMessage && (
        <Alert type="error" className="mb-4">
          {errorMessage}
        </Alert>
      )}

      {loadingSetup ? (
        <div className="flex justify-center py-8 text-sm text-gray-500">Loading…</div>
      ) : (
        qrCode &&
        secret && (
          <>
            <div className="mb-4 flex justify-center rounded-lg border border-gray-200 bg-white p-4">
              <img src={qrCode} alt="Two-factor authentication QR code" className="h-48 w-48" />
            </div>

            <div className="mb-4 rounded-lg bg-gray-50 p-3">
              <p className="mb-1 text-xs font-medium text-gray-500">Can't scan? Enter this code manually:</p>
              <div className="flex items-center gap-2">
                <code className="flex-1 break-all font-mono text-sm text-gray-900">{secret}</code>
                <Button type="button" variant="ghost" size="sm" onClick={copySecret} aria-label="Copy secret">
                  {copied ? <Check className="h-4 w-4 text-success-600" /> : <Copy className="h-4 w-4" />}
                </Button>
              </div>
            </div>

            <form onSubmit={handleConfirm} className="space-y-4">
              <OtpInput label="Enter the 6-digit code from your app" value={code} onChange={setCode} error={!!errorMessage} autoFocus />
              <Button
                type="submit"
                className="w-full"
                size="lg"
                isLoading={confirming}
                disabled={code.length < 6}
                leftIcon={<ShieldCheck className="h-4 w-4" />}
              >
                Verify and Enable
              </Button>
            </form>
          </>
        )
      )}
    </AuthLayout>
  )
}
