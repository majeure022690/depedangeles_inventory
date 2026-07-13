import type { LookupOption, ReferenceOption } from '@/types/lookup';

export type IspAccountListItem = {
    id: number;
    isp: string;
    isp_billing_account_no: string;
    subscription_type: string;
    isp_connection_type: string;
    cost_per_month: number;
    contract_start_date: string | null;
    contract_end_date: string | null;
    inactive_contract: boolean;
    overall_signal_quality: string;
};

export type IspAccountFilters = {
    search: string | null;
    isp: string | null;
    status: string | null;
};

export type IspAccountFilterOptions = {
    isp_provider: ReferenceOption[];
};

export type IspAccountFormOptions = {
    isp_provider: ReferenceOption[];
    school_level_coverage: LookupOption[];
    subscription_type: LookupOption[];
    isp_connection_type: LookupOption[];
    purpose_of_subscription: LookupOption[];
    mode_of_acquisition: LookupOption[];
    source_of_acquisition: LookupOption[];
    source_of_funds: LookupOption[];
    signal_quality: LookupOption[];
};

export type IspSpeedTestFormOptions = {
    signal_quality: LookupOption[];
};

export type IspSpeedTest = {
    id: number;
    download_speed: number;
    upload_speed: number;
    ping: number | null;
    tested_at: string | null;
    signal_quality: string;
    rate_isp_service: number | null;
    created_at: string | null;
};

export type IspSubscriptionCost = {
    id: number;
    contract_start_date: string | null;
    contract_end_date: string | null;
    total_amount_spent: number | null;
    contract_projected_start_date: string | null;
    contract_projected_end_date: string | null;
    total_projected_expenditure: number | null;
    created_at: string | null;
};

/**
 * Tier 1 field (lookup-normalization ADR) ships both the FK id —
 * `isp_provider_id`, the form's source of truth, bound by Form.vue's
 * <Select> — and the related row's display name under the pre-cutover key
 * (`isp`) for read-only contexts, e.g. the Edit page heading. Never derive
 * the display name from the id client-side; always read the name the
 * backend already resolved via the `ispProvider` relationship.
 */
export type IspAccountFull = {
    id: number;
    isp: string;
    isp_provider_id: number;
    cost_per_month: number;
    isp_billing_account_no: string;
    package_purchased_inclusion: string;

    school_area_coverage: string;
    min_speed: number;
    max_speed: number;

    subscription_type: string;
    isp_connection_type: string;

    contract_start_date: string | null;
    contract_end_date: string | null;
    inactive_contract: boolean;

    purpose_of_subscription: string;

    mode_of_acquisition: string;
    source_of_acquisition: string;
    donor: string | null;
    fund_source: string;

    number_access_points_linked: number | null;
    locations_access_points: string | null;

    number_admin_area_covered: number | null;
    rate_connectivity_admin_areas: string | null;
    number_classrooms_covered: number | null;
    rate_connectivity_classroom: string | null;

    overall_signal_quality: string;
    rate_isp_service: number | null;

    speed_tests: IspSpeedTest[];
    subscription_costs: IspSubscriptionCost[];
};

/**
 * Shape of the reactive object handed to Inertia's useForm() for the ISP
 * account create/edit form. Mirrors IspAccountStoreRequest/UpdateRequest.
 * All numeric fields are kept as strings (native <input> value semantics);
 * the backend casts them back on validation.
 *
 * `isp_provider_id` (lookup-normalization ADR, Step 3 — IspAccount) is a
 * required id-typed Tier 1 field, matching what
 * IspAccountStoreRequest/UpdateRequest now validate as
 * `Rule::exists('isp_providers', 'id')`. Every other lookup-backed field is
 * unchanged, still validated strings (Tier 2).
 */
export type IspAccountFormData = {
    isp_provider_id: number;
    cost_per_month: string;
    isp_billing_account_no: string;
    package_purchased_inclusion: string;

    school_area_coverage: string;
    min_speed: string;
    max_speed: string;

    subscription_type: string;
    isp_connection_type: string;

    contract_start_date: string;
    contract_end_date: string;
    inactive_contract: boolean;

    purpose_of_subscription: string;

    mode_of_acquisition: string;
    source_of_acquisition: string;
    donor: string;
    fund_source: string;

    number_access_points_linked: string;
    locations_access_points: string;

    number_admin_area_covered: string;
    rate_connectivity_admin_areas: string;
    number_classrooms_covered: string;
    rate_connectivity_classroom: string;

    overall_signal_quality: string;
    rate_isp_service: string;
};

/**
 * Shape of the reactive object handed to Inertia's useForm() for the
 * speed-test-logging mini-form embedded on isp-accounts/Edit. Mirrors
 * IspSpeedTestStoreRequest.
 */
export type IspSpeedTestFormData = {
    download_speed: string;
    upload_speed: string;
    ping: string;
    tested_at: string;
    signal_quality: string;
    rate_isp_service: string;
};

/**
 * Shape of the reactive object handed to Inertia's useForm() for the
 * subscription-cost-logging mini-form embedded on isp-accounts/Edit.
 * Mirrors IspSubscriptionCostStoreRequest.
 */
export type IspSubscriptionCostFormData = {
    contract_start_date: string;
    contract_end_date: string;
    total_amount_spent: string;
    contract_projected_start_date: string;
    contract_projected_end_date: string;
    total_projected_expenditure: string;
};
