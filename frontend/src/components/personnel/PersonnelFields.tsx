import { CheckboxGroupField } from '@/components/forms/CheckboxGroupField'
import { CheckboxField, FormField } from '@/components/forms/FormField'
import { FormSection } from '@/components/forms/FormSection'
import { Input } from '@/components/ui/Input'
import { Select, type SelectOption } from '@/components/ui/Select'
import { useAuth } from '@/context/AuthContext'
import { hasAllScope, SCOPE_LOCK_HINT } from '@/lib/dataScope'
import type { FieldErrors } from '@/lib/errors'
import { withCurrentOption } from '@/lib/options'
import { formatDate } from '@/lib/utils'
import type { Personnel, PersonnelFormData, PersonnelOptions } from '@/types/personnel'

function ReadOnly({ label, value }: { label: string; value: string | null | undefined }) {
  return (
    <div>
      <p className="mb-1 text-sm font-medium text-gray-700">{label}</p>
      <p className="text-sm text-gray-900">{value || <span className="text-gray-400">—</span>}</p>
    </div>
  )
}

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
  const { user } = useAuth()
  const scopeLocked = !hasAllScope(user)
  const prime = current?.managed_by_prime === true
  const labelFor = (label: string, locked: boolean | 'prime') => (locked === 'prime' ? label.replace(/s*(optional)$/i, '') : label)
  const lockNote = (locked: boolean | 'prime') => (disabled || locked !== true ? undefined : SCOPE_LOCK_HINT)

  const officeList =
    scopeLocked && value.office_id !== null && value.office_id === user?.office_id && current?.office_id !== value.office_id
      ? withCurrentOption(options?.office, value.office_id, user?.office?.office_name)
      : options?.office

  function set<K extends keyof PersonnelFormData>(key: K, next: PersonnelFormData[K]) {
    onChange({ ...value, [key]: next })
  }

  function text(
    key: TextKey,
    label: string,
    props: { type?: string; autoComplete?: string; required?: boolean; className?: string; locked?: boolean | 'prime' } = {},
  ) {
    const { className, locked = false, ...inputProps } = props
    return (
      <FormField id={key} label={labelFor(label, locked)} error={errors[key]} className={className} note={lockNote(locked)}>
        {(aria) => (
          <Input
            id={key}
            name={key}
            value={value[key]}
            disabled={disabled || locked !== false}
            onChange={(e) => set(key, e.target.value)}
            {...inputProps}
            {...aria}
          />
        )}
      </FormField>
    )
  }

  function stringSelect(key: TextKey, label: string, list: SelectOption[] | undefined, locked: boolean | 'prime' = false) {
    return (
      <FormField id={key} label={labelFor(label, locked)} error={errors[key]} note={lockNote(locked)}>
        {(aria) => (
          <Select
            id={key}
            name={key}
            value={value[key]}
            disabled={disabled || locked !== false || !options}
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
    locked: boolean | 'prime' = false,
  ) {
    const selected = value[key]
    return (
      <FormField id={key} label={labelFor(label, locked)} error={errors[key]} note={lockNote(locked)}>
        {(aria) => (
          <Select
            id={key}
            name={key}
            value={selected ?? ''}
            disabled={disabled || locked !== false || !options}
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
        {text('employee_id', 'Employee ID', { required: true, autoComplete: 'off', locked: prime ? 'prime' : scopeLocked && !!current })}
        {stringSelect('suffix', 'Suffix (optional)', options?.name_suffix, prime && 'prime')}
        {text('last_name', 'Last name', { required: true, locked: prime && 'prime' })}
        {text('first_name', 'First name', { required: true, locked: prime && 'prime' })}
        {text('middle_name', 'Middle name (optional)', { locked: prime && 'prime' })}
      </FormSection>

      <FormSection title="Assignment">
        {idSelect('position_id', 'Position (optional)', options?.position, current?.position, prime && 'prime')}
        {!prime && idSelect('ro_office_id', 'RO division (optional)', options?.ro_office, current?.ro_division)}
        {!prime && idSelect('sdo_office_id', 'Division/unit (optional)', options?.sdo_office, current?.division_unit)}
        {idSelect('office_id', 'School/office (optional)', officeList, current?.office, prime ? 'prime' : scopeLocked)}
        <CheckboxField id="oic" label="Officer-in-charge (OIC)" checked={value.oic} disabled={disabled} error={errors.oic} onChange={(checked) => set('oic', checked)} />
        {value.oic && text('oic_office', 'OIC office (optional)', { className: 'sm:col-span-2' })}
      </FormSection>

      <FormSection title="Contact information">
        {text('mobile_1', 'Mobile number 1 (optional)', { type: 'tel', autoComplete: 'tel' })}
        {text('mobile_2', 'Mobile number 2 (optional)', { type: 'tel', autoComplete: 'tel' })}
        {text('deped_email', 'DepEd email (optional)', { type: 'email', autoComplete: 'email', locked: prime && 'prime' })}
        {text('personal_email', 'Personal email (optional)', { type: 'email', autoComplete: 'email' })}
      </FormSection>

      <FormSection title="Employment">
        {text('date_hired', 'Date hired (optional)', { type: 'date', locked: prime && 'prime' })}
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

      {prime && current && (
        <FormSection title="Employment details">
          <ReadOnly label="Appointment status" value={current.appointment_status} />
          <ReadOnly label="Plantilla item no." value={current.plantilla_item_no} />
          <ReadOnly label="Item level" value={current.plantilla_item_level} />
          <ReadOnly label="Actual workstation" value={current.workstation} />
          <ReadOnly label="Date of last promotion" value={formatDate(current.date_last_promotion)} />
          <ReadOnly label="Last synced" value={formatDate(current.prime_synced_at)} />
        </FormSection>
      )}

      <FormSection title="Status" className="lg:col-span-2">
        <CheckboxField
          id="inactive"
          label="Inactive / separated"
          checked={value.inactive}
          disabled={disabled || prime}
          error={errors.inactive}
          onChange={(checked) => set('inactive', checked)}
        />
        <div aria-hidden="true" className="hidden sm:block" />
        {value.inactive && (
          <>
            {text('separation_date', 'Separation date (optional)', { type: 'date', locked: prime && 'prime' })}
            {stringSelect('separation_cause', 'Cause of separation (optional)', options?.cause_of_separation, prime && 'prime')}
            {text('transferred_from', 'Transferred from (optional)')}
            {text('transferred_to', 'Transferred to (optional)')}
          </>
        )}
      </FormSection>
    </div>
  )
}
