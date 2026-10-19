import type { LookupOption, ReferenceOption, ScopedReferenceOption } from '@/types/lookup';

/**
 * One row of the stakeholder-profiles.index admin list (StakeholderProfile
 * is one-per-office, not a global singleton) — Office-centric, not
 * StakeholderProfile-row-centric, since most offices won't have a profile
 * row yet (lazily created on first edit).
 */
export type StakeholderProfileOfficeListItem = {
    id: number;
    office_name: string;
    office_type: string | null;
    school_id: number | null;
    has_profile: boolean;
    updated_at: string | null;
};

export type StakeholderProfileFilters = {
    search: string | null;
};

/**
 * Shape of the `stakeholderProfile` Inertia prop from
 * StakeholderProfileController::edit()/update() — mirrors
 * StakeholderProfile's singleton row. `province_id`/`municipality_id`/
 * `barangay_id` are real FKs into the PSGC reference tables. `complete_address`
 * is a PHP accessor built from those relations: always present, always a
 * string (never writable — see StakeholderProfileUpdateRequest, which
 * omits it).
 */
export type StakeholderProfileFull = {
    id: number;

    governance_level: string | null;
    ro: string | null;
    sdo: string | null;

    school_district: string | null;
    school_name: string | null;
    school_id: string | null;

    province_id: number | null;
    municipality_id: number | null;
    legislative_district: string | null;
    barangay_id: number | null;
    street: string | null;
    complete_address: string;

    notes_corrections: string | null;
    notes_recent_development: string | null;

    mobile_1: string | null;
    mobile_2: string | null;
    landline: string | null;

    chief_name: string | null;
    chief_position: string | null;
    chief_email: string | null;
    chief_mobile: string | null;

    admin_staff_name: string | null;
    admin_staff_position: string | null;
    admin_staff_email: string | null;
    admin_staff_mobile: string | null;

    network_administrator_name: string | null;

    longitude: number | null;
    latitude: number | null;

    nearby_institutions: number[] | null;
    nearby_institutions_other: string | null;

    travel_time_to_center_minutes: number | null;

    access_paths: number[] | null;

    transportation_options: number[] | null;
    transportation_other: string | null;

    transportation_difficult: boolean | null;
    considered_very_remote: boolean | null;
    remote_context_notes: string | null;

    gidca: boolean | null;
    lms: boolean | null;

    community_engagement: number[] | null;
    community_context_remarks: string | null;

    submitted_at: string | null;
    transaction_type: string | null;
};

/**
 * Option lists for the form's select/checkbox-group fields, keyed by
 * StakeholderProfileController::FORM_LOOKUP_TYPES. `governance_level` and
 * `transaction_type` stay string-valued (`LookupOption[]`) — those two
 * fields remain plain validated strings post lookup-normalization. The
 * next four are `stakeholder_libraries` row ids (`ReferenceOption[]`,
 * ADR Question 4) backing the JSON-array checkbox-group fields.
 * `province`/`municipality`/`barangay` are the full Region III PSGC
 * hierarchy — `municipality`/`barangay` carry `parent_id` so the form can
 * filter them client-side into cascading dropdowns.
 */
export type StakeholderProfileFormOptions = {
    governance_level: LookupOption[];
    nearby_institution: ReferenceOption[];
    type_of_access_road: ReferenceOption[];
    by_transportation: ReferenceOption[];
    community_engagement: ReferenceOption[];
    transaction_type: LookupOption[];
    province: ReferenceOption[];
    municipality: ScopedReferenceOption[];
    barangay: ScopedReferenceOption[];
};

/**
 * Shape of the reactive object handed to Inertia's useForm() for the
 * Stakeholder Profile edit form. Mirrors every rule in
 * StakeholderProfileUpdateRequest — `complete_address` is intentionally
 * absent (read-only, never submitted). Most numeric fields are kept as
 * strings (native <input> value semantics), same convention as
 * IspAccountFormData; the backend casts them back on validation. The
 * three PSGC FK fields (`province_id`/`municipality_id`/`barangay_id`)
 * are the exception — `number | null`, same convention as Personnel's
 * `position_id`/`ro_office_id`/`sdo_office_id`, since they're driven by
 * cascading `<Select>`s, not text inputs. Nullable booleans are coerced
 * to `false` for the checkbox UI — there is no tri-state checkbox in this
 * codebase, so an unanswered field and an explicit "No" are not
 * distinguished once the form has been saved once.
 */
export type StakeholderProfileFormData = {
    governance_level: string;
    ro: string;
    sdo: string;

    school_district: string;
    school_name: string;
    school_id: string;

    province_id: number | null;
    municipality_id: number | null;
    legislative_district: string;
    barangay_id: number | null;
    street: string;

    notes_corrections: string;
    notes_recent_development: string;

    mobile_1: string;
    mobile_2: string;
    landline: string;

    chief_name: string;
    chief_position: string;
    chief_email: string;
    chief_mobile: string;

    admin_staff_name: string;
    admin_staff_position: string;
    admin_staff_email: string;
    admin_staff_mobile: string;

    network_administrator_name: string;

    longitude: string;
    latitude: string;

    nearby_institutions: number[];
    nearby_institutions_other: string;

    travel_time_to_center_minutes: string;

    access_paths: number[];

    transportation_options: number[];
    transportation_other: string;

    transportation_difficult: boolean;
    considered_very_remote: boolean;
    remote_context_notes: string;

    gidca: boolean;
    lms: boolean;

    community_engagement: number[];
    community_context_remarks: string;

    submitted_at: string;
    transaction_type: string;
};
