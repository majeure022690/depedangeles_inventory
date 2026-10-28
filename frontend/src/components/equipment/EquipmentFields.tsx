import { CheckboxField, FormField } from '@/components/forms/FormField'
import { FormSection } from '@/components/forms/FormSection'
import { Badge } from '@/components/ui/Badge'
import { Input } from '@/components/ui/Input'
import { Select, type SelectOption } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { DESKTOP_PACKAGE_ITEM } from '@/lib/equipmentBundles'
import type { FieldErrors } from '@/lib/errors'
import { withCurrentOption } from '@/lib/options'
import { formatDate } from '@/lib/utils'
import type { ClassificationDefaults, Equipment, EquipmentFormData, EquipmentFormOptions } from '@/types/equipment'

const HIGH_VALUE_THRESHOLD = 50_000
const DEFAULT_ALLOTMENT_CLASS = 'Not Applicable'

export type EquipmentFieldSection = 'item' | 'acquisition' | 'condition'

export const EQUIPMENT_FIELD_SECTIONS: { value: EquipmentFieldSection; label: string; fields: (keyof EquipmentFormData)[] }[] = [
  {
    value: 'item',
    label: 'Item',
    fields: [
      'property_no',
      'old_property_no',
      'serial_number',
      'item_type_id',
      'brand_id',
      'model',
      'uom',
      'equipment_classification_id',
      'equipment_category_id',
      'specifications',
      'non_dcp',
      'dcp_package',
      'dcp_year',
    ],
  },
  {
    value: 'acquisition',
    label: 'Acquisition & accounting',
    fields: [
      'mode_acquisition',
      'source_acquisition',
      'donor',
      'source_fund',
      'received_date',
      'acquisition_cost',
      'allotment_class',
      'gl_sl_code',
      'uacs',
      'estimated_useful_life',
    ],
  },
  {
    value: 'condition',
    label: 'Condition & warranty',
    fields: [
      'equipment_condition_id',
      'disposition_status',
      'non_functional',
      'equipment_location',
      'pm_plan',
      'remarks',
      'under_warranty',
      'end_warranty_date',
      'supplier',
    ],
  },
]

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
    property_no: '',
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
    equipment_category_id: null,
    gl_sl_code: '',
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
  }
}

export function equipmentToForm(equipment: Equipment): EquipmentFormData {
  return {
    property_no: equipment.property_no ?? '',
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
    equipment_category_id: equipment.equipment_category_id,
    gl_sl_code: equipment.gl_sl_code ?? '',
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
  }
}

function derivedCategory(cost: string): string {
  const amount = cost.trim() === '' ? NaN : Number(cost)
  return Number.isFinite(amount) && amount >= HIGH_VALUE_THRESHOLD ? 'High-value (₱50,000 and above)' : 'Low-value (below ₱50,000)'
}

function defaultPlaceholder(value: string | number | null | undefined, classificationChosen: boolean): string {
  if (!classificationChosen) return 'Default from the classification'
  return value === null || value === undefined || value === '' ? 'No default' : `Default: ${value}`
}

type TextKey = { [K in keyof EquipmentFormData]: EquipmentFormData[K] extends string ? K : never }[keyof EquipmentFormData]
type IdKey = { [K in keyof EquipmentFormData]: EquipmentFormData[K] extends number | null ? K : never }[keyof EquipmentFormData]
type BoolKey = { [K in keyof EquipmentFormData]: EquipmentFormData[K] extends boolean ? K : never }[keyof EquipmentFormData]

interface TextProps {
  type?: string
  className?: string
  inputMode?: 'numeric' | 'decimal'
  placeholder?: string
  step?: string
  min?: string
  note?: string
}

interface EquipmentFieldsProps {
  section: EquipmentFieldSection
  value: EquipmentFormData
  onChange: (next: EquipmentFormData) => void
  options: EquipmentFormOptions | null
  /** `classification_defaults` from the options endpoint, keyed by classification id. */
  classificationDefaults?: Record<string, ClassificationDefaults> | null
  errors: FieldErrors
  disabled?: boolean
  /** The saved record on edit, for display names of values that are no longer active options. */
  current?: Equipment | null
}

