import { CheckboxGroupField } from '@/components/forms/CheckboxGroupField'
import { CheckboxField, FormField } from '@/components/forms/FormField'
import { FormSection } from '@/components/forms/FormSection'
import { Input } from '@/components/ui/Input'
import { Select, type SelectOption } from '@/components/ui/Select'
import { useAuth } from '@/context/AuthContext'
import { hasAllScope, SCOPE_LOCK_HINT } from '@/lib/dataScope'
import type { FieldErrors } from '@/lib/errors'
import { withCurrentOption } from '@/lib/options'
import type { Personnel, PersonnelFormData, PersonnelOptions } from '@/types/personnel'

export function emptyPersonnelForm(): PersonnelFormData {
  return {
    employee_id: '',
    last_name: '',
    first_name: '',
    middle_name: '',
    suffix: '',
    ro_office_id: null,
    sdo_office_id: null,
    office_id: null,
    position_id: null,
    oic: false,
    oic_office: '',
    mobile_1: '',
    mobile_2: '',
    deped_email: '',
    personal_email: '',
    date_hired: '',
    non_deped_funded: false,
    fund_source: [],
    inactive: false,
    separation_date: '',
    separation_cause: '',
    transferred_from: '',
    transferred_to: '',
  }
}

export function personnelToForm(personnel: Personnel): PersonnelFormData {
  return {
    employee_id: personnel.employee_id,
    last_name: personnel.last_name,
    first_name: personnel.first_name,
    middle_name: personnel.middle_name ?? '',
    suffix: personnel.suffix ?? '',
    ro_office_id: personnel.ro_office_id ?? null,
    sdo_office_id: personnel.sdo_office_id ?? null,
    office_id: personnel.office_id ?? null,
    position_id: personnel.position_id ?? null,
    oic: personnel.oic,
    oic_office: personnel.oic_office ?? '',
    mobile_1: personnel.mobile_1 ?? '',
    mobile_2: personnel.mobile_2 ?? '',
    deped_email: personnel.deped_email ?? '',
    personal_email: personnel.personal_email ?? '',
    date_hired: personnel.date_hired ?? '',
    non_deped_funded: personnel.non_deped_funded,
    fund_source: personnel.fund_source ?? [],
    inactive: personnel.inactive,
    separation_date: personnel.separation_date ?? '',
    separation_cause: personnel.separation_cause ?? '',
    transferred_from: personnel.transferred_from ?? '',
    transferred_to: personnel.transferred_to ?? '',
  }
}

type TextKey = { [K in keyof PersonnelFormData]: PersonnelFormData[K] extends string ? K : never }[keyof PersonnelFormData]
type IdKey = { [K in keyof PersonnelFormData]: PersonnelFormData[K] extends number | null ? K : never }[keyof PersonnelFormData]

interface PersonnelFieldsProps {
  value: PersonnelFormData
  onChange: (next: PersonnelFormData) => void
  options: PersonnelOptions | null
  errors: FieldErrors
  disabled?: boolean
  /** The saved record on edit, for display names of values that are no longer active options. */
  current?: Personnel | null
}

