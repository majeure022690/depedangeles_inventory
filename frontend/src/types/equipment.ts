import type { Option } from '@/types/lookup'

export interface PersonnelSummary {
  id: number
  full_name: string | null
}

export interface EquipmentTransaction {
  id: number
  equipment_id: number
  transaction_type: string
  accountable_officer: PersonnelSummary | null
  end_user: PersonnelSummary | null
  received_by: PersonnelSummary | null
  date_assigned_accountable_officer: string | null
  date_assigned_end_user: string | null
  date_received_new_accountable: string | null
  supporting_documents1: string | null
  or_si_dr_iar_no: string | null
  supporting_documents2: string | null
  par_ics_rrsp_rs_wmr_no: string | null
  created_at: string | null
}

export interface EquipmentParentSummary {
  id: number
  property_no: string | null
}

/** A Monitor, Keyboard or Mouse bundled with a desktop, as listed under the desktop's `children`. */
export interface EquipmentPeripheral {
  id: number
  property_no: string | null
  item: string | null
  serial_number: string | null
  brand_manufacturer: string | null
  model: string | null
  equipment_condition: string | null
}

/** One row of `peripherals` on create or the batch add; a `null` brand or condition takes the desktop's. */
export interface EquipmentPeripheralFormData {
  item_type_id: number | null
  serial_number: string
  brand_id: number | null
  model: string
  specifications: string
  equipment_condition_id: number | null
  acquisition_cost: string
  remarks: string
}

export type EquipmentPeripheralPayload = { [K in keyof EquipmentPeripheralFormData]: EquipmentPeripheralFormData[K] | null }

/**
 * `EquipmentResource`. Tier 1 fields ship the FK id the form binds to
 * (`item_type_id`) and the resolved display name under the historical key
 * (`item`); never derive the name from the id client-side.
 * `parent` (the current bundle) and `children` are present on the create, detail and update endpoints;
 * `transactions` on detail only; `original_bundle` (the desktop whose number this one extends) on detail and update.
 */
export interface Equipment {
  id: number
  property_no: string | null
  old_property_no: string | null
  serial_number: string | null
  item_type_id: number
  item: string | null
  uom: string
  brand_id: number
  brand_manufacturer: string | null
  model: string | null
  specifications: string | null
  non_dcp: boolean
  dcp_package: string | null
  dcp_year: number | null
  equipment_category_id: number
  category: string | null
  equipment_classification_id: number
  classification: string | null
  gl_sl_code: string | null
  uacs: string | null
  acquisition_cost: number | null
  received_date: string | null
  estimated_useful_life: number | null
  mode_acquisition: string
  source_acquisition: string
  donor: string | null
  source_fund: string
  allotment_class: string
  pm_plan: string | null
  equipment_location: string | null
  non_functional: boolean
  equipment_condition_id: number
  equipment_condition: string | null
  disposition_status: string
  remarks: string | null
  qr_code: string | null
  under_warranty: boolean
  warranty_active: boolean | null
  end_warranty_date: string | null
  supplier: string | null
  used_by: string | null
  current_accountable_officer: PersonnelSummary | null
  current_end_user: PersonnelSummary | null
  dashboard_group: string | null
  parent_equipment_id: number | null
  parent?: EquipmentParentSummary | null
  children?: EquipmentPeripheral[]
  original_bundle?: EquipmentParentSummary | null
  transactions?: EquipmentTransaction[]
  activities?: EquipmentActivity[]
  attachments?: EquipmentAttachment[]
  created_at: string | null
  updated_at: string | null
}

export interface EquipmentActivity {
  id: number
  description: string
  properties: {
    attributes?: Record<string, unknown>
    old?: Record<string, unknown>
    // Direct properties for peripheral events (equipment.peripheral.linked/unlinked/created)
    // On parent equipment: shows which peripheral was linked/unlinked
    peripheral_id?: number
    peripheral_property_no?: string
    // On peripheral equipment: shows which parent it was linked to/unlinked from
    parent_equipment_id?: number
    parent_property_no?: string
  }
  causer: { id: number; name: string } | null
  created_at: string
}

