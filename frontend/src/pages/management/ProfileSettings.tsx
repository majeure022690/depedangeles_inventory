import { Camera, Download, KeyRound, LockKeyhole, Save, ShieldCheck, ShieldAlert, Trash2 } from 'lucide-react'
import { useEffect, useState, type ChangeEvent, type FormEvent } from 'react'
import { isAxiosError } from 'axios'

import { TrustedDevicesCard } from '@/components/TrustedDevicesCard'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardContent, CardHeader } from '@/components/ui/Card'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/Dialog'
import { Input } from '@/components/ui/Input'
import { ImageCropDialog } from '@/components/ImageCropDialog'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { isTooManyRequests, TOO_MANY_ATTEMPTS_MESSAGE } from '@/lib/errors'
import { profileService } from '@/services/profileService'
import { twoFactorService } from '@/services/twoFactorService'

function getInitials(name: string): string {
  return name
    .split(' ')
    .map((part) => part[0])
    .filter(Boolean)
    .slice(0, 2)
    .join('')
    .toUpperCase()
}

export function ProfileSettings() {
  usePageTitle('Profile Settings')
  const { user, fetchUser } = useAuth()
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [avatar, setAvatar] = useState<File | null>(null)
  const [avatarPreview, setAvatarPreview] = useState<string | null>(null)
  const [removeAvatar, setRemoveAvatar] = useState(false)
  const [cropSource, setCropSource] = useState<{ url: string; name: string; type: string } | null>(null)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const [show2faModal, setShow2faModal] = useState(false)
  const [twoFaPassword, setTwoFaPassword] = useState('')
  const [twoFaModalError, setTwoFaModalError] = useState<string | null>(null)
  const [regeneratingCodes, setRegeneratingCodes] = useState(false)
  const [newRecoveryCodes, setNewRecoveryCodes] = useState<string[] | null>(null)

  useEffect(() => {
    if (!user) return
    setName(user.name)
    setEmail(user.email)
    setAvatarPreview(user.avatar_url)
  }, [user])

  useEffect(() => {
    if (!avatar) return
    const objectUrl = URL.createObjectURL(avatar)
    setAvatarPreview(objectUrl)
    setRemoveAvatar(false)
    return () => URL.revokeObjectURL(objectUrl)
  }, [avatar])

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    void saveProfile()
  }

  async function saveProfile() {
    setSaving(true)
    setMessage(null)
    setError(null)

    try {
      await profileService.update({
        name,
        email,
        avatar,
        remove_avatar: removeAvatar,
      })
      await fetchUser()
      setAvatar(null)
      setRemoveAvatar(false)
      setMessage('Your profile photo has been updated.')
    } catch (requestError) {
      const fallback = 'Unable to update your profile.'
      let messageText = fallback
      if (isTooManyRequests(requestError)) {
        messageText = TOO_MANY_ATTEMPTS_MESSAGE
      } else if (isAxiosError<{ message?: string; errors?: Record<string, string[]> }>(requestError)) {
        const validationMessage = requestError.response?.data?.errors
          ? Object.values(requestError.response.data.errors).flat()[0]
          : undefined
        messageText = validationMessage ?? requestError.response?.data?.message ?? fallback
      }
      setError(messageText)
    } finally {
      setSaving(false)
    }
  }

  function handleAvatarChange(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0]
    // Reset so choosing the same file again still fires onChange.
    event.target.value = ''
    if (!file) return
    setCropSource({ url: URL.createObjectURL(file), name: file.name, type: file.type })
  }

  function handleCropOpenChange(open: boolean) {
    if (open || !cropSource) return
    URL.revokeObjectURL(cropSource.url)
    setCropSource(null)
  }

  function handleRemoveAvatar() {
    setAvatar(null)
    setAvatarPreview(null)
    setRemoveAvatar(true)
  }

  function handle2faModalOpenChange(open: boolean) {
    setShow2faModal(open)
    if (!open) {
      setTwoFaModalError(null)
      setTwoFaPassword('')
    }
  }

  async function handleRegenerateCodes() {
    setRegeneratingCodes(true)
    setTwoFaModalError(null)

    try {
      const result = await twoFactorService.regenerateRecoveryCodes(twoFaPassword)
      setNewRecoveryCodes(result.recovery_codes)
      handle2faModalOpenChange(false)
    } catch (requestError) {
      const fallback = 'Unable to regenerate recovery codes.'
      if (isTooManyRequests(requestError)) {
        setTwoFaModalError(TOO_MANY_ATTEMPTS_MESSAGE)
      } else if (isAxiosError<{ message?: string; errors?: Record<string, string[]> }>(requestError)) {
        const validationMessage = requestError.response?.data?.errors
          ? Object.values(requestError.response.data.errors).flat()[0]
          : undefined
        setTwoFaModalError(validationMessage ?? requestError.response?.data?.message ?? fallback)
      } else {
        setTwoFaModalError(fallback)
      }
    } finally {
      setRegeneratingCodes(false)
    }
  }

  function downloadRecoveryCodes() {
    if (!newRecoveryCodes) return
    const blob = new Blob([newRecoveryCodes.join('\n')], { type: 'text/plain' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = 'dictis-recovery-codes.txt'
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  }


  if (!user) return null

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Profile Settings</h1>
          <p className="text-sm text-gray-500">Update your profile photo, account details, and password.</p>
        </div>
      </div>

      {message && <Alert type="success">{message}</Alert>}
      {error && <Alert type="error">{error}</Alert>}

      <form onSubmit={handleSubmit} className="space-y-4">
        <Card>
          <CardHeader>
            <h2 className="font-semibold text-gray-900">Profile photo</h2>
            <p className="mt-1 text-sm text-gray-500">This photo appears beside your name in the management header.</p>
          </CardHeader>
          <CardContent>
            <div className="flex flex-col items-start gap-4 sm:flex-row sm:items-center">
              {avatarPreview ? (
                <img src={avatarPreview} alt="Profile preview" className="h-24 w-24 rounded-full object-cover ring-4 ring-primary-50" />
              ) : (
                <div className="flex h-24 w-24 items-center justify-center rounded-full bg-primary-100 text-2xl font-semibold text-primary-700 ring-4 ring-primary-50">
                  {getInitials(name)}
                </div>
              )}
              <div className="flex flex-wrap gap-2">
                <label className="inline-flex h-10 cursor-pointer items-center gap-2 rounded-md bg-primary-600 px-4 text-sm font-medium text-white shadow-xs hover:bg-primary-700 dark:hover:bg-primary-500">
                  <Camera className="h-4 w-4" />
                  Choose photo
                  <input type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={handleAvatarChange} />
                </label>
                {(avatarPreview || user.avatar_url) && (
                  <Button type="button" variant="outline" leftIcon={<Trash2 className="h-4 w-4" />} onClick={handleRemoveAvatar}>
                    Remove
                  </Button>
                )}
              </div>
            </div>
            <p className="mt-3 text-xs text-gray-500">JPG, PNG, or WebP up to 5 MB.</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <h2 className="font-semibold text-gray-900">Account details</h2>
            <p className="mt-1 text-sm text-gray-500">Profile data is managed by PRIME. To update your name or email, please update it in the PRIME system.</p>
          </CardHeader>
          <CardContent className="grid gap-4 sm:grid-cols-2">
            <Input label="Name" value={name} onChange={(event) => setName(event.target.value)} disabled />
            <Input label="Email" type="email" value={email} onChange={(event) => setEmail(event.target.value)} disabled />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <div className="flex items-center gap-2">
              <LockKeyhole className="h-4 w-4 text-primary-600" />
              <h2 className="font-semibold text-gray-900">Change password</h2>
            </div>
            <p className="mt-1 text-sm text-gray-500">Password changes must be done in the PRIME system.</p>
          </CardHeader>
          <CardContent>
            <Alert type="info">
              To change your password, please sign in to PRIME and update it there. Your password will automatically sync to this system on your next login.
            </Alert>
          </CardContent>
        </Card>

        <div className="flex justify-end">
          <Button type="submit" isLoading={saving} leftIcon={<Save className="h-4 w-4" />}>
            Save photo
          </Button>
        </div>
      </form>

      <Card>
        <CardHeader>
          <div className="flex items-center gap-2">
            {user.two_factor_enabled ? (
              <ShieldCheck className="h-4 w-4 text-success-600" />
            ) : (
              <ShieldAlert className="h-4 w-4 text-warning-600" />
            )}
            <h2 className="font-semibold text-gray-900">Two-factor authentication</h2>
          </div>
          <p className="mt-1 text-sm text-gray-500">Two-factor authentication is required for every account.</p>
        </CardHeader>
        <CardContent>
          {user.two_factor_enabled ? (
            <>
              <Badge color="success" className="mb-4">Enabled</Badge>

              {newRecoveryCodes ? (
                <div className="rounded-lg border border-gray-200 bg-gray-50 p-4">
                  <p className="mb-3 text-sm text-gray-700">
                    Your old recovery codes no longer work. Save these new ones somewhere safe.
                  </p>
                  <div className="mb-3 grid grid-cols-2 gap-2 font-mono text-sm">
                    {newRecoveryCodes.map((recoveryCode) => (
                      <div key={recoveryCode}>{recoveryCode}</div>
                    ))}
                  </div>
                  <div className="flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" leftIcon={<Download className="h-4 w-4" />} onClick={downloadRecoveryCodes}>
                      Download codes
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setNewRecoveryCodes(null)}>
                      Done
                    </Button>
                  </div>
                </div>
              ) : (
                <Button variant="outline" leftIcon={<KeyRound className="h-4 w-4" />} onClick={() => setShow2faModal(true)}>
                  Regenerate recovery codes
                </Button>
              )}
            </>
          ) : (
            <Alert type="warning">
              Two-factor authentication is not set up on this account. You'll be asked to set it up the next time you sign in.
            </Alert>
          )}
        </CardContent>
      </Card>

      {user.two_factor_enabled && <TrustedDevicesCard />}

      <Dialog open={show2faModal} onOpenChange={handle2faModalOpenChange}>
        <DialogContent size="sm">
          <DialogTitle>Regenerate recovery codes</DialogTitle>
          <DialogDescription>
            Your existing recovery codes will stop working. Enter your current password to confirm.
          </DialogDescription>
          {twoFaModalError && <Alert type="error">{twoFaModalError}</Alert>}
          <Input
            label="Current password"
            type="password"
            autoComplete="off"
            autoFocus
            value={twoFaPassword}
            onChange={(event) => setTwoFaPassword(event.target.value)}
            required
          />
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => handle2faModalOpenChange(false)}>
              Cancel
            </Button>
            <Button type="button" isLoading={regeneratingCodes} disabled={!twoFaPassword} onClick={() => void handleRegenerateCodes()}>
              Regenerate
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {cropSource && (
        <ImageCropDialog
          open
          onOpenChange={handleCropOpenChange}
          imageSrc={cropSource.url}
          fileName={cropSource.name}
          mimeType={cropSource.type}
          aspect={1}
          showOpacity={false}
          onConfirm={(file) => setAvatar(file)}
        />
      )}
    </div>
  )
}

