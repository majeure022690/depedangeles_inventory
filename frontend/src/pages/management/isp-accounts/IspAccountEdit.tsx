import { ArrowLeft, Wifi } from 'lucide-react'
import { useEffect, useState, type FormEvent } from 'react'
import { useLocation, useNavigate, useParams } from 'react-router-dom'

import { DetailPageSkeleton } from '@/components/DetailPageSkeleton'
import { IspAccountFields } from '@/components/isp-accounts/IspAccountFields'
import { SpeedTestLog } from '@/components/isp-accounts/SpeedTestLog'
import { SubscriptionCostLog } from '@/components/isp-accounts/SubscriptionCostLog'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardContent } from '@/components/ui/Card'
import { TabPanel, Tabs } from '@/components/ui/Tabs'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { extractError, extractFieldErrors, isValidationError, type FieldErrors } from '@/lib/errors'
import type { PermissionName } from '@/lib/permissions'
import { ispAccountFormFrom, ispAccountService } from '@/services/ispAccountService'
import type { IspAccount, IspAccountFormData, IspAccountOptions } from '@/types/isp-account'

type Feedback = { type: 'success' | 'error'; message: string } | null
type Tab = 'details' | 'speed-tests' | 'subscription-costs'

export function IspAccountEdit() {
  const { id } = useParams<{ id: string }>()
  const accountId = Number(id)
  const navigate = useNavigate()
  const location = useLocation()
  const { can } = useAuth()
  const canEdit = can('isp_accounts.edit' satisfies PermissionName)

  const [account, setAccount] = useState<IspAccount | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [options, setOptions] = useState<IspAccountOptions | null>(null)

  const [form, setForm] = useState<IspAccountFormData | null>(null)
  const [savedForm, setSavedForm] = useState<IspAccountFormData | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [feedback, setFeedback] = useState<Feedback>(() => {
    const flash = (location.state as { flash?: string } | null)?.flash
    return flash ? { type: 'success', message: flash } : null
  })
  const [tab, setTab] = useState<Tab>('details')

  usePageTitle(account ? `${canEdit ? 'Edit ' : ''}${account.isp ?? 'ISP account'}` : 'ISP account')

  // The create page's flash is one-shot: drop it from history so a refresh doesn't repeat it.
  useEffect(() => {
    if ((location.state as { flash?: string } | null)?.flash) navigate(location.pathname, { replace: true, state: null })
  }, [location.pathname, location.state, navigate])

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setLoadError(null)
    Promise.all([ispAccountService.show(accountId), ispAccountService.options()])
      .then(([loaded, loadedOptions]) => {
        if (cancelled) return
        const initial = ispAccountFormFrom(loaded)
        setAccount(loaded)
        setOptions(loadedOptions)
        setForm(initial)
        setSavedForm(initial)
      })
      .catch((error: unknown) => {
        if (!cancelled) setLoadError(extractError(error, 'Unable to load this ISP account.'))
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [accountId])

  if (loading) return <DetailPageSkeleton />
  if (loadError || !account || !form) {
    return (
      <Alert type="error">
        <span role="alert">{loadError ?? 'ISP account not found.'}</span>
      </Alert>
    )
  }

  const dirty = JSON.stringify(form) !== JSON.stringify(savedForm)
  const speedTests = account.speed_tests ?? []
  const subscriptionCosts = account.subscription_costs ?? []

  function setField<K extends keyof IspAccountFormData>(field: K, value: IspAccountFormData[K]) {
    setForm((prev) => (prev ? { ...prev, [field]: value } : prev))
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (!form) return
    setSaving(true)
    setErrors({})
    setFeedback(null)
    try {
      const response = await ispAccountService.update(accountId, form)
      // The update response doesn't carry the logs; keep the ones already loaded.
      setAccount((prev) => ({ ...response.data, speed_tests: prev?.speed_tests, subscription_costs: prev?.subscription_costs }))
      const next = ispAccountFormFrom(response.data)
      setForm(next)
      setSavedForm(next)
      setFeedback({ type: 'success', message: response.message })
    } catch (error) {
      const fieldErrors = isValidationError(error) ? extractFieldErrors(error) : null
      if (fieldErrors) setErrors(fieldErrors)
      setFeedback({
        type: 'error',
        message: fieldErrors ? 'Unable to save changes. Please review the highlighted fields and try again.' : extractError(error),
      })
    } finally {
      setSaving(false)
      window.scrollTo({ top: 0, behavior: 'smooth' })
    }
  }

  const tabs = [
    { value: 'details', label: dirty ? 'Details •' : 'Details' },
    { value: 'speed-tests', label: `Speed tests (${speedTests.length})` },
    { value: 'subscription-costs', label: `Subscription costs (${subscriptionCosts.length})` },
  ]

  return (
    <div>
      <div className="mb-4 flex min-w-0 items-start gap-3">
        <Button variant="ghost" size="sm" onClick={() => navigate('/portal/isp-accounts')} aria-label="Back to ISP accounts" className="mt-1.5">
          <ArrowLeft className="h-4 w-4" />
        </Button>
        <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700" aria-hidden="true">
          <Wifi className="h-6 w-6" />
        </span>
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-2xl font-bold text-gray-900">
              {canEdit ? 'Edit ' : ''}
              {account.isp ?? 'ISP account'}
            </h1>
            <Badge color={account.inactive_contract ? 'error' : 'success'}>{account.inactive_contract ? 'Inactive' : 'Active'}</Badge>
          </div>
          <p className="text-sm text-gray-600">Billing account no. {account.isp_billing_account_no}</p>
        </div>
      </div>

      {feedback && (
        <Alert type={feedback.type} className="mb-4">
          <span role={feedback.type === 'error' ? 'alert' : 'status'}>{feedback.message}</span>
        </Alert>
      )}

      <div className="mb-4">
        <Tabs id="isp-account" label="ISP account sections" tabs={tabs} value={tab} onChange={(value) => setTab(value as Tab)} />
      </div>

      <TabPanel tabsId="isp-account" value="details" active={tab === 'details'}>
        <form noValidate onSubmit={handleSubmit} aria-label="ISP account details">
          <Card>
            <CardContent className="p-0">
              <IspAccountFields form={form} onChange={setField} options={options?.form ?? null} errors={errors} disabled={!canEdit || saving} />
            </CardContent>
            {canEdit && (
              <div className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 bg-gray-50/60 px-5 py-3">
                <div>
                  {dirty && (
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => {
                        setForm(savedForm)
                        setErrors({})
                      }}
                    >
                      Discard changes
                    </Button>
                  )}
                </div>
                <div className="flex items-center gap-3">
                  {dirty ? (
                    <span className="text-xs font-medium text-amber-700">Unsaved changes</span>
                  ) : (
                    <span className="text-xs text-gray-400">All changes saved</span>
                  )}
                  <Button type="submit" isLoading={saving} disabled={!dirty}>
                    Save changes
                  </Button>
                </div>
              </div>
            )}
          </Card>
        </form>
      </TabPanel>

      <TabPanel tabsId="isp-account" value="speed-tests" active={tab === 'speed-tests'}>
        <SpeedTestLog
          ispAccountId={account.id}
          speedTests={speedTests}
          signalQualityOptions={options?.speed_test.signal_quality ?? []}
          canRecord={canEdit}
          onRecorded={(test) => setAccount((prev) => (prev ? { ...prev, speed_tests: [test, ...(prev.speed_tests ?? [])] } : prev))}
        />
      </TabPanel>

      <TabPanel tabsId="isp-account" value="subscription-costs" active={tab === 'subscription-costs'}>
        <SubscriptionCostLog
          ispAccountId={account.id}
          subscriptionCosts={subscriptionCosts}
          canRecord={canEdit}
          onRecorded={(cost) => setAccount((prev) => (prev ? { ...prev, subscription_costs: [cost, ...(prev.subscription_costs ?? [])] } : prev))}
        />
      </TabPanel>
    </div>
  )
}
