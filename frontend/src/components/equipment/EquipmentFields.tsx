import { Link } from 'react-router-dom'

import { CheckboxField, FormField } from '@/components/forms/FormField'
import { FormSection } from '@/components/forms/FormSection'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Input } from '@/components/ui/Input'
import { Select, type SelectOption } from '@/components/ui/Select'
import { SelectPicker } from '@/components/ui/SelectPicker'
import { Textarea } from '@/components/ui/Textarea'
import type { FieldErrors } from '@/lib/errors'
import { withCurrentOption } from '@/lib/options'
import { formatDate } from '@/lib/utils'
import type {
  Equipment,
  EquipmentCategoryRule,
  EquipmentCategoryRules,
  EquipmentFormData,
  EquipmentOptions,
  EquipmentTransactionFormData,
} from '@/types/equipment'

const DEFAULT_ALLOTMENT_CLASS = 'Not Applicable'
const DEFAULT_TRANSACTION_TYPE = 'Beginning Inventory'

export type EquipmentFieldSection = 'equipment' | 'acquisition' | 'accountability' | 'status' | 'documents'

type AccountabilityKey = keyof EquipmentTransactionFormData
type SectionField = keyof EquipmentFormData | 'property_no' | 'accountability' | `accountability.${AccountabilityKey}`

export const EQUIPMENT_FIELD_SECTIONS: { value: EquipmentFieldSection; label: string; fields: SectionField[] }[] = [
  {
    value: 'equipment',
    label: 'Equipment',
    fields: ['property_no', 'old_property_no', 'item_type_id', 'uom', 'brand_id', 'model', 'serial_number', 'specifications'],
  },
  {
    value: 'acquisition',
    label: 'Acquisition',
    fields: [
      'acquisition_cost',
      'received_date',
      'mode_acquisition',
      'source_acquisition',
      'donor',
      'source_fund',
      'equipment_classification_id',
      'non_dcp',
      'dcp_package',
      'dcp_year',
      'uacs',
      'allotment_class',
    ],
  },
  {
    value: 'accountability',
    label: 'Accountability',
    fields: [
      'accountability',
      'accountability.accountable_officer_id',
      'accountability.date_assigned_accountable_officer',
      'accountability.end_user_id',
      'accountability.date_assigned_end_user',
      'accountability.received_by_id',
      'accountability.date_received_new_accountable',
      'accountability.supporting_documents2',
      'accountability.par_ics_rrsp_rs_wmr_no',
      'used_by',
    ],
  },
  {
    value: 'status',
    label: 'Status / Location',
    fields: [
      'equipment_location',
      'non_functional',
      'equipment_condition_id',
      'disposition_status',
      'remarks',
      'supplier',
      'under_warranty',
      'end_warranty_date',
      'estimated_useful_life',
      'accountability.transaction_type',
    ],
  },
  {
    value: 'documents',
    label: 'Documents',
    fields: ['pm_plan', 'accountability.supporting_documents1', 'accountability.or_si_dr_iar_no'],
  },
]

/** Nested request objects whose 422 keys keep their column (pass to `extractFieldErrors`). */
export const EQUIPMENT_NESTED_ERROR_FIELDS = ['accountability'] as const

export function isEquipmentFieldSection(value: string | null): value is EquipmentFieldSection {
  return EQUIPMENT_FIELD_SECTIONS.some((section) => section.value === value)
}

export function sectionErrorCount(section: EquipmentFieldSection, errors: FieldErrors): number {
  return EQUIPMENT_FIELD_SECTIONS.find((s) => s.value === section)?.fields.filter((field) => errors[field]).length ?? 0
}

export function firstSectionWithError(errors: FieldErrors): EquipmentFieldSection | null {
  return EQUIPMENT_FIELD_SECTIONS.find((section) => section.fields.some((field) => errors[field]))?.value ?? null
}

