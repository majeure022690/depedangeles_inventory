import { CheckboxField, FormField } from '@/components/forms/FormField'
import { FormSection } from '@/components/forms/FormSection'
import { Input } from '@/components/ui/Input'
import { Select, type SelectOption } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import type { FieldErrors } from '@/lib/errors'
import { withCurrentOption } from '@/lib/options'
import type { Equipment, EquipmentFormData, EquipmentFormOptions } from '@/types/equipment'

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
    non_dcp: false,
    dcp_package: '',
    dcp_year: '',
    equipment_category_id: null,
    equipment_classification_id: null,
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
    equipment_category_id: equipment.equipment_category_id,
    equipment_classification_id: equipment.equipment_classification_id,
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

type TextKey = { [K in keyof EquipmentFormData]: EquipmentFormData[K] extends string ? K : never }[keyof EquipmentFormData]
type IdKey = { [K in keyof EquipmentFormData]: EquipmentFormData[K] extends number | null ? K : never }[keyof EquipmentFormData]
type BoolKey = { [K in keyof EquipmentFormData]: EquipmentFormData[K] extends boolean ? K : never }[keyof EquipmentFormData]

interface EquipmentFieldsProps {
  value: EquipmentFormData
  onChange: (next: EquipmentFormData) => void
  options: EquipmentFormOptions | null
  errors: FieldErrors
  disabled?: boolean
  /** The saved record on edit, for display names of values that are no longer active options. */
  current?: Equipment | null
}

