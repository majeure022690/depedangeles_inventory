import { FileText, Gauge, HandCoins, Receipt, Router, Signal } from 'lucide-react'

import { DetailSection } from '@/components/ui/DetailSection'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Switch } from '@/components/ui/Switch'
import { Textarea } from '@/components/ui/Textarea'
import type { FieldErrors } from '@/lib/errors'
import type { IspAccountFormData, IspAccountOptions } from '@/types/isp-account'

interface IspAccountFieldsProps {
  form: IspAccountFormData
  onChange: <K extends keyof IspAccountFormData>(field: K, value: IspAccountFormData[K]) => void
  options: IspAccountOptions['form'] | null
  errors: FieldErrors
  disabled?: boolean
}

/** The ISP account form body (shared by create and edit), as `DetailSection`s for a `Card`. */
export function IspAccountFields({ form, onChange, options, errors, disabled = false }: IspAccountFieldsProps) {
  const text = (field: keyof IspAccountFormData) => ({
    id: field,
    name: field,
    value: form[field] as string,
    error: errors[field],
    disabled,
    onChange: (e: { target: { value: string } }) => onChange(field, e.target.value as never),
  })

  return (
    <>
      <DetailSection icon={<Receipt className="h-4 w-4" />} title="Provider & billing">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Select {...text('isp_provider_id')} label="ISP" placeholder="Select an ISP" options={options?.isp_provider ?? []} />
          <Input {...text('isp_billing_account_no')} label="Billing account no." required />
          <Input {...text('cost_per_month')} label="Cost per month" type="number" step="0.01" min="0" inputMode="decimal" required />
          <div className="sm:col-span-2">
            <Textarea {...text('package_purchased_inclusion')} label="Package inclusions" required />
          </div>
        </div>
      </DetailSection>

      <DetailSection icon={<Gauge className="h-4 w-4" />} title="Coverage & speed">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <Select {...text('school_area_coverage')} label="School level coverage" placeholder="Select coverage" options={options?.school_level_coverage ?? []} />
          </div>
          <Input {...text('min_speed')} label="Minimum speed (Mbps) (optional)" type="number" step="0.01" min="0" inputMode="decimal" />
          <Input {...text('max_speed')} label="Maximum speed (Mbps) (optional)" type="number" step="0.01" min="0" inputMode="decimal" />
        </div>
      </DetailSection>

      <DetailSection icon={<FileText className="h-4 w-4" />} title="Subscription & contract">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Select {...text('subscription_type')} label="Subscription type" placeholder="Select a type" options={options?.subscription_type ?? []} />
          <Select {...text('isp_connection_type')} label="Connection type" placeholder="Select a connection type" options={options?.isp_connection_type ?? []} />
          <Input {...text('contract_start_date')} label="Contract start date (optional)" type="date" />
          <Input {...text('contract_end_date')} label="Contract end date (optional)" type="date" />
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

      <DetailSection icon={<HandCoins className="h-4 w-4" />} title="Purpose & acquisition">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <Select {...text('purpose_of_subscription')} label="Purpose of subscription" placeholder="Select a purpose" options={options?.purpose_of_subscription ?? []} />
          </div>
          <Select {...text('mode_of_acquisition')} label="Mode of acquisition" placeholder="Select a mode" options={options?.mode_of_acquisition ?? []} />
          <Select {...text('source_of_acquisition')} label="Source of acquisition" placeholder="Select a source" options={options?.source_of_acquisition ?? []} />
          <Input {...text('donor')} label="Donor (optional)" />
          <Select {...text('fund_source')} label="Source of funds" placeholder="Select a fund source" options={options?.source_of_funds ?? []} />
        </div>
      </DetailSection>

      <DetailSection icon={<Router className="h-4 w-4" />} title="Access points & area coverage">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input {...text('number_access_points_linked')} label="Access points linked (optional)" type="number" min="0" inputMode="numeric" />
          <Textarea {...text('locations_access_points')} label="Access point locations (optional)" rows={2} />
          <Input {...text('number_admin_area_covered')} label="Admin areas covered (optional)" type="number" min="0" inputMode="numeric" />
          <Select {...text('rate_connectivity_admin_areas')} label="Admin area connectivity rating (optional)" placeholder="None" options={options?.signal_quality ?? []} />
          <Input {...text('number_classrooms_covered')} label="Classrooms covered (optional)" type="number" min="0" inputMode="numeric" />
          <Select {...text('rate_connectivity_classroom')} label="Classroom connectivity rating (optional)" placeholder="None" options={options?.signal_quality ?? []} />
        </div>
      </DetailSection>

      <DetailSection icon={<Signal className="h-4 w-4" />} title="Overall quality" last>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Select {...text('overall_signal_quality')} label="Overall signal quality" placeholder="Select a signal quality" options={options?.signal_quality ?? []} />
          <Input {...text('rate_isp_service')} label="ISP service rating (1–5) (optional)" type="number" min="1" max="5" inputMode="numeric" />
        </div>
      </DetailSection>
    </>
  )
}
