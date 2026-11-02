import { api } from '@/lib/api'
import type { ApiResponse, PaginatedResponse } from '@/types/api'
import type { PropertyItemEquipment, SchoolPropertyFilters, SchoolPropertyOptions, SchoolPropertySummary } from '@/types/property-item'

function params(filters: Partial<SchoolPropertyFilters>): Record<string, string> {
  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '' && value !== undefined)) as Record<string, string>
}

export const propertyItemService = {
  options: () => api.get<ApiResponse<SchoolPropertyOptions>>('/property-items/options').then((r) => r.data.data),
  summary: (filters: SchoolPropertyFilters) =>
    api.get<ApiResponse<SchoolPropertySummary>>('/property-items/summary', { params: params(filters) }).then((r) => r.data.data),
  equipment: (query: Record<string, string | number>) =>
    api.get<PaginatedResponse<PropertyItemEquipment>>('/property-items/equipment', { params: query }).then((r) => r.data),
}
