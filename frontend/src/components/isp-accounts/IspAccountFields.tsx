import { FormField } from '@/components/forms/FormField'
import { FormSection } from '@/components/forms/FormSection'
import { Input } from '@/components/ui/Input'
import { Select, type SelectOption } from '@/components/ui/Select'
import { Switch } from '@/components/ui/Switch'
import { Textarea } from '@/components/ui/Textarea'
import { useAuth } from '@/context/AuthContext'
import { hasAllScope, SCOPE_LOCK_HINT } from '@/lib/dataScope'
import type { FieldErrors } from '@/lib/errors'
import { withCurrentOption } from '@/lib/options'
import type { IspAccount, IspAccountFormData, IspAccountOptions } from '@/types/isp-account'

export type IspAccountFieldSection = 'provider' | 'billing' | 'coverage'

export const ISP_ACCOUNT_FIELD_SECTIONS: { value: IspAccountFieldSection; label: string; fields: (keyof IspAccountFormData)[] }[] = [
  {
    value: 'provider',
    label: 'ISP Provider',
    fields: ['isp_provider_id', 'fund_source', 'cost_per_month', 'subscription_type', 'isp_connection_type', 'min_speed', 'max_speed', 'office_id'],
  },
  {
    value: 'billing',
    label: 'Billing and Contract',
    fields: [
      'isp_billing_account_no',
      'package_purchased_inclusion',
      'contract_start_date',
      'contract_end_date',
      'subscriber_speed_upload',
      'subscriber_speed_download',
      'mode_of_acquisition',
      'source_of_acquisition',
      'donor',
      'inactive_contract',
    ],
  },
  {
    value: 'coverage',
    label: 'Coverage and Quality',
    fields: [
      'purpose_of_subscription',
      'school_area_coverage',
      'number_access_points_linked',
      'locations_access_points',
      'number_admin_area_covered',
      'rate_connectivity_admin_areas',
      'number_classrooms_covered',
      'rate_connectivity_classroom',
      'overall_signal_quality',
      'rate_isp_service',
    ],
  },
]

export function isIspAccountFieldSection(value: string | null): value is IspAccountFieldSection {
  return ISP_ACCOUNT_FIELD_SECTIONS.some((section) => section.value === value)
}

export function sectionErrorCount(section: IspAccountFieldSection, errors: FieldErrors): number {
  return ISP_ACCOUNT_FIELD_SECTIONS.find((s) => s.value === section)?.fields.filter((field) => errors[field]).length ?? 0
}

export function firstSectionWithError(errors: FieldErrors): IspAccountFieldSection | null {
  return ISP_ACCOUNT_FIELD_SECTIONS.find((section) => section.fields.some((field) => errors[field]))?.value ?? null
}

export function sectionIsDirty(section: IspAccountFieldSection, form: IspAccountFormData, saved: IspAccountFormData): boolean {
  return ISP_ACCOUNT_FIELD_SECTIONS.find((s) => s.value === section)?.fields.some((field) => form[field] !== saved[field]) ?? false
}

type TextKey = Exclude<keyof IspAccountFormData, 'inactive_contract'>

interface InputOptions {
  type?: string
  step?: string
  min?: string
  max?: string
  inputMode?: 'numeric' | 'decimal'
  required?: boolean
  className?: string
}

interface IspAccountFieldsProps {
  section: IspAccountFieldSection
  form: IspAccountFormData
  onChange: <K extends keyof IspAccountFormData>(field: K, value: IspAccountFormData[K]) => void
  options: IspAccountOptions['form'] | null
  errors: FieldErrors
  disabled?: boolean
  current?: IspAccount | null
}

const DECIMAL: InputOptions = { type: 'number', step: '0.01', min: '0', inputMode: 'decimal' }
const COUNT: InputOptions = { type: 'number', min: '0', inputMode: 'numeric' }

