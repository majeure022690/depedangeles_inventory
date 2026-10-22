import { ShieldCheck } from 'lucide-react'
import { useEffect, useState } from 'react'
import { isAxiosError } from 'axios'

import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card, CardContent, CardHeader } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { PasswordConfirmDialog } from '@/components/ui/PasswordConfirmDialog'
import { useAuth } from '@/context/AuthContext'
import { securitySettingService, type SecuritySetting } from '@/services/securitySettingService'

export function SecuritySettingsPanel() {
  const { can } = useAuth()
  const [settings, setSettings] = useState<SecuritySetting[]>([])
  const [loading, setLoading] = useState(true)
  const [pending, setPending] = useState<SecuritySetting | null>(null)
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  async function loadSettings() {
    setLoading(true)
    setError(null)
    try {
      setSettings(await securitySettingService.list())
    } catch (requestError) {
      setError(isAxiosError(requestError) ? requestError.response?.data?.message ?? 'Unable to load security settings.' : 'Unable to load security settings.')
    } finally {
      setLoading(false)
    }
  }

  const canManage = can('security_settings.manage')

  useEffect(() => {
    if (canManage) loadSettings()
  }, [canManage])

  function requestSave(setting: SecuritySetting) {
    setMessage(null)
    setError(null)
    setPending(setting)
    setConfirmOpen(true)
  }

  async function saveSetting(currentPassword: string) {
    if (!pending) return
    const updated = await securitySettingService.update(pending.key, pending.value, currentPassword)
    setSettings((current) => current.map((item) => (item.key === updated.key ? updated : item)))
    setMessage(`${updated.label} updated.`)
  }

  if (!canManage) return null

  return (
    <Card>
      <CardHeader>
        <div className="flex items-start gap-3">
          <span className="rounded-lg bg-primary-600 p-2 text-white"><ShieldCheck className="h-5 w-5" /></span>
          <div>
            <h2 className="font-semibold text-gray-900">Security and rate limits</h2>
            <p className="mt-1 text-sm text-gray-500">Control abuse protection for sign-in, password resets, two-factor codes, exports, and idle sessions.</p>
          </div>
        </div>
      </CardHeader>
      <CardContent>
        {message && <Alert type="success" className="mb-4">{message}</Alert>}
        {error && <Alert type="error" className="mb-4">{error}</Alert>}
        {loading ? (
          <div className="space-y-3">{[1, 2, 3].map((item) => <div key={item} className="h-16 animate-pulse rounded-lg bg-gray-100" />)}</div>
        ) : (
          <div className="divide-y divide-gray-100">
            {settings.map((setting) => (
              <div key={setting.key} className="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0 sm:max-w-2xl">
                  <p className="text-sm font-medium text-gray-900">{setting.label}</p>
                  <p className="mt-1 text-xs text-gray-500">{setting.description} Range: {setting.min} to {setting.max}.</p>
                </div>
                <div className="flex items-end gap-2 sm:w-44 sm:shrink-0">
                  <Input
                    aria-label={setting.label}
                    type="number"
                    min={setting.min}
                    max={setting.max}
                    value={setting.value}
                    onChange={(event) => {
                      const value = Number(event.target.value)
                      setSettings((current) => current.map((item) => item.key === setting.key ? { ...item, value } : item))
                    }}
                  />
                  <Button size="sm" onClick={() => requestSave(setting)}>Save</Button>
                </div>
              </div>
            ))}
          </div>
        )}
      </CardContent>

      <PasswordConfirmDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        onConfirm={saveSetting}
        description={pending ? `Enter your password to set ${pending.label} to ${pending.value}.` : ''}
        confirmText="Save"
        fallbackError="Unable to update security setting."
      />
    </Card>
  )
}
