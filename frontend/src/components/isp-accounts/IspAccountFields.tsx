import { FileText, Gauge, HandCoins, Receipt, Router, Signal } from 'lucide-react'

import { DetailSection } from '@/components/ui/DetailSection'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Switch } from '@/components/ui/Switch'
import { Textarea } from '@/components/ui/Textarea'
import { useAuth } from '@/context/AuthContext'
import { hasAllScope, SCOPE_LOCK_HINT } from '@/lib/dataScope'
import type { FieldErrors } from '@/lib/errors'
import { withCurrentOption } from '@/lib/options'
import type { IspAccount, IspAccountFormData, IspAccountOptions } from '@/types/isp-account'

interface IspAccountFieldsProps {
  section: TabId
  form: IspAccountFormData
  onChange: <K extends keyof IspAccountFormData>(field: K, value: IspAccountFormData[K]) => void
  options: IspAccountOptions['form'] | null
  errors: FieldErrors
  disabled?: boolean
  /** The saved record on edit, so an inactive office still shows its name. */
  current?: IspAccount | null
}

type TabId = 'provider' | 'billing' | 'coverage' | 'acquisition'

/** The ISP account form body (shared by create and edit), organized into tabs. */
export function IspAccountFields({ section, form, onChange, options, errors, disabled = false, current }: IspAccountFieldsProps) {
  const { user } = useAuth()
  const scopeLocked = !hasAllScope(user)
  const officeId = form.office_id === '' ? null : Number(form.office_id)
  const officeLabel =
    current?.office_id === officeId ? current?.office?.office_name : officeId === user?.office_id ? user?.office?.office_name : null

  const text = (field: keyof IspAccountFormData) => ({
    id: field,
    name: field,
    value: form[field] as string,
    error: errors[field],
    disabled,
    onChange: (e: { target: { value: string } }) => onChange(field, e.target.value as never),
  })

  if (section === 'provider') {
    return (
      <div className="space-y-4">
        <DetailSection icon={<Receipt className="h-4 w-4" />} title="Provider information">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Select {...text('isp_provider_id')} label="ISP provider" placeholder="Select an ISP" options={options?.isp_provider ?? []} required />
              <Select
                {...text('office_id')}
                label="School/office (optional)"
                placeholder="None"
                options={withCurrentOption(options?.office, officeId, officeLabel)}
                disabled={disabled || scopeLocked || !options}
                hint={scopeLocked && !disabled ? SCOPE_LOCK_HINT : undefined}
              />
            </div>
          </DetailSection>

          <DetailSection icon={<FileText className="h-4 w-4" />} title="Billing details">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Input {...text('isp_billing_account_no')} label="Billing account no." required />
              <Input {...text('cost_per_month')} label="Cost per month" type="number" step="0.01" min="0" inputMode="decimal" required />
              <div className="sm:col-span-2">
                <Textarea {...text('package_purchased_inclusion')} label="Package inclusions" required />
              </div>
            </div>
          </DetailSection>

          <DetailSection icon={<HandCoins className="h-4 w-4" />} title="Funding" last>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Select {...text('fund_source')} label="Source of funds" placeholder="Select a source" options={options?.source_of_funds ?? []} required />
            </div>
          </DetailSection>
      </div>
    )
  }

  if (section === 'billing') {
    return (
      <div className="space-y-4">
          <DetailSection icon={<FileText className="h-4 w-4" />} title="Subscription & contract">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Select {...text('subscription_type')} label="Subscription type" placeholder="Select a type" options={options?.subscription_type ?? []} required />
              <Select {...text('isp_connection_type')} label="Connection type" placeholder="Select a type" options={options?.isp_connection_type ?? []} required />
              <Input {...text('contract_start_date')} label="Contract start date" type="date" required />
              <Input {...text('contract_end_date')} label="Contract end date" type="date" required />
              <div className="sm:col-span-2">
                <Switch
                  checked={form.inactive_contract}
                  disabled={disabled}
                  onChange={(checked) => onChange('inactive_contract', checked)}
                  label="Inactive contract"
                  id="inactive_contract"
                  error={errors.inactive_contract}
                />
              </div>
            </div>
          </DetailSection>

          <DetailSection icon={<HandCoins className="h-4 w-4" />} title="Purpose & donor" last>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <Select {...text('purpose_of_subscription')} label="Purpose of subscription" placeholder="Select a purpose" options={options?.purpose_of_subscription ?? []} required />
              </div>
              <Input {...text('donor')} label="Donor (optional)" />
            </div>
          </DetailSection>
      </div>
    )
  }

  if (section === 'coverage') {
    return (
      <div className="space-y-4">
          <DetailSection icon={<Gauge className="h-4 w-4" />} title="Coverage & speed">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <Select {...text('school_area_coverage')} label="School level coverage" placeholder="Select coverage" options={options?.school_level_coverage ?? []} required />
              </div>
              <Input {...text('min_speed')} label="Minimum speed (Mbps)" type="number" step="0.01" min="0" inputMode="decimal" required />
              <Input {...text('max_speed')} label="Maximum speed (Mbps)" type="number" step="0.01" min="0" inputMode="decimal" required />
            </div>
          </DetailSection>

          <DetailSection icon={<Router className="h-4 w-4" />} title="Access points & area coverage" last>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Input {...text('number_access_points_linked')} label="Access points linked" type="number" min="0" inputMode="numeric" required />
              <Textarea {...text('locations_access_points')} label="Access point locations (optional)" rows={2} />
              <Input {...text('number_admin_area_covered')} label="Admin areas covered" type="number" min="0" inputMode="numeric" required />
              <Select {...text('rate_connectivity_admin_areas')} label="Admin area connectivity rating (optional)" placeholder="None" options={options?.signal_quality ?? []} />
              <Input {...text('number_classrooms_covered')} label="Classrooms covered" type="number" min="0" inputMode="numeric" required />
              <Select {...text('rate_connectivity_classroom')} label="Classroom connectivity rating" placeholder="Select a rating" options={options?.signal_quality ?? []} required />
            </div>
          </DetailSection>
      </div>
    )
  }

  if (section === 'acquisition') {
    return (
      <div className="space-y-4">
          <DetailSection icon={<HandCoins className="h-4 w-4" />} title="Acquisition">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Select {...text('mode_of_acquisition')} label="Mode of acquisition" placeholder="Select a mode" options={options?.mode_of_acquisition ?? []} required />
              <Select {...text('source_of_acquisition')} label="Source of acquisition" placeholder="Select a source" options={options?.source_of_acquisition ?? []} required />
            </div>
          </DetailSection>

          <DetailSection icon={<Signal className="h-4 w-4" />} title="Overall quality" last>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Select {...text('overall_signal_quality')} label="Overall signal quality" placeholder="Select a signal quality" options={options?.signal_quality ?? []} required />
              <Input {...text('rate_isp_service')} label="ISP service rating (1–5)" type="number" min="1" max="5" inputMode="numeric" required />
            </div>
          </DetailSection>
      </div>
    )
  }

  return null
}
