import { FormSection } from '@/components/forms/FormSection'
import { Card } from '@/components/ui/Card'
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

type TabId = 'provider' | 'billing' | 'coverage'

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
        <p className="text-sm text-gray-600">Fields marked <span aria-hidden="true" className="text-error-600">*</span> are required.</p>
        <Card>
          <FormSection title="ISP provider">
            <Select {...text('isp_provider_id')} label="Internet Service Provider" placeholder="Select an ISP" options={options?.isp_provider ?? []} required />
            <Select {...text('fund_source')} label="Source of funds" placeholder="Select a source" options={options?.source_of_funds ?? []} required />
            <Input {...text('cost_per_month')} label="Cost per month" type="number" step="0.01" min="0" inputMode="decimal" required />
            <Select {...text('subscription_type')} label="Subscription type" placeholder="Select a type" options={options?.subscription_type ?? []} required />
            <Select {...text('isp_connection_type')} label="ISP connection type" placeholder="Select a type" options={options?.isp_connection_type ?? []} required />
            <Input {...text('min_speed')} label="Min. speed" type="number" step="0.01" min="0" inputMode="decimal" required />
            <Input {...text('max_speed')} label="Max. speed" type="number" step="0.01" min="0" inputMode="decimal" required />
            <Select
              {...text('office_id')}
              label="School/office (optional)"
              placeholder="None"
              options={withCurrentOption(options?.office, officeId, officeLabel)}
              disabled={disabled || scopeLocked || !options}
              hint={scopeLocked && !disabled ? SCOPE_LOCK_HINT : undefined}
            />
          </FormSection>
        </Card>
      </div>
    )
  }

  if (section === 'billing') {
    // Donor is required when source is one of these specific values
    const donorRequiredSources = [
      'Local Government Unit(LGU)',
      'Private Corporation',
      'Non-Governmental Organization(NGO)',
      'LGU',
      'Other National Government Agency',
      "Parent Teacher's Association"
    ]
    const showDonor = donorRequiredSources.includes(form.source_of_acquisition)

    return (
      <div className="space-y-4">
        <p className="text-sm text-gray-600">Fields marked <span aria-hidden="true" className="text-error-600">*</span> are required.</p>
        <Card>
          <FormSection title="Billing and contract">
            <Input {...text('isp_billing_account_no')} label="ISP billing account number" required />
            <Input {...text('contract_start_date')} label="ISP contract start" type="date" required />
            <Input {...text('contract_end_date')} label="ISP contract end" type="date" required />
            <Input {...text('subscriber_speed_upload')} label="Subscriber speed (Upload)" type="number" step="0.01" min="0" inputMode="decimal" placeholder="Mbps" />
            <Input {...text('subscriber_speed_download')} label="Subscriber speed (Download)" type="number" step="0.01" min="0" inputMode="decimal" placeholder="Mbps" />
            <Select {...text('mode_of_acquisition')} label="Mode of acquisition" placeholder="Select a mode" options={options?.mode_of_acquisition ?? []} required />
            <Select {...text('source_of_acquisition')} label="Source of acquisition" placeholder="Select a source" options={options?.source_of_acquisition ?? []} required />
            {showDonor && (
              <Input {...text('donor')} label="Donor" required />
            )}
            <div className="sm:col-span-2">
              <Textarea {...text('package_purchased_inclusion')} label="Package purchased/inclusion" required />
            </div>
            <div className="sm:col-span-2">
              <Switch
                checked={form.inactive_contract}
                disabled={disabled}
                onChange={(checked) => onChange('inactive_contract', checked)}
                label="Inactive / End-of-Contract"
                id="inactive_contract"
                error={errors.inactive_contract}
              />
            </div>
          </FormSection>
        </Card>
      </div>
    )
  }

  if (section === 'coverage') {
    return (
      <div className="space-y-4">
        <p className="text-sm text-gray-600">Fields marked <span aria-hidden="true" className="text-error-600">*</span> are required.</p>
        <Card>
          <FormSection title="Coverage and speed">
            <div className="sm:col-span-2">
              <Select {...text('purpose_of_subscription')} label="Purpose of subscription" placeholder="Select a purpose" options={options?.purpose_of_subscription ?? []} required />
            </div>
            <div className="sm:col-span-2">
              <Select {...text('school_area_coverage')} label="School level/area of ISP coverage (Usage)" placeholder="Select coverage" options={options?.school_level_coverage ?? []} required />
            </div>
            <Input {...text('number_access_points_linked')} label="Number of access points linked" type="number" min="0" inputMode="numeric" required />
            <Textarea {...text('locations_access_points')} label="Locations of access points (optional)" rows={2} />
            <Input {...text('number_admin_area_covered')} label="Number of admin area rooms covered by ISP" type="number" min="0" inputMode="numeric" required />
            <Select {...text('rate_connectivity_admin_areas')} label="Rate connectivity in admin areas (number of admin areas > 1) (optional)" placeholder="None" options={options?.signal_quality ?? []} />
            <Input {...text('number_classrooms_covered')} label="Number of classrooms covered by ISP" type="number" min="0" inputMode="numeric" required />
            <Select {...text('rate_connectivity_classroom')} label="Rate connectivity in classroom areas (if number of classroom areas > 1)" placeholder="Select a rating" options={options?.signal_quality ?? []} required />
            <Select {...text('overall_signal_quality')} label="Overall signal quality/reliability" placeholder="Select a signal quality" options={options?.signal_quality ?? []} required />
            <Input {...text('rate_isp_service')} label="Rate ISP service" type="number" min="1" max="5" inputMode="numeric" required />
          </FormSection>
        </Card>
      </div>
    )
  }

  return null
}
