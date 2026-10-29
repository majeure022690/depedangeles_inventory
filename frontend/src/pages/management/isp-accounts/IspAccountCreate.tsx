import { ArrowLeft, Wifi } from 'lucide-react'
import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'

import { IspAccountFields } from '@/components/isp-accounts/IspAccountFields'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { TabPanel, Tabs, type TabItem } from '@/components/ui/Tabs'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { hasAllScope } from '@/lib/dataScope'
import { extractError, extractFieldErrors, isValidationError, type FieldErrors } from '@/lib/errors'
import { emptyIspAccountForm, ispAccountService } from '@/services/ispAccountService'
import type { IspAccountFormData, IspAccountOptions } from '@/types/isp-account'

type TabId = 'provider' | 'billing' | 'coverage' | 'acquisition'

const TABS_ID = 'isp-account-create'
const ISP_TABS: { value: TabId; label: string }[] = [
  { value: 'provider', label: 'Provider & Billing' },
  { value: 'billing', label: 'Subscription & Contract' },
  { value: 'coverage', label: 'Coverage & Speed' },
  { value: 'acquisition', label: 'Quality & Acquisition' },
]

function isIspTab(value: string | null): value is TabId {
  return value === 'provider' || value === 'billing' || value === 'coverage' || value === 'acquisition'
}

function errorCount(tabId: TabId, errors: FieldErrors): number {
  const fieldsByTab: Record<TabId, (keyof IspAccountFormData)[]> = {
    provider: ['isp_provider_id', 'office_id', 'isp_billing_account_no', 'cost_per_month', 'package_purchased_inclusion', 'fund_source'],
    billing: ['subscription_type', 'isp_connection_type', 'contract_start_date', 'contract_end_date', 'inactive_contract', 'donor', 'purpose_of_subscription'],
    coverage: ['school_area_coverage', 'min_speed', 'max_speed', 'number_access_points_linked', 'locations_access_points', 'number_admin_area_covered', 'rate_connectivity_admin_areas', 'number_classrooms_covered', 'rate_connectivity_classroom'],
    acquisition: ['mode_of_acquisition', 'source_of_acquisition', 'overall_signal_quality', 'rate_isp_service'],
  }
  return fieldsByTab[tabId].filter((field) => !!errors[field]).length
}

export function IspAccountCreate() {
  usePageTitle('Add ISP account')
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const { user } = useAuth()

  const [options, setOptions] = useState<IspAccountOptions | null>(null)
  const [optionsError, setOptionsError] = useState<string | null>(null)
  const [form, setForm] = useState<IspAccountFormData>(() => ({
    ...emptyIspAccountForm(),
    office_id: !hasAllScope(user) && typeof user?.office_id === 'number' ? String(user.office_id) : '',
  }))
  const [errors, setErrors] = useState<FieldErrors>({})
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const requestedTab = searchParams.get('tab')
  const tab = isIspTab(requestedTab) ? requestedTab : ISP_TABS[0].value

  const selectTab = useCallback(
    (next: string) => {
      setSearchParams(
        (prev) => {
          const params = new URLSearchParams(prev)
          if (next === ISP_TABS[0].value) params.delete('tab')
          else params.set('tab', next)
          return params
        },
        { replace: true },
      )
    },
    [setSearchParams],
  )

  useEffect(() => {
    ispAccountService
      .options()
      .then(setOptions)
      .catch((error: unknown) => setOptionsError(extractError(error, 'Unable to load the form options. Please refresh the page.')))
  }, [])

  function setField<K extends keyof IspAccountFormData>(field: K, value: IspAccountFormData[K]) {
    setForm((prev) => ({ ...prev, [field]: value }))
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors({})
    setErrorMessage(null)
    try {
      const response = await ispAccountService.create(form)
      navigate(`/isp-accounts/${response.data.id}/edit`, { state: { flash: response.message } })
    } catch (error) {
      const fieldErrors = isValidationError(error) ? extractFieldErrors(error) : null
      if (fieldErrors) setErrors(fieldErrors)
      setErrorMessage(fieldErrors ? 'Unable to save the ISP account. Please review the highlighted fields and try again.' : extractError(error))
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } finally {
      setSaving(false)
    }
  }

  const tabs: TabItem[] = ISP_TABS.map((t) => ({ value: t.value, label: t.label, errorCount: errorCount(t.value, errors) }))

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
          <h1 className="text-xl font-bold text-gray-900">Add ISP account</h1>
          <p className="text-sm text-gray-600">
            Register a new internet service subscription. Fields marked <span aria-hidden="true" className="text-error-600">*</span>
            <span className="sr-only">with an asterisk</span> are required.
          </p>
        </div>
      </div>

      <form onSubmit={handleSubmit} noValidate aria-label="Add ISP account" className="space-y-4">
        <div role="alert" className="space-y-2 empty:hidden">
          {optionsError && <Alert type="error">{optionsError}</Alert>}
          {errorMessage && <Alert type="error">{errorMessage}</Alert>}
        </div>

        <div>
          <Tabs id={TABS_ID} label="ISP account sections" tabs={tabs} value={tab} onChange={selectTab} />
          {ISP_TABS.map((t) => (
            <TabPanel key={t.value} tabsId={TABS_ID} value={t.value} active={tab === t.value}>
              <IspAccountFields section={t.value} form={form} onChange={setField} options={options?.form ?? null} errors={errors} disabled={saving} />
            </TabPanel>
          ))}
        </div>

        <Card>
          <div className="flex flex-wrap items-center justify-end gap-3 border-t border-gray-100 bg-gray-50/60 px-5 py-3">
            <Button variant="outline" onClick={() => navigate('/isp-accounts')} disabled={saving}>
              Cancel
            </Button>
            <Button type="submit" isLoading={saving} disabled={!options}>
              Save ISP account
            </Button>
          </div>
        </Card>
      </form>
    </div>
  )
}