/** Port of the old `equipment/Partials/Form.vue`; shared by the create and edit pages. */
export function EquipmentFields({ value, onChange, options, errors, disabled = false, current }: EquipmentFieldsProps) {
  function set<K extends keyof EquipmentFormData>(key: K, next: EquipmentFormData[K]) {
    onChange({ ...value, [key]: next })
  }

  function text(key: TextKey, label: string, hint: string, props: { type?: string; className?: string; inputMode?: 'numeric' | 'decimal'; placeholder?: string; step?: string; min?: string } = {}) {
    const { className, ...inputProps } = props
    return (
      <FormField id={key} label={label} hint={hint} error={errors[key]} className={className}>
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

  function longText(key: TextKey, label: string, hint: string) {
    return (
      <FormField id={key} label={label} hint={hint} error={errors[key]} className="sm:col-span-2">
        {(aria) => (
          <Textarea
            id={key}
            name={key}
            value={value[key]}
            disabled={disabled}
            onChange={(e) => set(key, e.target.value)}
            {...aria}
          />
        )}
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
    return <CheckboxField id={key} label={label} hint={hint} checked={value[key]} disabled={disabled} error={errors[key]} onChange={(checked) => set(key, checked)} />
  }

  return (
    <div className="grid items-start gap-4 lg:grid-cols-2">
      <FormSection title="Identification">
        {text(
          'property_no',
          'Property number (optional)',
          'Enter the equipment\'s property number following the official format. For newly acquired items or existing properties without an assigned property number, please coordinate with the Asset Management Office for the proper issuance of a new property number.',
        )}
        {text(
          'old_property_no',
          'Old property number (optional)',
          'Enter the equipment\'s old or previous property number, if applicable (e.g., when the item was originally part of a bundled set and later separated into standalone components such as a keyboard, monitor, mouse, or RAM from the CPU).',
        )}
        {text('serial_number', 'Serial number (optional)', 'Input the equipment\'s Serial number')}
      </FormSection>

      <FormSection title="Accounting">
        {text(
          'gl_sl_code',
          'GL/SL code (optional)',
          'Input the Subsidiary Ledger Chart of Accounts code assigned to the equipment. Kindly coordinate with your Accounting office for assistance. Please refer to https://uacs.gov.ph/resources/uacs/object-code/chart-of-accounts',
        )}
        {text(
          'uacs',
          'UACS (optional)',
          'Input the UACS code assigned to the equipment. Kindly coordinate with your Accounting office for assistance. Please refer to https://uacs.gov.ph/resources/uacs/object-code/sub-object',
        )}
        {text(
          'acquisition_cost',
          'Acquisition cost (optional)',
          'Input the cost of purchase or value of the equipment. Please refer to the Purchase Order (PO) or related documents for reference.',
          { type: 'number', step: '0.01', min: '0', inputMode: 'decimal' },
        )}
        {text('received_date', 'Received date (optional)', 'Input when the equipment was acquired in the \'MM/DD/YYYY\' date format.', { type: 'date' })}
        {text(
          'estimated_useful_life',
          'Estimated useful life (years) (optional)',
          'Input the equipment\'s estimated useful life (only accepts whole number). Kindly seek assistance from your Accounting office.',
          { type: 'number', min: '0', step: '1', inputMode: 'numeric' },
        )}
      </FormSection>

      <FormSection title="Item details" className="lg:col-span-2">
        {idSelect(
          'item_type_id',
          'Item',
          'Select the appropriate option from the dropdown list under Item Selection, choosing from Device Type, Equipment, Hardware, Software, or Peripherals.',
          options?.item_type,
          'Select an item type',
          current?.item,
        )}
        {stringSelect('uom', 'Unit of measure', 'Select the appropriate unit of measure from the dropdown list.', options?.unit_of_measure, 'Select a unit')}
        {idSelect('brand_id', 'Brand/manufacturer', 'Choose the Brand/Manufacturer from the dropdown.', options?.brand, 'Select a brand', current?.brand_manufacturer)}
        {text('model', 'Model (optional)', 'Input the equipment\'s Model Name or Number.')}
        {longText(
          'specifications',
          'Specifications (optional)',
          'Enter the equipment\'s detailed specifications. Refer to the Delivery Receipt (DR), Property Acknowledgment Receipt (PAR), Inventory Custodian Slip (ICS), Inspection and Acceptance Report (IAR), or other relevant documents.',
        )}
        {checkbox('non_dcp', 'Non-DCP', 'Checkbox to identify whether an equipment is DCP or Non-DCP')}
        {!value.non_dcp ? (
          <div className="grid gap-4 sm:grid-cols-2">
            {stringSelect('dcp_package', 'DCP package (optional)', 'Input the Package Name of the DCP', options?.dcp_package, 'None')}
            {text('dcp_year', 'DCP year (optional)', 'Input the Year of the DCP Package the item belongs to', { inputMode: 'numeric', placeholder: 'YYYY' })}
          </div>
        ) : (
          <div aria-hidden="true" />
        )}
        {idSelect(
          'equipment_category_id',
          'Category',
          'Select the equipment\'s Category from the dropdown. An item is considered a High-Value Equipment if it costs ₱50,000 or above, and a Low-Value Equipment if it costs below ₱50,000.',
          options?.category,
          'Select a category',
          current?.category,
        )}
        {idSelect(
          'equipment_classification_id',
          'Classification',
          'Select the appropriate Classification from the dropdown. For now, the focus is limited to Machinery and Equipment for Information and Communication Technology (ICT).',
          options?.classification,
          'Select a classification',
          current?.classification,
        )}
      </FormSection>

      <FormSection title="Acquisition">
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
        {text(
          'donor',
          'Donor (optional)',
          'If acquisition is through Donation, please put the Name of the Institution (i.e. Company, Association, Organization, Agency or LGU), else please leave it blank.',
        )}
        {stringSelect('source_fund', 'Source of funds', 'Select the corresponding source of funds from the dropdown.', options?.source_of_funds, 'Select a fund source')}
        {stringSelect('allotment_class', 'Allotment class', 'Select the corresponding Allotment Class from the dropdown.', options?.allotment_class, 'Select an allotment class')}
      </FormSection>

      <FormSection title="Warranty & supplier">
        {checkbox('under_warranty', 'Under warranty', 'Use this checkbox to mark the equipment\'s warranty status.')}
        {value.under_warranty ? (
          text('end_warranty_date', 'Warranty end date (optional)', 'Input the End of Warranty in the \'MM/DD/YYYY\' date format', { type: 'date' })
        ) : (
          <div aria-hidden="true" />
        )}
        {text('supplier', 'Supplier (optional)', 'Input the name of the Supplier / Provider or Distributor of the equipment', { className: 'sm:col-span-2' })}
      </FormSection>

      <FormSection title="Condition & location" className="lg:col-span-2">
        {text(
          'pm_plan',
          'PM plan (optional)',
          'Enter the item number as referenced in the approved PPMP document. If the item/equipment was received through donation or not directly purchased by the stakeholder, leave this field blank.',
        )}
        {checkbox('non_functional', 'Non-functional', 'Use this checkbox to mark the equipment as Functional or Non-Functional.')}
        {longText(
          'equipment_location',
          'Location (optional)',
          'Enter the location where the equipment is installed or primarily used (e.g., Access Point installed in School Annex). For movable or mobile assets, indicate the designated office, or leave this field blank if not applicable.',
        )}
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
        {longText(
          'remarks',
          'Remarks (optional)',
          'This column may include the technical assessment report, evaluation findings, recommendations, justifications, or other relevant information/documents related to the equipment. Include additional notes such as missing accessories, broken parts, non-functional features, repair needs, repair history, or if the item is unusable or unserviceable. For damaged equipment, indicate if the cause is due to natural disasters (e.g., floods, earthquakes, typhoons). The information provided here will serve as justification for any unknown or unidentified details, including document reference numbers, the accountable officer or custodian, or other information that cannot be determined by the user.',
        )}
      </FormSection>
    </div>
  )
}