export function emptyEquipmentForm(): EquipmentFormData {
  return {
    old_property_no: '',
    serial_number: '',
    item_type_id: null,
    uom: '',
    brand_id: null,
    model: '',
    specifications: '',
    non_dcp: true,
    dcp_package: '',
    dcp_year: '',
    equipment_classification_id: null,
    uacs: '',
    acquisition_cost: '',
    received_date: '',
    estimated_useful_life: '',
    mode_acquisition: '',
    source_acquisition: '',
    donor: '',
    source_fund: '',
    allotment_class: '',
    pm_plan: '',
    equipment_location: '',
    non_functional: false,
    equipment_condition_id: null,
    disposition_status: '',
    remarks: '',
    under_warranty: false,
    end_warranty_date: '',
    supplier: '',
    used_by: '',
  }
}

/** Initial accountability for a new record; the transaction type defaults to Beginning Inventory when it is offered. */
export function emptyAccountability(options?: EquipmentOptions | null): EquipmentTransactionFormData {
  const offersDefault = options?.transaction.transaction_type.some((option) => option.value === DEFAULT_TRANSACTION_TYPE) ?? false
  return {
    transaction_type: offersDefault ? DEFAULT_TRANSACTION_TYPE : '',
    accountable_officer_id: null,
    end_user_id: null,
    received_by_id: null,
    date_assigned_accountable_officer: '',
    date_assigned_end_user: '',
    date_received_new_accountable: '',
    supporting_documents1: '',
    or_si_dr_iar_no: '',
    supporting_documents2: '',
    par_ics_rrsp_rs_wmr_no: '',
  }
}

export function equipmentToForm(equipment: Equipment): EquipmentFormData {
  return {
    old_property_no: equipment.old_property_no ?? '',
    serial_number: equipment.serial_number ?? '',
    item_type_id: equipment.item_type_id,
    uom: equipment.uom ?? '',
    brand_id: equipment.brand_id,
    model: equipment.model ?? '',
    specifications: equipment.specifications ?? '',
    non_dcp: equipment.non_dcp,
    dcp_package: equipment.dcp_package ?? '',
    dcp_year: equipment.dcp_year ? String(equipment.dcp_year) : '',
    equipment_classification_id: equipment.equipment_classification_id,
    uacs: equipment.uacs ?? '',
    acquisition_cost: equipment.acquisition_cost !== null ? String(equipment.acquisition_cost) : '',
    received_date: equipment.received_date ?? '',
    estimated_useful_life: equipment.estimated_useful_life !== null ? String(equipment.estimated_useful_life) : '',
    mode_acquisition: equipment.mode_acquisition ?? '',
    source_acquisition: equipment.source_acquisition ?? '',
    donor: equipment.donor ?? '',
    source_fund: equipment.source_fund ?? '',
    allotment_class: equipment.allotment_class ?? '',
    pm_plan: equipment.pm_plan ?? '',
    equipment_location: equipment.equipment_location ?? '',
    non_functional: equipment.non_functional,
    equipment_condition_id: equipment.equipment_condition_id,
    disposition_status: equipment.disposition_status ?? '',
    remarks: equipment.remarks ?? '',
    under_warranty: equipment.under_warranty,
    end_warranty_date: equipment.end_warranty_date ?? '',
    supplier: equipment.supplier ?? '',
    used_by: equipment.used_by ?? '',
  }
}

function parseCost(cost: string): number | null {
  if (cost.trim() === '') return null
  const amount = Number(cost)
  return Number.isFinite(amount) ? amount : null
}

/** The stored category/GL-SL while the cost is unchanged on edit, otherwise the preview from the options' rules. */
function accountingPreview(cost: string, rules: EquipmentCategoryRules | undefined, current: Equipment | null | undefined): EquipmentCategoryRule | null {
  const amount = parseCost(cost)
  if (current && amount === current.acquisition_cost) {
    return { category: current.category ?? '', gl_sl_code: current.gl_sl_code ?? '' }
  }
  if (amount === null || !rules) return null
  return amount >= rules.threshold ? rules.high : rules.low
}

function defaultPlaceholder(value: string | number | null | undefined, classificationChosen: boolean): string {
  if (!classificationChosen) return 'Default from the classification'
  return value === null || value === undefined || value === '' ? 'No default' : `Default: ${value}`
}

