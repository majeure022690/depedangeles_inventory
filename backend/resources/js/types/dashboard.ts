/**
 * Mirrors DashboardController::index()'s prop contract. Every top-level key
 * is OPTIONAL, not nullable — the backend omits a key entirely (rather than
 * sending null/an empty shape) when the current user lacks the matching
 * view permission, so callers must check key presence (`props.equipment`),
 * not just falsy values.
 */

export type DashboardCount = {
    value: string;
    count: number;
};

export type DashboardEquipmentSummary = {
    total: number;
    by_condition: DashboardCount[];
    by_category: DashboardCount[];
    by_disposition_status: DashboardCount[];
    total_acquisition_cost: number;
    non_functional_count: number;
    warranty_expiring_soon_count: number;
};

export type DashboardPersonnelSummary = {
    active_count: number;
    inactive_count: number;
    oic_count: number;
};

export type DashboardIspAccountsSummary = {
    total_monthly_cost: number;
    contracts_expiring_soon_count: number;
};

export type DashboardActivityEntity = {
    id: number;
    [key: string]: unknown;
};

export type DashboardRecentActivityEntry = {
    id: number;
    transaction_type: string;
    equipment: (DashboardActivityEntity & { property_no: string; item: string }) | null;
    accountable_officer: (DashboardActivityEntity & { full_name: string }) | null;
    end_user: (DashboardActivityEntity & { full_name: string }) | null;
    recorded_by: (DashboardActivityEntity & { name: string }) | null;
    created_at: string | null;
};
