/**
 * Row shape for the offices/Index paginated table, mirrors
 * OfficeController::index()'s `->through()` projection.
 */
export type OfficeListItem = {
    id: number;
    office_name: string;
    office_type: string | null;
    school_id: number | null;
    district: string | null;
    division: string | null;
    region: string | null;
    is_active: boolean;
};

export type OfficeFilters = {
    search: string | null;
};

/**
 * All 11 fields OfficeController::edit() ships via `Office::only([...])`.
 */
export type OfficeFull = {
    id: number;
    office_name: string;
    office_type: string | null;
    school_id: number | null;
    address: string | null;
    region: string | null;
    district: string | null;
    division: string | null;
    contact_number: string | null;
    email: string | null;
    is_active: boolean;
};

/**
 * Shape handed to Inertia's useForm() for the office create/edit form.
 * Mirrors OfficeStoreRequest/OfficeUpdateRequest: `school_id` is kept as a
 * string for native <input type="number"> semantics (same convention as
 * Equipment's acquisition_cost/estimated_useful_life), even though it's
 * validated/cast as an integer server-side. `is_active` defaults true on
 * Create (mirrors OfficeController::store()'s `$data['is_active'] ??= true`).
 */
export type OfficeFormData = {
    office_name: string;
    office_type: string;
    school_id: string;
    address: string;
    region: string;
    district: string;
    division: string;
    contact_number: string;
    email: string;
    is_active: boolean;
};