type TextKey = { [K in keyof EquipmentFormData]: EquipmentFormData[K] extends string ? K : never }[keyof EquipmentFormData]
type IdKey = { [K in keyof EquipmentFormData]: EquipmentFormData[K] extends number | null ? K : never }[keyof EquipmentFormData]
type BoolKey = { [K in keyof EquipmentFormData]: EquipmentFormData[K] extends boolean ? K : never }[keyof EquipmentFormData]
type PersonKey = 'accountable_officer_id' | 'end_user_id' | 'received_by_id'
type AccountabilityTextKey = Exclude<AccountabilityKey, PersonKey>

interface TextProps {
  type?: string
  className?: string
  inputMode?: 'numeric' | 'decimal'
  placeholder?: string
  step?: string
  min?: string
  note?: string
  required?: boolean
}

interface EquipmentFieldsProps {
  section: EquipmentFieldSection
  mode: 'create' | 'edit'
  value: EquipmentFormData
  onChange: (next: EquipmentFormData) => void
  options: EquipmentOptions | null
  errors: FieldErrors
  disabled?: boolean
  /** The saved record on edit, for display names of values that are no longer active options. */
  current?: Equipment | null
  /** The initial accountability transaction (create only). */
  accountability?: EquipmentTransactionFormData
  onAccountabilityChange?: (next: EquipmentTransactionFormData) => void
}

