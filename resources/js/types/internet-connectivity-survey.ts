import type { LookupOption, ReferenceOption } from '@/types/lookup';

/** One row of the internet-connectivity-surveys.index admin list. */
export type InternetConnectivitySurveyOfficeListItem = {
    id: number;
    office_name: string;
    office_type: string | null;
    school_id: number | null;
    has_survey: boolean;
    updated_at: string | null;
};

export type InternetConnectivitySurveyFilters = {
    search: string | null;
};

/** Shape of the `internetConnectivitySurvey` Inertia prop. */
export type InternetConnectivitySurveyFull = {
    id: number;

    has_isp_in_area: boolean | null;

    available_isps: number[] | null;
    available_isps_other: string | null;

    mobile_signal_types: number[] | null;

    has_mobile_data_connectivity: boolean | null;
    mobile_data_quality: string | null;

    subscribes_to_isp: boolean | null;
    subscribed_isps: number[] | null;

    insufficient_bandwidth_explanation: string | null;

    coverage_areas: number[] | null;
    coverage_areas_other: string | null;

    dict_free_wifi_recipient: boolean | null;
    dict_free_wifi_rating: number | null;

    has_sufficient_bandwidth: boolean | null;
    no_subscription_reason: string | null;

    has_electricity_source: boolean | null;
    electricity_sources: number[] | null;
    primarily_solar_powered: boolean | null;
    frequent_brownouts: boolean | null;

    rooms_other_use: number | null;
};

/**
 * Option lists for the form's select/checkbox-group fields, keyed by
 * InternetConnectivitySurveyController::FORM_LOOKUP_TYPES. `isp_provider`
 * is shared by both `available_isps` and `subscribed_isps`. `signal_quality`
 * (backing the scalar `mobile_data_quality` Select) stays string-valued
 * (`LookupOption[]`) — that field remains a plain validated string post
 * lookup-normalization. The other four are id-valued (`ReferenceOption[]`,
 * ADR Question 4) backing the JSON-array checkbox-group fields —
 * `isp_provider` is Tier 1 (`isp_providers` FK table), the rest are Tier 2
 * `connectivity_libraries`/`stakeholder_libraries` row ids.
 */
export type InternetConnectivitySurveyFormOptions = {
    isp_provider: ReferenceOption[];
    mobile_network_signal: ReferenceOption[];
    signal_quality: LookupOption[];
    coverage_area: ReferenceOption[];
    source_of_electricity: ReferenceOption[];
};

/**
 * Shape of the reactive object handed to Inertia's useForm() for the
 * Internet Connectivity Survey edit form. Mirrors every rule in
 * InternetConnectivitySurveyUpdateRequest. Numeric fields are kept as
 * strings, same convention as IspAccountFormData. Nullable booleans are
 * coerced to `false` for the checkbox UI, same trade-off documented on
 * StakeholderProfileFormData.
 */
export type InternetConnectivitySurveyFormData = {
    has_isp_in_area: boolean;

    available_isps: number[];
    available_isps_other: string;

    mobile_signal_types: number[];

    has_mobile_data_connectivity: boolean;
    mobile_data_quality: string;

    subscribes_to_isp: boolean;
    subscribed_isps: number[];

    insufficient_bandwidth_explanation: string;

    coverage_areas: number[];
    coverage_areas_other: string;

    dict_free_wifi_recipient: boolean;
    dict_free_wifi_rating: string;

    has_sufficient_bandwidth: boolean;
    no_subscription_reason: string;

    has_electricity_source: boolean;
    electricity_sources: number[];
    primarily_solar_powered: boolean;
    frequent_brownouts: boolean;

    rooms_other_use: string;
};
