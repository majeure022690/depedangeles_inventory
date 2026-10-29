import { ArrowLeft, Wifi } from 'lucide-react'
import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom'

import { DetailPageSkeleton } from '@/components/DetailPageSkeleton'
import { IspAccountFields } from '@/components/isp-accounts/IspAccountFields'
import { SpeedTestLog } from '@/components/isp-accounts/SpeedTestLog'
import { SubscriptionCostLog } from '@/components/isp-accounts/SubscriptionCostLog'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { TabPanel, Tabs, type TabItem } from '@/components/ui/Tabs'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { extractError, extractFieldErrors, extractLoadError, isValidationError, OUT_OF_REACH_MESSAGE, type FieldErrors } from '@/lib/errors'
import type { PermissionName } from '@/lib/permissions'
import { ispAccountFormFrom, ispAccountService } from '@/services/ispAccountService'
import type { IspAccount, IspAccountFormData, IspAccountOptions } from '@/types/isp-account'

type Feedback = { type: 'success' | 'error'; message: string } | null
type Tab = 'provider' | 'billing' | 'coverage' | 'acquisition' | 'speed-tests' | 'subscription-costs'

const ISP_FIELD_TABS: { value: Tab; label: string }[] = [
  { value: 'provider', label: 'Provider & Billing' },
  { value: 'billing', label: 'Subscription & Contract' },
  { value: 'coverage', label: 'Coverage & Speed' },
  { value: 'acquisition', label: 'Quality & Acquisition' },
]

function isFieldTab(value: string | null): value is 'provider' | 'billing' | 'coverage' | 'acquisition' {
  return value === 'provider' || value === 'billing' || value === 'coverage' || value === 'acquisition'
}

function errorCount(tabId: 'provider' | 'billing' | 'coverage' | 'acquisition', errors: FieldErrors): number {
  const fieldsByTab: Record<'provider' | 'billing' | 'coverage' | 'acquisition', (keyof IspAccountFormData)[]> = {
    provider: ['isp_provider_id', 'office_id', 'isp_billing_account_no', 'cost_per_month', 'package_purchased_inclusion', 'fund_source'],
    billing: ['subscription_type', 'isp_connection_type', 'contract_start_date', 'contract_end_date', 'inactive_contract', 'donor', 'purpose_of_subscription'],
    coverage: ['school_area_coverage', 'min_speed', 'max_speed', 'number_access_points_linked', 'locations_access_points', 'number_admin_area_covered', 'rate_connectivity_admin_areas', 'number_classrooms_covered', 'rate_connectivity_classroom'],
    acquisition: ['mode_of_acquisition', 'source_of_acquisition', 'overall_signal_quality', 'rate_isp_service'],
  }
  return fieldsByTab[tabId].filter((field) => !!errors[field]).length
}

export function IspAccountEdit() {
  const { id } = useParams<{ id: string }>()
  const accountId = Number(id)
  const navigate = useNavigate()
  const location = useLocation()
  const [searchParams, setSearchParams] = useSearchParams()
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

  const requestedTab = searchParams.get('tab')
  const tab: Tab = requestedTab === 'speed-tests' || requestedTab === 'subscription-costs' || isFieldTab(requestedTab) ? requestedTab : ISP_FIELD_TABS[0].value

  const selectTab = useCallback(
    (next: string) => {
      setSearchParams(
        (prev) => {
          const params = new URLSearchParams(prev)
          if (next === ISP_FIELD_TABS[0].value) params.delete('tab')
          else params.set('tab', next)
          return params
        },
        { replace: true },
      )
    },
    [setSearchParams],
  )

  usePageTitle(account ? `${canEdit ? 'Edit ' : ''}${account.isp_provider?.name ?? 'ISP account'}` : 'ISP account')

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
        if (!cancelled) setLoadError(extractLoadError(error, 'Unable to load this ISP account.'))
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
      <div>
        <Button variant="ghost" size="sm" onClick={() => navigate('/isp-accounts')} aria-label="Back to ISP accounts">
          <ArrowLeft className="h-4 w-4" />
        </Button>
        <Alert type="error" className="mt-4">
          <span role="alert">{loadError ?? OUT_OF_REACH_MESSAGE}</span>
        </Alert>
      </div>
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

  const tabs: TabItem[] = [
    ...ISP_FIELD_TABS.map((t) => ({ value: t.value, label: t.label, errorCount: isFieldTab(t.value) ? errorCount(t.value, errors) : 0 })),
    { value: 'speed-tests', label: 'Speed tests', count: speedTests.length },
    { value: 'subscription-costs', label: 'Subscription costs', count: subscriptionCosts.length },
  ]

  return (
    <div className="min-w-0">
      <div className="mb-4 flex items-start gap-3">
        <Button variant="ghost" size="sm" onClick={() => navigate('/isp-accounts')} aria-label="Back to ISP accounts" className="mt-0.5">
          <ArrowLeft className="h-4 w-4" />
        </Button>
        <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700" aria-hidden="true">
          <Wifi className="h-6 w-6" />
        </span>
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-xl font-bold text-gray-900">
              {canEdit ? 'Edit ' : ''}
              {account.isp_provider?.name ?? 'ISP account'}
            </h1>
            <Badge color={account.inactive_contract ? 'error' : 'success'}>{account.inactive_contract ? 'Inactive' : 'Active'}</Badge>
          </div>
          <p className="text-sm text-gray-600">Billing account no. {account.isp_billing_account_no}</p>
        </div>
      </div>

      <form onSubmit={handleSubmit} noValidate aria-label="ISP account details" className="space-y-4">
        <div role="alert" className="space-y-2 empty:hidden">
          {feedback && <Alert type={feedback.type}>{feedback.message}</Alert>}
        </div>

        <div>
          <Tabs id="isp-account" label="ISP account sections" tabs={tabs} value={tab} onChange={selectTab} />
          {ISP_FIELD_TABS.map((t) => (
            <TabPanel key={t.value} tabsId="isp-account" value={t.value} active={tab === t.value}>
              <IspAccountFields section={t.value} form={form!} onChange={setField} options={options?.form ?? null} errors={errors} disabled={!canEdit || saving} current={account} />
            </TabPanel>
          ))}
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

        {canEdit && (
          <Card>
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
          </Card>
        )}
      </form>
    </div>
  )
}
