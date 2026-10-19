import { isAxiosError } from 'axios'
import { CreditCard, Globe, Smartphone, Wifi, Zap } from 'lucide-react'
import type { ComponentProps } from 'react'

import { CheckboxGroupField } from '@/components/forms/CheckboxGroupField'
import { DetailSection } from '@/components/ui/DetailSection'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Switch } from '@/components/ui/Switch'
import { Textarea } from '@/components/ui/Textarea'
import { cn } from '@/lib/utils'
import type { ApiValidationError } from '@/types/api'
import type { ConnectivitySurveyFormData, ConnectivitySurveyOptions } from '@/types/internet-connectivity-survey'
import type { Option } from '@/types/lookup'

export type FieldErrors = Partial<Record<string, string>>

/** First 422 message per field (`field.0` element errors fold into `field`), or null for any other failure. */
export function fieldErrorsFrom(error: unknown): FieldErrors | null {
  if (!isAxiosError<ApiValidationError>(error) || error.response?.status !== 422 || !error.response.data?.errors) return null
  const errors: FieldErrors = {}
  for (const [key, messages] of Object.entries(error.response.data.errors)) {
    const field = key.split('.')[0]
    if (!errors[field] && messages[0]) errors[field] = messages[0]
  }
  return errors
}

const INVALID = 'border-error-400 focus:border-error-500 focus:ring-error-500/20'

function FieldError({ id, message }: { id: string; message?: string }) {
  if (!message) return null
  return (
    <p id={`${id}-error`} className="mt-1 text-xs text-error-600">
      {message}
    </p>
  )
}

function described(id: string, error?: string) {
  return { 'aria-invalid': error ? true : undefined, 'aria-describedby': error ? `${id}-error` : undefined }
}

type Wrapped<P> = Omit<P, 'id' | 'label' | 'error'> & { id: string; label: string; error?: string }

function FormInput({ id, label, error, className, ...props }: Wrapped<ComponentProps<typeof Input>>) {
  return (
    <div>
      <Input id={id} label={label} className={cn(error && INVALID, className)} {...described(id, error)} {...props} />
      <FieldError id={id} message={error} />
    </div>
  )
}

function FormSelect({ id, label, error, className, ...props }: Wrapped<ComponentProps<typeof Select>>) {
  return (
    <div>
      <Select id={id} label={label} className={cn(error && INVALID, className)} {...described(id, error)} {...props} />
      <FieldError id={id} message={error} />
    </div>
  )
}

function FormTextarea({ id, label, error, className, ...props }: Wrapped<ComponentProps<typeof Textarea>>) {
  return (
    <div>
      <Textarea id={id} label={label} className={cn(error && INVALID, className)} {...described(id, error)} {...props} />
      <FieldError id={id} message={error} />
    </div>
  )
}

type BooleanField = {
  [K in keyof ConnectivitySurveyFormData]: ConnectivitySurveyFormData[K] extends boolean ? K : never
}[keyof ConnectivitySurveyFormData]

type IdListField = {
  [K in keyof ConnectivitySurveyFormData]: ConnectivitySurveyFormData[K] extends number[] ? K : never
}[keyof ConnectivitySurveyFormData]

interface ConnectivitySurveyFieldsProps {
  form: ConnectivitySurveyFormData
  onChange: <K extends keyof ConnectivitySurveyFormData>(field: K, value: ConnectivitySurveyFormData[K]) => void
  options: ConnectivitySurveyOptions | null
  errors: FieldErrors
  disabled?: boolean
}

/** The Internet Connectivity Survey form body, as `DetailSection`s for a `Card`. */
export function ConnectivitySurveyFields({ form, onChange, options, errors, disabled = false }: ConnectivitySurveyFieldsProps) {
  const text = (field: keyof ConnectivitySurveyFormData) => ({
    id: field,
    name: field,
    value: form[field] as string,
    error: errors[field],
    disabled,
    onChange: (e: { target: { value: string } }) => onChange(field, e.target.value as never),
  })

  const flag = (field: BooleanField, label: string) => (
    <div>
      <Switch checked={form[field]} disabled={disabled} onChange={(checked) => onChange(field, checked)} label={label} />
      <FieldError id={field} message={errors[field]} />
    </div>
  )

  const group = (field: IdListField, label: string, groupOptions: Option<number>[] | undefined) => (
    <CheckboxGroupField
      id={field}
      label={label}
      options={groupOptions ?? []}
      value={form[field]}
      onChange={(value) => onChange(field, value)}
      error={errors[field]}
      disabled={disabled}
    />
  )

  return (
    <>
      <DetailSection icon={<Globe className="h-4 w-4" />} title="ISP availability">
        <div className="space-y-4">
          {flag('has_isp_in_area', 'An ISP is available in the area')}
          {group('available_isps', 'Available ISPs', options?.isp_provider)}
          <div className="sm:max-w-sm">
            <FormInput {...text('available_isps_other')} label="Other available ISP" />
          </div>
        </div>
      </DetailSection>

      <DetailSection icon={<Smartphone className="h-4 w-4" />} title="Mobile connectivity">
        <div className="space-y-4">
          {group('mobile_signal_types', 'Mobile signal types', options?.mobile_network_signal)}
          {flag('has_mobile_data_connectivity', 'Has mobile data connectivity')}
          <div className="sm:max-w-sm">
            <FormSelect {...text('mobile_data_quality')} label="Mobile data quality" placeholder="None" options={options?.signal_quality ?? []} />
          </div>
        </div>
      </DetailSection>

      <DetailSection icon={<CreditCard className="h-4 w-4" />} title="Subscription details">
        <div className="space-y-4">
          {flag('subscribes_to_isp', 'Currently subscribes to an ISP')}
          {group('subscribed_isps', 'Subscribed ISPs', options?.isp_provider)}
          <FormTextarea {...text('no_subscription_reason')} label="Reason for no subscription" />
          {flag('dict_free_wifi_recipient', 'DICT Free Wi-Fi recipient')}
          <div className="sm:max-w-sm">
            <FormInput {...text('dict_free_wifi_rating')} label="DICT Free Wi-Fi rating (1–5)" type="number" min="1" max="5" inputMode="numeric" />
          </div>
        </div>
      </DetailSection>

      <DetailSection icon={<Wifi className="h-4 w-4" />} title="Coverage & bandwidth">
        <div className="space-y-4">
          {group('coverage_areas', 'Coverage areas', options?.coverage_area)}
          <div className="sm:max-w-sm">
            <FormInput {...text('coverage_areas_other')} label="Other coverage area" />
          </div>
          {flag('has_sufficient_bandwidth', 'Has sufficient bandwidth')}
          <FormTextarea {...text('insufficient_bandwidth_explanation')} label="Insufficient bandwidth explanation" />
          <div className="sm:max-w-sm">
            <FormInput {...text('rooms_other_use')} label="Rooms for other use" type="number" min="0" inputMode="numeric" />
          </div>
        </div>
      </DetailSection>

      <DetailSection icon={<Zap className="h-4 w-4" />} title="Electricity" last>
        <div className="space-y-4">
          {flag('has_electricity_source', 'Has an electricity source')}
          {group('electricity_sources', 'Electricity sources', options?.source_of_electricity)}
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            {flag('primarily_solar_powered', 'Primarily solar-powered')}
            {flag('frequent_brownouts', 'Frequent brownouts')}
          </div>
        </div>
      </DetailSection>
    </>
  )
}
