import { CreditCard, Globe, Smartphone, Wifi, Zap } from 'lucide-react'

import { CheckboxGroupField } from '@/components/forms/CheckboxGroupField'
import { DetailSection } from '@/components/ui/DetailSection'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Switch } from '@/components/ui/Switch'
import { Textarea } from '@/components/ui/Textarea'
import type { FieldErrors } from '@/lib/errors'
import type { ConnectivitySurveyFormData, ConnectivitySurveyOptions } from '@/types/internet-connectivity-survey'
import type { Option } from '@/types/lookup'

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
    <Switch id={field} checked={form[field]} disabled={disabled} onChange={(checked) => onChange(field, checked)} label={label} error={errors[field]} />
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
            <Input {...text('available_isps_other')} label="Other available ISP" />
          </div>
        </div>
      </DetailSection>

      <DetailSection icon={<Smartphone className="h-4 w-4" />} title="Mobile connectivity">
        <div className="space-y-4">
          {group('mobile_signal_types', 'Mobile signal types', options?.mobile_network_signal)}
          {flag('has_mobile_data_connectivity', 'Has mobile data connectivity')}
          <div className="sm:max-w-sm">
            <Select {...text('mobile_data_quality')} label="Mobile data quality" placeholder="None" options={options?.signal_quality ?? []} />
          </div>
        </div>
      </DetailSection>

      <DetailSection icon={<CreditCard className="h-4 w-4" />} title="Subscription details">
        <div className="space-y-4">
          {flag('subscribes_to_isp', 'Currently subscribes to an ISP')}
          {group('subscribed_isps', 'Subscribed ISPs', options?.isp_provider)}
          <Textarea {...text('no_subscription_reason')} label="Reason for no subscription" />
          {flag('dict_free_wifi_recipient', 'DICT Free Wi-Fi recipient')}
          <div className="sm:max-w-sm">
            <Input {...text('dict_free_wifi_rating')} label="DICT Free Wi-Fi rating (1–5)" type="number" min="1" max="5" inputMode="numeric" />
          </div>
        </div>
      </DetailSection>

      <DetailSection icon={<Wifi className="h-4 w-4" />} title="Coverage & bandwidth">
        <div className="space-y-4">
          {group('coverage_areas', 'Coverage areas', options?.coverage_area)}
          <div className="sm:max-w-sm">
            <Input {...text('coverage_areas_other')} label="Other coverage area" />
          </div>
          {flag('has_sufficient_bandwidth', 'Has sufficient bandwidth')}
          <Textarea {...text('insufficient_bandwidth_explanation')} label="Insufficient bandwidth explanation" />
          <div className="sm:max-w-sm">
            <Input {...text('rooms_other_use')} label="Rooms for other use" type="number" min="0" inputMode="numeric" />
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