/** One tab's worth of ISP account fields; shared by the create and edit pages. */
export function IspAccountFields({ section, form, onChange, options, errors, disabled = false, current }: IspAccountFieldsProps) {
  const { user } = useAuth()
  const scopeLocked = !hasAllScope(user)
  const officeId = form.office_id === '' ? null : Number(form.office_id)
  const officeLabel =
    current?.office_id === officeId ? current?.office?.office_name : officeId === user?.office_id ? user?.office?.office_name : null

  function input(key: TextKey, label: string, { required = false, className, ...props }: InputOptions = {}) {
    return (
      <FormField id={key} label={label} error={errors[key]} required={required} className={className}>
        {(aria) => <Input id={key} name={key} value={form[key]} disabled={disabled} onChange={(e) => onChange(key, e.target.value)} {...props} {...aria} />}
      </FormField>
    )
  }

  function textarea(key: TextKey, label: string, required = false, rows?: number) {
    return (
      <FormField id={key} label={label} error={errors[key]} required={required} className="sm:col-span-2">
        {(aria) => <Textarea id={key} name={key} value={form[key]} rows={rows} disabled={disabled} onChange={(e) => onChange(key, e.target.value)} {...aria} />}
      </FormField>
    )
  }

  function select(key: TextKey, label: string, list: SelectOption[], placeholder: string, required = false, className?: string) {
    return (
      <FormField id={key} label={label} error={errors[key]} required={required} className={className}>
        {(aria) => (
          <Select
            id={key}
            name={key}
            value={form[key]}
            options={list}
            placeholder={placeholder}
            disabled={disabled || !options}
            onChange={(e) => onChange(key, e.target.value)}
            {...aria}
          />
        )}
      </FormField>
    )
  }

  if (section === 'provider') {
    return (
      <FormSection title="ISP provider">
        {select('isp_provider_id', 'Internet Service Provider', options?.isp_provider ?? [], 'Select an ISP', true)}
        {select('fund_source', 'Source of funds', options?.source_of_funds ?? [], 'Select a fund source', true)}
        {input('cost_per_month', 'Cost per month', { ...DECIMAL, required: true })}
        {select('subscription_type', 'Subscription type', options?.subscription_type ?? [], 'Select a type', true)}
        {select('isp_connection_type', 'ISP connection type', options?.isp_connection_type ?? [], 'Select a connection type', true)}
        {input('min_speed', 'Min. speed (Mbps) (optional)', DECIMAL)}
        {input('max_speed', 'Max. speed (Mbps) (optional)', DECIMAL)}
        <FormField id="office_id" label="School/office (optional)" note={scopeLocked && !disabled ? SCOPE_LOCK_HINT : undefined} error={errors.office_id}>
          {(aria) => (
            <Select
              id="office_id"
              name="office_id"
              value={form.office_id}
              placeholder="None"
              options={withCurrentOption(options?.office, officeId, officeLabel)}
              disabled={disabled || scopeLocked || !options}
              onChange={(e) => onChange('office_id', e.target.value)}
              {...aria}
            />
          )}
        </FormField>
      </FormSection>
    )
  }

  if (section === 'billing') {
    return (
      <FormSection title="Billing and contract">
        {input('isp_billing_account_no', 'ISP billing account number', { required: true, className: 'sm:col-span-2' })}
        {textarea('package_purchased_inclusion', 'Package inclusions', true)}
        {input('contract_start_date', 'ISP contract start (optional)', { type: 'date' })}
        {input('contract_end_date', 'ISP contract end (optional)', { type: 'date' })}
        {input('subscriber_speed_upload', 'Subscriber upload speed (Mbps) (optional)', DECIMAL)}
        {input('subscriber_speed_download', 'Subscriber download speed (Mbps) (optional)', DECIMAL)}
        {select('mode_of_acquisition', 'Mode of acquisition', options?.mode_of_acquisition ?? [], 'Select a mode', true)}
        {select('source_of_acquisition', 'Source of acquisition', options?.source_of_acquisition ?? [], 'Select a source', true)}
        {input('donor', 'Donor (optional)', { className: 'sm:col-span-2' })}
        <div className="sm:col-span-2">
          <Switch
            checked={form.inactive_contract}
            disabled={disabled}
            onChange={(checked) => onChange('inactive_contract', checked)}
            label="Inactive / end of contract"
            id="inactive_contract"
            error={errors.inactive_contract}
          />
        </div>
      </FormSection>
    )
  }

  return (
    <FormSection title="Coverage and quality">
      {select('purpose_of_subscription', 'Purpose of subscription', options?.purpose_of_subscription ?? [], 'Select a purpose', true, 'sm:col-span-2')}
      {select('school_area_coverage', 'School level/area of ISP coverage (usage)', options?.school_level_coverage ?? [], 'Select coverage', true, 'sm:col-span-2')}
      {input('number_access_points_linked', 'Number of access points linked (optional)', COUNT)}
      {textarea('locations_access_points', 'Locations of access points (optional)', false, 2)}
      {input('number_admin_area_covered', 'Number of admin area rooms covered (optional)', COUNT)}
      {select('rate_connectivity_admin_areas', 'Admin area connectivity rating (optional)', options?.signal_quality ?? [], 'None')}
      {input('number_classrooms_covered', 'Number of classrooms covered (optional)', COUNT)}
      {select('rate_connectivity_classroom', 'Classroom connectivity rating (optional)', options?.signal_quality ?? [], 'None')}
      {select('overall_signal_quality', 'Overall signal quality/reliability', options?.signal_quality ?? [], 'Select a signal quality', true)}
      {input('rate_isp_service', 'ISP service rating (1–5) (optional)', { type: 'number', min: '1', max: '5', inputMode: 'numeric' })}
    </FormSection>
  )
}