/** One tab's worth of equipment fields; shared by the create and edit pages. */
export function EquipmentFields({ section, value, onChange, options, classificationDefaults, errors, disabled = false, current }: EquipmentFieldsProps) {
  const itemTypeName = options?.item_type.find((option) => option.value === value.item_type_id)?.label ?? (current?.item_type_id === value.item_type_id ? current?.item : null)
  const classificationChosen = value.equipment_classification_id !== null
  const defaults = classificationChosen ? classificationDefaults?.[String(value.equipment_classification_id)] : undefined
  const savedWarranty = current?.end_warranty_date && current.end_warranty_date === value.end_warranty_date ? current : null

  function set<K extends keyof EquipmentFormData>(key: K, next: EquipmentFormData[K]) {
    onChange({ ...value, [key]: next })
  }

  function text(key: TextKey, label: string, hint: string, props: TextProps = {}) {
    const { className, note, ...inputProps } = props
    return (
      <FormField id={key} label={label} hint={hint} note={note} error={errors[key]} className={className}>
        {(aria) => (
          <Input id={key} name={key} value={value[key]} disabled={disabled} onChange={(e) => set(key, e.target.value)} {...inputProps} {...aria} />
        )}
      </FormField>
    )
  }

  function longText(key: TextKey, label: string, hint: string) {
    return (
      <FormField id={key} label={label} hint={hint} error={errors[key]} className="sm:col-span-2">
        {(aria) => <Textarea id={key} name={key} value={value[key]} disabled={disabled} onChange={(e) => set(key, e.target.value)} {...aria} />}
      </FormField>
    )
  }

  function stringSelect(key: TextKey, label: string, hint: string, list: SelectOption[] | undefined, placeholder: string) {
    return (
      <FormField id={key} label={label} hint={hint} error={errors[key]}>
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

  function idSelect(key: IdKey, label: string, hint: string, list: SelectOption[] | undefined, placeholder: string, currentLabel?: string | null) {
    const selected = value[key]
    return (
      <FormField id={key} label={label} hint={hint} error={errors[key]}>
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

  if (section === 'item') {
    return (
      <div className="space-y-4">
        <FormSection title="Identification">
          {text(
            'property_no',
            'Property number (optional)',
            'Enter the equipment\'s property number following the official format. For newly acquired items or existing properties without an assigned property number, please coordinate with the Asset Management Office for the proper issuance of a new property number.',
          )}
          {text('old_property_no', 'Old property number (optional)', 'Input the equipment\'s previous property number, if it had one before re-numbering.')}
          {text('serial_number', 'Serial number (optional)', 'Input the equipment\'s Serial number')}
        </FormSection>

        <FormSection title="Item details">
          {idSelect(
            'item_type_id',
            'Item',
            'Select the appropriate option from the dropdown list under Item Selection, choosing from Device Type, Equipment, Hardware, Software, or Peripherals.',
            options?.item_type,
            'Select an item type',
            current?.item,
          )}
          {idSelect('brand_id', 'Brand/manufacturer', 'Choose the Brand/Manufacturer from the dropdown.', options?.brand, 'Select a brand', current?.brand_manufacturer)}
          {text('model', 'Model (optional)', 'Input the equipment\'s Model Name or Number.')}
          {stringSelect(
            'uom',
            'Unit of measure (optional)',
            'Leave blank to use the default unit for the selected item.',
            options?.unit_of_measure,
            `Default: ${itemTypeName === DESKTOP_PACKAGE_ITEM ? 'Set (bundled)' : 'Piece'}`,
          )}
          {idSelect(
            'equipment_classification_id',
            'Classification',
            'Select the appropriate Classification from the dropdown. For now, the focus is limited to Machinery and Equipment for Information and Communication Technology (ICT).',
            options?.classification,
            'Select a classification',
            current?.classification,
          )}
          {idSelect(
            'equipment_category_id',
            'Category (optional)',
            'Leave blank to set the category from the acquisition cost: ₱50,000 and above is high-value, below is low-value.',
            options?.category,
            'Set from the acquisition cost',
            current?.category,
          )}
          {longText(
            'specifications',
            'Specifications (optional)',
            'Enter the equipment\'s detailed specifications. Refer to the Delivery Receipt (DR), Property Acknowledgment Receipt (PAR), Inventory Custodian Slip (ICS), Inspection and Acceptance Report (IAR), or other relevant documents.',
          )}
          {checkbox('non_dcp', 'Non-DCP', 'Leave unticked if the item was delivered under the DepEd Computerization Program (DCP).')}
          {stringSelect('dcp_package', 'DCP package (optional)', 'Input the Package Name of the DCP. Leave blank for non-DCP items.', options?.dcp_package, 'None')}
          {text('dcp_year', 'DCP year (optional)', 'Input the Year of the DCP Package the item belongs to. Leave blank for non-DCP items.', {
            inputMode: 'numeric',
            placeholder: 'YYYY',
          })}
        </FormSection>
      </div>
    )
  }

  if (section === 'acquisition') {
    return (
      <div className="space-y-4">
        <FormSection title="Acquisition & funds">
          {stringSelect(
            'mode_acquisition',
            'Mode of acquisition',
            'Select from the dropdown whether the equipment was acquired through a DepEd purchase or received as a donation.',
            options?.mode_of_acquisition,
            'Select a mode',
          )}
          {stringSelect(
            'source_acquisition',
            'Source of acquisition',
            'From the dropdown, select the source of acquisition (e.g., Central Office, LGU, PTA, etc.).',
            options?.source_of_acquisition,
            'Select a source',
          )}
          {text('donor', 'Donor (optional)', 'Name of the donating institution (i.e. Company, Association, Organization, Agency or LGU), for donated equipment.', {
            className: 'sm:col-span-2',
          })}
          {stringSelect('source_fund', 'Source of funds', 'Select the corresponding source of funds from the dropdown.', options?.source_of_funds, 'Select a fund source')}
          {text('received_date', 'Received date (optional)', 'Input when the equipment was acquired in the \'MM/DD/YYYY\' date format.', { type: 'date' })}
          {text(
            'acquisition_cost',
            'Acquisition cost (optional)',
            'Input the cost of purchase or value of the equipment. Please refer to the Purchase Order (PO) or related documents for reference.',
            {
              type: 'number',
              step: '0.01',
              min: '0',
              inputMode: 'decimal',
              note: `Category suggestion from cost: ${derivedCategory(value.acquisition_cost)}`,
              className: 'sm:col-span-2',
            },
          )}
        </FormSection>

        <FormSection title="Accounting">
          {stringSelect(
            'allotment_class',
            'Allotment class (optional)',
            'Select the corresponding Allotment Class from the dropdown.',
            options?.allotment_class,
            `Default: ${DEFAULT_ALLOTMENT_CLASS}`,
          )}
          {text(
            'gl_sl_code',
            'GL/SL code (optional)',
            'Subsidiary Ledger Chart of Accounts code. Leave blank to use the classification\'s default. Refer to https://uacs.gov.ph/resources/uacs/object-code/chart-of-accounts',
            { placeholder: defaultPlaceholder(defaults?.default_gl_sl_code, classificationChosen) },
          )}
          {text(
            'uacs',
            'UACS (optional)',
            'UACS object code. Leave blank to use the classification\'s default. Refer to https://uacs.gov.ph/resources/uacs/object-code/sub-object',
            { placeholder: defaultPlaceholder(defaults?.default_uacs, classificationChosen) },
          )}
          {text('estimated_useful_life', 'Estimated useful life (years) (optional)', 'Whole number of years. Leave blank to use the classification\'s default.', {
            type: 'number',
            min: '0',
            step: '1',
            inputMode: 'numeric',
            placeholder: defaultPlaceholder(defaults?.default_useful_life != null ? `${defaults.default_useful_life} years` : null, classificationChosen),
          })}
        </FormSection>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <FormSection title="Condition & location">
        {idSelect(
          'equipment_condition_id',
          'Condition',
          'Select the equipment\'s condition from the dropdown. Additional details, such as missing accessories, broken parts, non-functional features, or items needing repair, may be provided in the Remarks column.',
          options?.condition,
          'Select a condition',
          current?.equipment_condition,
        )}
        {stringSelect(
          'disposition_status',
          'Disposition status',
          'Select the equipment\'s Accountability or Disposition Status from the dropdown: Normal, Transferred, Stolen, Lost, Damaged due to calamity, or For Disposal. Additional details may be provided in the Remarks column.',
          options?.disposition_status,
          'Select a disposition status',
        )}
        {checkbox('non_functional', 'Non-functional', 'Tick if the equipment no longer works. Describe the problem in the Remarks.')}
        {longText(
          'equipment_location',
          'Location (optional)',
          'Enter the location where the equipment is installed or primarily used (e.g., Access Point installed in School Annex). For movable or mobile assets, indicate the designated office, or leave this field blank if not applicable.',
        )}
        {text('pm_plan', 'PM plan (optional)', 'Input the preventive maintenance plan or schedule for the equipment.', { className: 'sm:col-span-2' })}
        {longText(
          'remarks',
          'Remarks (optional)',
          'This column may include the technical assessment report, evaluation findings, recommendations, justifications, or other relevant information/documents related to the equipment. Include additional notes such as missing accessories, broken parts, non-functional features, repair needs, repair history, or if the item is unusable or unserviceable. For damaged equipment, indicate if the cause is due to natural disasters (e.g., floods, earthquakes, typhoons). The information provided here will serve as justification for any unknown or unidentified details, including document reference numbers, the accountable officer or custodian, or other information that cannot be determined by the user.',
        )}
      </FormSection>

      <FormSection title="Warranty">
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
        {text('supplier', 'Supplier (optional)', 'Input the name of the Supplier / Provider or Distributor of the equipment')}
      </FormSection>
    </div>
  )
}