/** Port of the old `personnel/Partials/Form.vue`; shared by the create and edit pages. */
export function PersonnelFields({ value, onChange, options, errors, disabled = false, current }: PersonnelFieldsProps) {
  const { user, can } = useAuth()
  const scopeLocked = !hasAllScope(user)

  const officeList =
    scopeLocked && value.office_id !== null && value.office_id === user?.office_id && current?.office_id !== value.office_id
      ? withCurrentOption(options?.office, value.office_id, user?.office?.office_name)
      : options?.office

  const linkedUser = can('users.view') ? current?.user : null

  function set<K extends keyof PersonnelFormData>(key: K, next: PersonnelFormData[K]) {
    onChange({ ...value, [key]: next })
  }

  function text(
    key: TextKey,
    label: string,
    props: { type?: string; autoComplete?: string; required?: boolean; className?: string } = {},
  ) {
    const { className, ...inputProps } = props
    return (
      <FormField id={key} label={label} error={errors[key]} className={className}>
        {(aria) => (
          <Input
            id={key}
            name={key}
            value={value[key]}
            disabled={disabled}
            onChange={(e) => set(key, e.target.value)}
            {...inputProps}
            {...aria}
          />
        )}
      </FormField>
    )
  }

  function stringSelect(key: TextKey, label: string, list: SelectOption[] | undefined) {
    return (
      <FormField id={key} label={label} error={errors[key]}>
        {(aria) => (
          <Select
            id={key}
            name={key}
            value={value[key]}
            disabled={disabled || !options}
            options={withCurrentOption(list, value[key])}
            placeholder="None"
            onChange={(e) => set(key, e.target.value)}
            {...aria}
          />
        )}
      </FormField>
    )
  }

  function idSelect(
    key: IdKey,
    label: string,
    list: SelectOption[] | undefined,
    currentLabel: string | null | undefined,
    locked = false,
  ) {
    const selected = value[key]
    return (
      <FormField id={key} label={label} error={errors[key]} note={locked && !disabled ? SCOPE_LOCK_HINT : undefined}>
        {(aria) => (
          <Select
            id={key}
            name={key}
            value={selected ?? ''}
            disabled={disabled || locked || !options}
            options={withCurrentOption(list, selected, current?.[key] === selected ? currentLabel : null)}
            placeholder="None"
            onChange={(e) => set(key, e.target.value === '' ? null : Number(e.target.value))}
            {...aria}
          />
        )}
      </FormField>
    )
  }

  return (
    <div className="grid items-start gap-4 lg:grid-cols-2">
      <FormSection title="Identification">
        {text('employee_id', 'Employee ID', { required: true, autoComplete: 'off' })}
        {stringSelect('suffix', 'Suffix (optional)', options?.name_suffix)}
        {text('last_name', 'Last name', { required: true })}
        {text('first_name', 'First name', { required: true })}
        {text('middle_name', 'Middle name (optional)')}
      </FormSection>

      <FormSection title="Assignment">
        {idSelect('position_id', 'Position (optional)', options?.position, current?.position)}
        {idSelect('ro_office_id', 'RO division (optional)', options?.ro_office, current?.ro_division)}
        {idSelect('sdo_office_id', 'Division/unit (optional)', options?.sdo_office, current?.division_unit)}
        {idSelect('office_id', 'School/office (optional)', officeList, current?.office, scopeLocked)}
        {linkedUser && (
          <div className="min-w-0">
            <p className="mb-1 text-sm font-medium text-gray-700">Linked user account</p>
            <p className="text-sm text-gray-900">{linkedUser.name}</p>
            <p className="mt-1 text-xs text-gray-500">Linked from the Users page.</p>
          </div>
        )}
        <CheckboxField id="oic" label="Officer-in-charge (OIC)" checked={value.oic} disabled={disabled} error={errors.oic} onChange={(checked) => set('oic', checked)} />
        {value.oic && text('oic_office', 'OIC office (optional)', { className: 'sm:col-span-2' })}
      </FormSection>

      <FormSection title="Contact information">
        {text('mobile_1', 'Mobile number 1 (optional)', { type: 'tel', autoComplete: 'tel' })}
        {text('mobile_2', 'Mobile number 2 (optional)', { type: 'tel', autoComplete: 'tel' })}
        {text('deped_email', 'DepEd email (optional)', { type: 'email', autoComplete: 'email' })}
        {text('personal_email', 'Personal email (optional)', { type: 'email', autoComplete: 'email' })}
      </FormSection>

      <FormSection title="Employment">
        {text('date_hired', 'Date hired (optional)', { type: 'date' })}
        <CheckboxField
          id="non_deped_funded"
          label="Non-DepEd funded"
          checked={value.non_deped_funded}
          disabled={disabled}
          error={errors.non_deped_funded}
          onChange={(checked) => set('non_deped_funded', checked)}
        />
        {value.non_deped_funded && (
          <CheckboxGroupField<number>
            id="fund_source"
            label="Funding source (optional)"
            options={options?.teachers_funding_source ?? []}
            value={value.fund_source}
            onChange={(next) => set('fund_source', next)}
            disabled={disabled}
            error={errors.fund_source}
            className="sm:col-span-2"
          />
        )}
      </FormSection>

      <FormSection title="Status" className="lg:col-span-2">
        <CheckboxField
          id="inactive"
          label="Inactive / separated"
          checked={value.inactive}
          disabled={disabled}
          error={errors.inactive}
          onChange={(checked) => set('inactive', checked)}
        />
        <div aria-hidden="true" className="hidden sm:block" />
        {value.inactive && (
          <>
            {text('separation_date', 'Separation date (optional)', { type: 'date' })}
            {stringSelect('separation_cause', 'Cause of separation (optional)', options?.cause_of_separation)}
            {text('transferred_from', 'Transferred from (optional)')}
            {text('transferred_to', 'Transferred to (optional)')}
          </>
        )}
      </FormSection>
    </div>
  )
}
