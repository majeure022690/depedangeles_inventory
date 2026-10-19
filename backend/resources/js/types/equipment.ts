import type { LookupOption, ReferenceOption } from '@/types/lookup';

export type PersonnelSummary = {
    id: number;
    full_name: string;
};

export type EquipmentListItem = {
    id: number;
    property_no: string | null;
    item: string;
    brand_manufacturer: string;
    model: string | null;
    serial_number: string | null;
    category: string;
    equipment_condition: string;
    disposition_status: string;
    acquisition_cost: number | null;
    current_accountable_officer: PersonnelSummary | null;
    current_end_user: PersonnelSummary | null;
};

export type EquipmentTransaction = {
    id: number;
    transaction_type: string;
    accountable_officer: PersonnelSummary | null;
    end_user: PersonnelSummary | null;
    received_by: PersonnelSummary | null;
    date_assigned_accountable_officer: string | null;
    date_assigned_end_user: string | null;
    date_received_new_accountable: string | null;
    supporting_documents1: string | null;
    or_si_dr_iar_no: string | null;
    supporting_documents2: string | null;
    par_ics_rrsp_rs_wmr_no: string | null;
    created_at: string | null;
};

/**
 * Tier 1 fields (lookup-normalization ADR) ship both the FK id — the form's
 * source of truth, bound by Form.vue's <Select> components — and the
 * related row's display name under the pre-cutover key (`item`,
 * `brand_manufacturer`, `category`, `classification`, `equipment_condition`)
 * for read-only contexts, e.g. the Edit page heading. Never derive the
 * display name from the id client-side; always read the name the backend
 * already resolved via the relationship.
 */
export type EquipmentFull = {
    id: number;
    property_no: string | null;
    old_property_no: string | null;
    serial_number: string | null;
    item: string;
    item_type_id: number;
    uom: string;
    brand_manufacturer: string;
    brand_id: number;
    model: string | null;
    specifications: string | null;
    non_dcp: boolean;
    dcp_package: string | null;
    dcp_year: number | null;
    category: string;
    equipment_category_id: number;
    classification: string;
    equipment_classification_id: number;
    gl_sl_code: string | null;
    uacs: string | null;
    acquisition_cost: number | null;
    received_date: string | null;
    estimated_useful_life: number | null;
    mode_acquisition: string;
    source_acquisition: string;
    donor: string | null;
    source_fund: string;
    allotment_class: string;
    pm_plan: string | null;
    equipment_location: string | null;
    non_functional: boolean;
    equipment_condition: string;
    equipment_condition_id: number;
    disposition_status: string;
    remarks: string | null;
    qr_code: string | null;
    under_warranty: boolean;
    end_warranty_date: string | null;
    supplier: string | null;
    current_accountable_officer: PersonnelSummary | null;
    current_end_user: PersonnelSummary | null;
    transactions: EquipmentTransaction[];
};

export type EquipmentFormOptions = {
    item_type: ReferenceOption[];
    unit_of_measure: LookupOption[];
    brand: ReferenceOption[];
    dcp_package: LookupOption[];
    category: ReferenceOption[];
    classification: ReferenceOption[];
    mode_of_acquisition: LookupOption[];
    source_of_acquisition: LookupOption[];
    source_of_funds: LookupOption[];
    allotment_class: LookupOption[];
    condition: ReferenceOption[];
    disposition_status: LookupOption[];
};

export type EquipmentFilterOptions = {
    condition: ReferenceOption[];
    category: ReferenceOption[];
    disposition_status: LookupOption[];
};

export type EquipmentTransactionFormOptions = {
    transaction_type: LookupOption[];
    supporting_document_type: LookupOption[];
};

export type EquipmentFilters = {
    search: string | null;
    condition: string | null;
    category: string | null;
    disposition_status: string | null;
};

/**
 * Shape of the reactive object handed to Inertia's useForm() for the
 * equipment create/edit form. Mirrors EquipmentStoreRequest/UpdateRequest.
 * Deliberately excludes current_accountable_officer_id/current_end_user_id
 * and qr_code — see EquipmentStoreRequest's doc-comment.
 *
 * Tier 1 fields (lookup-normalization ADR, Step 3 — Equipment only) are
 * id-typed (`item_type_id`, `brand_id`, `equipment_category_id`,
 * `equipment_classification_id`, `equipment_condition_id`), matching what
 * EquipmentStoreRequest/UpdateRequest now validate as
 * `Rule::exists($table, 'id')`. Tier 2 fields (uom, dcp_package,
 * mode_acquisition, source_acquisition, source_fund, allotment_class,
 * disposition_status) are unchanged, still validated strings.
 */
export type EquipmentFormData = {
    property_no: string;
    old_property_no: string;
    serial_number: string;
    item_type_id: number;
    uom: string;
    brand_id: number;
    model: string;
    specifications: string;
    non_dcp: boolean;
    dcp_package: string;
    dcp_year: string;
    equipment_category_id: number;
    equipment_classification_id: number;
    gl_sl_code: string;
    uacs: string;
    acquisition_cost: string;
    received_date: string;
    estimated_useful_life: string;
    mode_acquisition: string;
    source_acquisition: string;
    donor: string;
    source_fund: string;
    allotment_class: string;
    pm_plan: string;
    equipment_location: string;
    non_functional: boolean;
    equipment_condition_id: number;
    disposition_status: string;
    remarks: string;
    under_warranty: boolean;
    end_warranty_date: string;
    supplier: string;
};

/**
 * Shape of the reactive object handed to Inertia's useForm() for the
 * transaction-logging mini-form embedded on equipment/Edit. Mirrors
 * EquipmentTransactionStoreRequest.
 */
export type EquipmentTransactionFormData = {
    transaction_type: string;
    accountable_officer_id: string;
    end_user_id: string;
    received_by_id: string;
    date_assigned_accountable_officer: string;
    date_assigned_end_user: string;
    date_received_new_accountable: string;
    supporting_documents1: string;
    or_si_dr_iar_no: string;
    supporting_documents2: string;
    par_ics_rrsp_rs_wmr_no: string;
};
