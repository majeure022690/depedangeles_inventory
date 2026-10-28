/**
 * GET /api/dashboard/summary. Every key is optional: the backend omits a
 * section entirely (never null) when the user lacks its dashboard permission,
 * so check presence, not truthiness of nested values.
 */
export interface DashboardPropertyValue {
  total_value: number
  item_count: number
}

export interface DashboardEquipmentBreakdown {
  label: string
  count: number
  item_type_ids: number[]
}

export interface DashboardEquipmentGroup {
  key: string
  label: string
  count: number
  breakdown: DashboardEquipmentBreakdown[]
}

export interface DashboardEquipmentOverview {
  groups: DashboardEquipmentGroup[]
  other: { count: number }
}

export interface DashboardDistributionSlice {
  key: string
  label: string
  count: number
  percentage: number
}

export interface DashboardEquipmentDistribution {
  total: number
  slices: DashboardDistributionSlice[]
}

export interface DashboardProviderCount {
  name: string
  count: number
  percentage: number
}

export interface DashboardConnectionTypeCount {
  value: string
  count: number
  percentage: number
}

export interface DashboardInternetConnectivity {
  total_accounts: number
  total_cost: number
  by_provider: DashboardProviderCount[]
  by_connection_type: DashboardConnectionTypeCount[]
}

export interface DashboardSummary {
  ppe?: DashboardPropertyValue
  semi_expendable?: DashboardPropertyValue
  equipment_overview?: DashboardEquipmentOverview
  equipment_distribution?: DashboardEquipmentDistribution
  internet_connectivity?: DashboardInternetConnectivity
}