/** A scanned document or photo kept on an equipment record; downloaded through the API only. */
export interface EquipmentAttachment {
  id: number
  document_type: string
  document_no: string | null
  original_filename: string
  mime_type: string
  file_size: number
  uploaded_by?: string | null
  created_at: string | null
}

export interface EquipmentFormOptions {
  item_type: Option<number>[]
  brand: Option<number>[]
  category: Option<number>[]
  classification: Option<number>[]
  condition: Option<number>[]
  unit_of_measure: Option<string>[]
  dcp_package: Option<string>[]
  mode_of_acquisition: Option<string>[]
  source_of_acquisition: Option<string>[]
  source_of_funds: Option<string>[]
  allotment_class: Option<string>[]
  disposition_status: Option<string>[]
  used_by: Option<string>[]
}

export interface EquipmentFilterOptions {
  condition: Option<number>[]
  category: Option<number>[]
  disposition_status: Option<string>[]
}

export interface EquipmentTransactionOptions {
  transaction_type: Option<string>[]
  supporting_document_type: Option<string>[]
}

export interface ClassificationDefaults {
  default_uacs: string | null
  default_useful_life: number | null
}

export interface EquipmentCategoryRule {
  category: string
  gl_sl_code: string
}

/** An acquisition cost below `threshold` is `low`, at or above it `high`. */
export interface EquipmentCategoryRules {
  threshold: number
  low: EquipmentCategoryRule
  high: EquipmentCategoryRule
}

/** `GET /api/equipment/options`. `personnel` is only sent to `equipment.transactions.create` holders. */
export interface EquipmentOptions {
  form: EquipmentFormOptions
  filters: EquipmentFilterOptions
  transaction: EquipmentTransactionOptions
  classification_defaults: Record<string, ClassificationDefaults>
  donor_sources: string[]
  category_rules: EquipmentCategoryRules
  attachment_document_types: string[]
  personnel?: Option<number>[]
}

/**
 * Form state for create/edit, a subset of Store/UpdateEquipmentRequest.
 * Tier 1 ids use `null` for "not chosen yet"; numeric text inputs stay
 * strings while editing and are converted by `toEquipmentPayload`. Category
 * and GL/SL code are derived server-side from the acquisition cost, the
 * property number is generated server-side and read-only; current_*
 * pointers and qr_code only change through a recorded transaction.
 */
export interface EquipmentFormData {
  old_property_no: string
  serial_number: string
  item_type_id: number | null
  uom: string
  brand_id: number | null
  model: string
  specifications: string
  non_dcp: boolean
  dcp_package: string
  dcp_year: string
  equipment_classification_id: number | null
  uacs: string
  acquisition_cost: string
  received_date: string
  estimated_useful_life: string
  mode_acquisition: string
  source_acquisition: string
  donor: string
  source_fund: string
  allotment_class: string
  pm_plan: string
  equipment_location: string
  non_functional: boolean
  equipment_condition_id: number | null
  disposition_status: string
  remarks: string
  under_warranty: boolean
  end_warranty_date: string
  supplier: string
  used_by: string
}

/** Mirrors StoreEquipmentTransactionRequest; personnel ids are `null` when not chosen. */
export interface EquipmentTransactionFormData {
  transaction_type: string
  accountable_officer_id: number | null
  end_user_id: number | null
  received_by_id: number | null
  date_assigned_accountable_officer: string
  date_assigned_end_user: string
  date_received_new_accountable: string
  supporting_documents1: string
  or_si_dr_iar_no: string
  supporting_documents2: string
  par_ics_rrsp_rs_wmr_no: string
}

export type EquipmentPayload = { [K in keyof EquipmentFormData]: EquipmentFormData[K] | null }

export type EquipmentTransactionPayload = { [K in keyof EquipmentTransactionFormData]: EquipmentTransactionFormData[K] | null }

export type EquipmentCreatePayload = EquipmentPayload & {
  accountability: EquipmentTransactionPayload
  peripherals?: EquipmentPeripheralPayload[]
}

export type EquipmentUpdatePayload = EquipmentPayload