/** One tab's worth of equipment fields; shared by the create and edit pages. */
export function EquipmentFields({
  section,
  mode,
  value,
  onChange,
  options,
  errors,
  disabled = false,
  current,
  accountability,
  onAccountabilityChange,
}: EquipmentFieldsProps) {
  const formOptions = options?.form
  const isCreate = mode === 'create'
  const classificationChosen = value.equipment_classification_id !== null
  const defaults = classificationChosen ? options?.classification_defaults[String(value.equipment_classification_id)] : undefined
  const savedWarranty = current?.end_warranty_date && current.end_warranty_date === value.end_warranty_date ? current : null
  const needsDonor = options?.donor_sources.includes(value.source_acquisition) ?? false
  const accounting = accountingPreview(value.acquisition_cost, options?.category_rules, current)

  function set<K extends keyof EquipmentFormData>(key: K, next: EquipmentFormData[K]) {
    onChange({ ...value, [key]: next })
  }

  function text(key: TextKey, label: string, hint: string, props: TextProps = {}) {
    const { className, note, required, ...inputProps } = props
    return (
      <FormField id={key} label={label} hint={hint} note={note} error={errors[key]} required={required} className={className}>
        {(aria) => (
          <Input id={key} name={key} value={value[key]} disabled={disabled} onChange={(e) => set(key, e.target.value)} {...inputProps} {...aria} />
        )}
      </FormField>
    )
  }

  function longText(key: TextKey, label: string, hint: string, required = false) {
    return (
      <FormField id={key} label={label} hint={hint} error={errors[key]} required={required} className="sm:col-span-2">
        {(aria) => <Textarea id={key} name={key} value={value[key]} disabled={disabled} onChange={(e) => set(key, e.target.value)} {...aria} />}
      </FormField>
    )
  }

  function stringSelect(key: TextKey, label: string, hint: string, list: SelectOption[] | undefined, placeholder: string, required = false) {
    return (
      <FormField id={key} label={label} hint={hint} error={errors[key]} required={required}>
        {(aria) => (
          <Select
            id={key}
            name={key}
            value={value[key]}
            disabled={disabled || !options}
            options={withCurrentOption(list, value[key])}
            placeholder={placeholder}
            onChange={(e) => set(key, e.target.value)}
            {...aria}
          />
        )}
      </FormField>
    )
  }

  function idSelect(key: IdKey, label: string, hint: string, list: SelectOption[] | undefined, placeholder: string, currentLabel: string | null | undefined) {
    const selected = value[key]
    return (
      <FormField id={key} label={label} hint={hint} error={errors[key]} required>
        {(aria) => (
          <Select
            id={key}
            name={key}
            value={selected ?? ''}
            disabled={disabled || !options}
            options={withCurrentOption(list, selected, current?.[key] === selected ? currentLabel : null)}
            placeholder={placeholder}
            onChange={(e) => set(key, e.target.value === '' ? null : Number(e.target.value))}
            {...aria}
          />
        )}
      </FormField>
    )
  }

  function checkbox(key: BoolKey, label: string, hint: string) {
    return (
      <div className="sm:col-span-2">
        <CheckboxField id={key} label={label} hint={hint} checked={value[key]} disabled={disabled} error={errors[key]} onChange={(checked) => set(key, checked)} />
      </div>
    )
  }

  function readOnly(id: string, label: string, display: string, note: string, emptyText: string) {
    return (
      <FormField id={id} label={label} note={note} error={errors[id]}>
        {(aria) => (
          <Input id={id} readOnly value={display || emptyText} className={display ? 'bg-gray-50' : 'bg-gray-50 text-gray-500'} {...aria} />
        )}
      </FormField>
    )
  }

  function setAccountability<K extends AccountabilityKey>(key: K, next: EquipmentTransactionFormData[K]) {
    if (accountability && onAccountabilityChange) onAccountabilityChange({ ...accountability, [key]: next })
  }

  function accountabilityText(key: AccountabilityTextKey, label: string, hint: string, type: 'text' | 'date' = 'text', required = false) {
    const id = `accountability-${key}`
    return (
      <FormField id={id} label={label} hint={hint} error={errors[`accountability.${key}`]} required={required}>
        {(aria) => <Input id={id} type={type} value={accountability?.[key] ?? ''} disabled={disabled} onChange={(e) => setAccountability(key, e.target.value)} {...aria} />}
      </FormField>
    )
  }

  function accountabilitySelect(key: AccountabilityTextKey, label: string, hint: string, list: SelectOption[] | undefined, placeholder: string, required = false) {
    const id = `accountability-${key}`
    return (
      <FormField id={id} label={label} hint={hint} error={errors[`accountability.${key}`]} required={required}>
        {(aria) => (
          <Select
            id={id}
            value={accountability?.[key] ?? ''}
            options={list ?? []}
            disabled={disabled || !options}
            placeholder={placeholder}
            onChange={(e) => setAccountability(key, e.target.value)}
            {...aria}
          />
        )}
      </FormField>
    )
  }

  function personField(key: PersonKey, label: string, hint: string) {
    const id = `accountability-${key}`
    const selected = accountability?.[key] ?? null
    const people = (options?.personnel ?? []).map((option) => ({ value: String(option.value), label: option.label }))
    return (
      <FormField id={id} label={label} hint={hint} error={errors[`accountability.${key}`]} required>
        {(aria) => (
          <SelectPicker
            id={id}
            aria-labelledby={`${id}-label`}
            options={people}
            disabled={disabled || people.length === 0}
            selectedValue={selected === null ? null : String(selected)}
            onSelect={(option) => setAccountability(key, option ? Number(option.value) : null)}
            placeholder="Select personnel"
            {...aria}
          />
        )}
      </FormField>
    )
  }

  if (section === 'equipment') {
    return (
      <div className="space-y-4">
        <FormSection title="Identification">
          {isCreate
            ? readOnly(
                'property_no',
                'Property number',
                '',
                'Assigned automatically when the record is saved, as AC-YYYY-IT-ABV-NNNNNN from the acquisition year and the item type.',
                'Generated when saved',
              )
            : readOnly('property_no', 'Property number', current?.property_no ?? '', 'Generated by the system and can\'t be changed.', 'Not assigned')}
          {text('old_property_no', 'Old property number (optional)', 'Input the equipment\'s previous property number, if it had one before re-numbering.')}
        </FormSection>

        <FormSection title="Item details">
          {idSelect(
            'item_type_id',
            'Item',
            'Select the appropriate option from the dropdown list under Item Selection, choosing from Device Type, Equipment, Hardware, Software, or Peripherals.',
            formOptions?.item_type,
            'Select an item type',
            current?.item,
          )}
          {stringSelect('uom', 'Unit of measure', 'Select the unit of measure of the item.', formOptions?.unit_of_measure, 'Select a unit of measure', true)}
          {idSelect('brand_id', 'Brand/manufacturer', 'Choose the Brand/Manufacturer from the dropdown.', formOptions?.brand, 'Select a brand', current?.brand_manufacturer)}
          {text('model', 'Model', 'Input the equipment\'s Model Name or Number.', { required: true })}
          {text('serial_number', 'Serial number', 'Input the equipment\'s Serial number', { required: true })}
          {longText(
            'specifications',
            'Specifications',
            'Enter the equipment\'s detailed specifications. Refer to the Delivery Receipt (DR), Property Acknowledgment Receipt (PAR), Inventory Custodian Slip (ICS), Inspection and Acceptance Report (IAR), or other relevant documents.',
            true,
          )}
        </FormSection>
      </div>
    )
  }

  if (section === 'acquisition') {
    return (
      <div className="space-y-4">
        <FormSection title="Acquisition & funds">
          {text(
            'acquisition_cost',
            'Acquisition cost',
            'Input the cost of purchase or value of the equipment. Please refer to the Purchase Order (PO) or related documents for reference.',
            { type: 'number', step: '0.01', min: '0', inputMode: 'decimal', required: true },
          )}
          {text('received_date', 'Acquisition date', 'Input when the equipment was acquired in the \'MM/DD/YYYY\' date format.', { type: 'date', required: true })}
          {stringSelect(
            'mode_acquisition',
            'Mode of acquisition',
            'Select from the dropdown whether the equipment was acquired through a DepEd purchase or received as a donation.',
            formOptions?.mode_of_acquisition,
            'Select a mode',
            true,
          )}
          {stringSelect(
            'source_acquisition',
            'Source of acquisition',
            'From the dropdown, select the source of acquisition (e.g., Central Office, LGU, PTA, etc.).',
            formOptions?.source_of_acquisition,
            'Select a source',
            true,
          )}
          {needsDonor &&
            text('donor', 'Donor', 'Name of the donating institution (i.e. Company, Association, Organization, Agency or LGU).', {
              className: 'sm:col-span-2',
              required: true,
            })}
          {stringSelect('source_fund', 'Source of funds', 'Select the corresponding source of funds from the dropdown.', formOptions?.source_of_funds, 'Select a fund source', true)}
        </FormSection>

        <FormSection title="Classification & accounting">
          {readOnly(
            'category',
            'Category',
            accounting?.category ?? '',
            options?.category_rules
              ? `Set from the acquisition cost: ₱${options.category_rules.threshold.toLocaleString('en-PH')} and above is ${options.category_rules.high.category}, below is ${options.category_rules.low.category}.`
              : 'Set from the acquisition cost.',
            'Set from the acquisition cost',
          )}
          {idSelect(
            'equipment_classification_id',
            'Classification',
            'Select the appropriate Classification from the dropdown. For now, the focus is limited to Machinery and Equipment for Information and Communication Technology (ICT).',
            formOptions?.classification,
            'Select a classification',
            current?.classification,
          )}
          {checkbox('non_dcp', 'Non-DCP', 'Leave unticked if the item was delivered under the DepEd Computerization Program (DCP).')}
          {!value.non_dcp && (
            <>
              {stringSelect('dcp_package', 'DCP package', 'Input the Package Name of the DCP.', formOptions?.dcp_package, 'Select a DCP package', true)}
              {text('dcp_year', 'DCP year package', 'Input the Year of the DCP Package the item belongs to.', {
                inputMode: 'numeric',
                placeholder: 'YYYY',
                required: true,
              })}
            </>
          )}
          {readOnly(
            'gl_sl_code',
            'GL-SL code (NGAS)',
            accounting?.gl_sl_code ?? '',
            'Set from the category. Refer to https://uacs.gov.ph/resources/uacs/object-code/chart-of-accounts',
            'Set from the acquisition cost',
          )}
          {text(
            'uacs',
            'UACS (optional)',
            'UACS object code. Leave blank to use the classification\'s default. Refer to https://uacs.gov.ph/resources/uacs/object-code/sub-object',
            { placeholder: defaultPlaceholder(defaults?.default_uacs, classificationChosen) },
          )}
          {stringSelect(
            'allotment_class',
            'Allotment class (optional)',
            'Select the corresponding Allotment Class from the dropdown.',
            formOptions?.allotment_class,
            `Default: ${DEFAULT_ALLOTMENT_CLASS}`,
          )}
        </FormSection>
      </div>
    )
  }

  if (section === 'accountability') {
    const usedBy = stringSelect(
      'used_by',
      'Used by',
      'Select who primarily uses the equipment: Learner, Teacher, Teaching-Related or Non-Teaching personnel.',
      formOptions?.used_by,
      'Select who uses it',
      true,
    )

    if (!isCreate) {
      return (
        <div className="space-y-4">
          <FormSection title="Usage">{usedBy}</FormSection>
          <FormSection title="Current accountability">
            <dl className="grid gap-x-6 gap-y-3 text-sm sm:col-span-2 sm:grid-cols-2">
              <div className="min-w-0">
                <dt className="text-xs text-gray-500">Accountable officer</dt>
                <dd className="font-medium text-gray-900">{current?.current_accountable_officer?.full_name ?? '—'}</dd>
              </div>
              <div className="min-w-0">
                <dt className="text-xs text-gray-500">Custodian / end user</dt>
                <dd className="font-medium text-gray-900">{current?.current_end_user?.full_name ?? '—'}</dd>
              </div>
            </dl>
            <p className="text-sm text-gray-600 sm:col-span-2">
              Accountability changes are recorded as transactions. Log one in the{' '}
              <Link to={{ search: '?tab=history' }} replace className="font-medium text-primary-700 underline hover:no-underline">
                History tab
              </Link>
              .
            </p>
          </FormSection>
        </div>
      )
    }

    return (
      <div className="space-y-4">
        {errors.accountability && <Alert type="error">{errors.accountability}</Alert>}
        {options && !options.personnel && (
          <Alert type="warning">Your account can&apos;t assign accountability, so this record can&apos;t be saved. Ask an administrator for access to record equipment transactions.</Alert>
        )}
        {options?.personnel?.length === 0 && (
          <Alert type="warning">No personnel are within your access, so accountability can&apos;t be assigned and this record can&apos;t be saved. Ask an administrator.</Alert>
        )}
        <FormSection title="Initial accountability">
          {personField('accountable_officer_id', 'Accountable officer', 'Choose the employee accountable for the equipment from the dropdown.')}
          {accountabilityText(
            'date_assigned_accountable_officer',
            'Date assigned to accountable officer',
            'Enter the date when the equipment was received by the Accountable Officer in the \'MM/DD/YYYY\' format.',
            'date',
            true,
          )}
          {personField('end_user_id', 'Custodian / end user', 'Select the Custodian or End User from the dropdown to whom the equipment is assigned.')}
          {accountabilityText(
            'date_assigned_end_user',
            'Date assigned to end user',
            'Enter the date when the equipment was received by the Custodian/End User in the \'MM/DD/YYYY\' format.',
            'date',
            true,
          )}
          {personField('received_by_id', 'Received by', 'Choose the Accountable Officer, Custodian, or End User who received the equipment.')}
          {accountabilityText(
            'date_received_new_accountable',
            'Date received (optional)',
            'Enter the date when the equipment was received in the \'MM/DD/YYYY\' format.',
            'date',
          )}
          {accountabilitySelect(
            'supporting_documents2',
            'Supporting document (PAR/ICS/RRSP/RS/WMR) (optional)',
            'Select the document type for the issuance: PAR, ICS, RRSP, RS or WMR.',
            options?.transaction.supporting_document_type,
            'Select a document type',
          )}
          {accountabilityText('par_ics_rrsp_rs_wmr_no', 'PAR/ICS/RRSP/RS/WMR no. (optional)', 'Input the document number to track and properly identify the transaction.')}
          {usedBy}
        </FormSection>
      </div>
    )
  }

  if (section === 'documents') {
    return (
      <div className="space-y-4">
        <FormSection title="Documents">
          {text('pm_plan', 'PM plan (optional)', 'Input the preventive maintenance plan or schedule for the equipment.', { className: 'sm:col-span-2' })}
          {isCreate && (
            <>
              {accountabilitySelect(
                'supporting_documents1',
                'Supporting document (OR/SI/DR/IAR/RRSP) (optional)',
                'Select the document type for the delivery or inspection: OR, SI, DR, IAR or RRSP.',
                options?.transaction.supporting_document_type,
                'Select a document type',
              )}
              {accountabilityText(
                'or_si_dr_iar_no',
                'Document no. (optional)',
                'Enter the document number for the delivery or inspection to ensure proper tracking and identification of the equipment.',
              )}
            </>
          )}
        </FormSection>
        {isCreate && <Alert type="info">You can attach scanned documents or photos after saving the record.</Alert>}
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <FormSection title="Status & location">
        {longText(
          'equipment_location',
          'Location',
          'Enter the location where the equipment is installed or primarily used (e.g., Access Point installed in School Annex). For movable or mobile assets, indicate the designated office.',
          true,
        )}
        {checkbox('non_functional', 'Non-functional', 'Tick if the equipment no longer works. Describe the problem in the Remarks.')}
        {idSelect(
          'equipment_condition_id',
          'Condition',
          'Select the equipment\'s condition from the dropdown. Additional details, such as missing accessories, broken parts, non-functional features, or items needing repair, may be provided in the Remarks column.',
          formOptions?.condition,
          'Select a condition',
          current?.equipment_condition,
        )}
        {stringSelect(
          'disposition_status',
          'Disposition status',
          'Select the equipment\'s Accountability or Disposition Status from the dropdown: Normal, Transferred, Stolen, Lost, Damaged due to calamity, or For Disposal. Additional details may be provided in the Remarks column.',
          formOptions?.disposition_status,
          'Select a disposition status',
          true,
        )}
        {longText(
          'remarks',
          'Remarks (optional)',
          'This column may include the technical assessment report, evaluation findings, recommendations, justifications, or other relevant information/documents related to the equipment. Include additional notes such as missing accessories, broken parts, non-functional features, repair needs, repair history, or if the item is unusable or unserviceable. For damaged equipment, indicate if the cause is due to natural disasters (e.g., floods, earthquakes, typhoons). The information provided here will serve as justification for any unknown or unidentified details, including document reference numbers, the accountable officer or custodian, or other information that cannot be determined by the user.',
        )}
        {text('estimated_useful_life', 'Estimated useful life (years) (optional)', 'Whole number of years. Leave blank to use the classification\'s default.', {
          type: 'number',
          min: '0',
          step: '1',
          inputMode: 'numeric',
          placeholder: defaultPlaceholder(defaults?.default_useful_life != null ? `${defaults.default_useful_life} years` : null, classificationChosen),
        })}
        {isCreate &&
          accountabilitySelect(
            'transaction_type',
            'Transaction type',
            'The transaction recorded with the initial accountability. For a new record this is usually \'Beginning Inventory.\'',
            options?.transaction.transaction_type,
            'Select a transaction type',
            true,
          )}
      </FormSection>

      <FormSection title="Supplier & warranty">
        {text('supplier', 'Supplier (optional)', 'Input the name of the Supplier / Provider or Distributor of the equipment')}
        {savedWarranty && (
          <div className="sm:col-span-2">
            {savedWarranty.warranty_active ? (
              <Badge color="success">Under warranty until {formatDate(savedWarranty.end_warranty_date)}</Badge>
            ) : (
              <Badge color="gray">Warranty expired</Badge>
            )}
          </div>
        )}
        {checkbox('under_warranty', 'Under warranty', 'Tick if the equipment is still covered by the supplier\'s or manufacturer\'s warranty.')}
        {text('end_warranty_date', 'Warranty end date (optional)', 'Input the End of Warranty in the \'MM/DD/YYYY\' date format', { type: 'date' })}
      </FormSection>
    </div>
  )
}
