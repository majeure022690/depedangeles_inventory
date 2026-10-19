import type { LookupOption, ReferenceOption } from '@/types/lookup';

export type PersonnelListItem = {
    id: number;
    employee_id: string;
    full_name: string;
    last_name: string;
    first_name: string;
    middle_name: string | null;
    suffix: string | null;
    position: string | null;
    division_unit: string | null;
    ro_division: string | null;
    mobile_1: string | null;
    deped_email: string | null;
    oic: boolean;
    oic_office: string | null;
    inactive: boolean;
};

/**
 * Tier 1 fields (lookup-normalization ADR) ship both the FK id — the
 * form's source of truth, bound by Form.vue's <Select> components — and
 * the related row's display name under the pre-cutover key (`position`,
 * `ro_division`, `division_unit`) for read-only contexts. Never derive the
 * display name from the id client-side; always read the name the backend
 * already resolved via the relationship. `fund_source` now carries an
 * array of `personnel_libraries` row ids, not value strings.
 */
export type PersonnelFull = {
    id: number;
    employee_id: string;
    last_name: string;
    first_name: string;
    middle_name: string | null;
    suffix: string | null;
    full_name: string;
    ro_division: string | null;
    ro_office_id: number | null;
    division_unit: string | null;
    sdo_office_id: number | null;
    position: string | null;
    position_id: number | null;
    oic: boolean;
    oic_office: string | null;
    mobile_1: string | null;
    mobile_2: string | null;
    deped_email: string | null;
    personal_email: string | null;
    date_hired: string | null;
    non_deped_funded: boolean;
    fund_source: number[] | null;
    inactive: boolean;
    separation_date: string | null;
    separation_cause: string | null;
    transferred_from: string | null;
    transferred_to: string | null;
};

/**
 * `position`/`ro_office`/`sdo_office` (Tier 1) ship id-valued
 * `{value: id, label: name}` options — note the singular keys, matching
 * PersonnelController::TIER1_FORM_MODELS. `name_suffix`/`cause_of_separation`
 * (Tier 2) are unchanged, string-valued. `teachers_funding_source` is the
 * one Tier 2 exception (ADR Question 4): id-valued options backing the
 * `fund_source` JSON-array field, built via `libraryOptionsById()` rather
 * than the usual string-valued Tier 2 shape.
 */
export type PersonnelFormOptions = {
    position: ReferenceOption[];
    name_suffix: LookupOption[];
    ro_office: ReferenceOption[];
    sdo_office: ReferenceOption[];
    cause_of_separation: LookupOption[];
    teachers_funding_source: ReferenceOption[];
};

export type PersonnelStatusFilter = 'active' | 'inactive' | 'all';

export type PersonnelFilters = {
    search: string | null;
    status: PersonnelStatusFilter;
};

/**
 * Shape of the reactive object handed to Inertia's useForm() for the
 * personnel create/edit form. Mirrors PersonnelStoreRequest/UpdateRequest.
 *
 * Tier 1 fields (lookup-normalization ADR, Step 3 — Personnel) are
 * nullable id-typed (`position_id`, `ro_office_id`, `sdo_office_id`),
 * matching what PersonnelStoreRequest/UpdateRequest now validate as
 * `Rule::exists($table, 'id')`. `fund_source` is a JSON array of
 * `personnel_libraries` row ids (`number[]`), not value strings. `suffix`/
 * `separation_cause` (Tier 2) are unchanged, still validated strings.
 */
export type PersonnelFormData = {
    employee_id: string;
    last_name: string;
    first_name: string;
    middle_name: string;
    suffix: string;
    ro_office_id: number | null;
    sdo_office_id: number | null;
    position_id: number | null;
    oic: boolean;
    oic_office: string;
    mobile_1: string;
    mobile_2: string;
    deped_email: string;
    personal_email: string;
    date_hired: string;
    non_deped_funded: boolean;
    fund_source: number[];
    inactive: boolean;
    separation_date: string;
    separation_cause: string;
    transferred_from: string;
    transferred_to: string;
};
