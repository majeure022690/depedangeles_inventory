import { Laptop, LogOut } from 'lucide-react'
import { useEffect, useState } from 'react'

import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardContent, CardHeader } from '@/components/ui/Card'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { extractError } from '@/lib/errors'
import { trustedDeviceService, type TrustedDevice } from '@/services/trustedDeviceService'

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—'
}

export function TrustedDevicesCard() {
  const [devices, setDevices] = useState<TrustedDevice[]>([])
  const [trustDays, setTrustDays] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [busyId, setBusyId] = useState<number | 'all' | null>(null)
  const [confirmAll, setConfirmAll] = useState(false)

  async function load() {
    setError(null)
    try {
      const result = await trustedDeviceService.list()
      setDevices(result.data)
      setTrustDays(result.meta.trust_device_days)
    } catch (e) {
      setError(extractError(e, 'Unable to load trusted devices.'))
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    load()
  }, [])

  async function revoke(id: number) {
    setBusyId(id)
    try {
      await trustedDeviceService.revoke(id)
      await load()
    } catch (e) {
      setError(extractError(e))
    } finally {
      setBusyId(null)
    }
  }

  async function revokeAll() {
    setBusyId('all')
    try {
      await trustedDeviceService.revokeAll()
      setConfirmAll(false)
      await load()
    } catch (e) {
      setError(extractError(e))
    } finally {
      setBusyId(null)
    }
  }

  const noneHonoured = trustDays === 0 && devices.length > 0 && devices.every((device) => !device.is_honoured)

  return (
    <Card>
      <CardHeader>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex items-center gap-2">
            <Laptop className="h-4 w-4 text-gray-500" />
            <h2 className="font-semibold text-gray-900">Trusted devices</h2>
          </div>
          {devices.length > 0 && (
            <Button size="sm" variant="outline" className="text-red-600" onClick={() => setConfirmAll(true)}>
              Sign out all devices
            </Button>
          )}
        </div>
        <p className="mt-1 text-sm text-gray-500">
          {trustDays > 0
            ? `Browsers where you chose "Trust this device" skip the authenticator code for ${trustDays} days. Sign out any you don't recognize or no longer use.`
            : 'Your account always asks for an authenticator code when you sign in.'}
        </p>
      </CardHeader>
      <CardContent>
        {error && <Alert type="error" className="mb-3">{error}</Alert>}
        {loading ? (
          <div className="h-12 animate-pulse rounded-md bg-gray-100" />
        ) : devices.length === 0 ? (
          <p className="text-sm text-gray-500">No trusted devices.</p>
        ) : (
          <>
            {noneHonoured && (
              <p className="mb-2 text-sm text-gray-500">Your account always requires the 2FA code, so these devices won't skip it. You can still sign them out.</p>
            )}
            <ul className="divide-y divide-gray-100">
              {devices.map((device) => (
                <li key={device.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                  <div className="min-w-0">
                    <p className={`flex flex-wrap items-center gap-2 text-sm font-medium ${device.is_honoured ? 'text-gray-900' : 'text-gray-600'}`}>
                      {device.label}
                      {device.is_current && <Badge color="primary">This browser</Badge>}
                      {!device.is_honoured && <Badge>Not currently trusted</Badge>}
                    </p>
                    <p className="text-xs text-gray-500">
                      Last used {formatDate(device.last_used_at)}
                      {device.ip_address ? ` from ${device.ip_address}` : ''} ·{' '}
                      {device.is_honoured ? 'Trusted until' : 'Could be trusted again until'} {formatDate(device.expires_at)}
                    </p>
                  </div>
                  <Button
                    size="sm"
                    variant="ghost"
                    leftIcon={<LogOut className="h-4 w-4" />}
                    isLoading={busyId === device.id}
                    onClick={() => revoke(device.id)}
                  >
                    Sign out
                  </Button>
                </li>
              ))}
            </ul>
          </>
        )}
      </CardContent>

      <ConfirmDialog
        open={confirmAll}
        onOpenChange={setConfirmAll}
        onConfirm={revokeAll}
        loading={busyId === 'all'}
        title="Sign out all trusted devices?"
        description="Every browser you trusted, including this one, will ask for an authenticator code at the next sign-in. You stay signed in right now."
        confirmText="Sign out all"
      />
    </Card>
  )
}
