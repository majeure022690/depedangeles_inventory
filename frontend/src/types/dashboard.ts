/**
 * GET /api/dashboard/summary. Every key is optional: the backend omits a
 * section entirely (never null) when the user lacks that resource's view
 * permission, so check presence, not truthiness of nested values.
 */
export interface DashboardCount {
  value: string
  count: number
}

export interface DashboardEquipmentSummary {
  total: number
  by_condition: DashboardCount[]
  by_category: DashboardCount[]
  by_disposition_status: DashboardCount[]
  total_acquisition_cost: number
  non_functional_count: number
  warranty_expiring_soon_count: number
}

export interface DashboardPersonnelSummary {
  active_count: number
  inactive_count: number
  oic_count: number
}

export interface DashboardIspAccountsSummary {
  total_monthly_cost: number
  contracts_expiring_soon_count: number
}

export interface DashboardRecentTransaction {
  id: number
  transaction_type: string
  equipment: { id: number; property_no: string | null; item: string | null } | null
  accountable_officer: { id: number; full_name: string } | null
  end_user: { id: number; full_name: string } | null
  recorded_by: { id: number; name: string } | null
  created_at: string | null
}

export interface DashboardSummary {
  equipment?: DashboardEquipmentSummary
  recent_transactions?: DashboardRecentTransaction[]
  personnel?: DashboardPersonnelSummary
  isp_accounts?: DashboardIspAccountsSummary
}
