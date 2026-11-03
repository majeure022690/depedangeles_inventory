import { api } from '@/lib/api'
import type { ApiResponse, PaginatedResponse } from '@/types/api'
import type {
  CountItemUpdate,
  FoundItemInput,
  InventoryCount,
  InventoryCountFormData,
  InventoryCountFoundItem,
  InventoryCountItem,
  InventoryCountLookup,
  InventoryCountOptions,
} from '@/types/inventory-count'

function text(value: string): string | null {
  return value.trim() === '' ? null : value.trim()
}

export function defaultCountName(asOfDate: string): string {
  if (!asOfDate) return 'Physical Count'
  const date = new Date(`${asOfDate}T00:00:00`)
  return `Physical Count as of ${new Intl.DateTimeFormat('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }).format(date)}`
}

/** The count is presented as of December 31 unless the user picks another date. */
export function emptyInventoryCountForm(officeIds: number[] = [], today = new Date()): InventoryCountFormData {
  const asOf = `${today.getFullYear()}-12-31`
  return {
    name: defaultCountName(asOf),
    as_of_date: asOf,
    office_ids: officeIds,
    committee_chair_id: '',
    committee_member_ids: [],
    coa_representative: '',
    remarks: '',
  }
}

export function inventoryCountFormFrom(count: InventoryCount): InventoryCountFormData {
  return {
    name: count.name,
    as_of_date: count.as_of_date,
    office_ids: count.office_ids,
    committee_chair_id: count.committee_chair_id === null ? '' : String(count.committee_chair_id),
    committee_member_ids: count.committee_member_ids,
    coa_representative: count.coa_representative ?? '',
    remarks: count.remarks ?? '',
  }
}

function payload(form: InventoryCountFormData) {
  return {
    name: form.name.trim(),
    as_of_date: form.as_of_date,
    office_ids: form.office_ids,
    committee_chair_id: form.committee_chair_id === '' ? null : Number(form.committee_chair_id),
    committee_member_ids: form.committee_member_ids,
    coa_representative: text(form.coa_representative),
    remarks: text(form.remarks),
  }
}

export const inventoryCountService = {
  list: (params: Record<string, string | number>) => api.get<PaginatedResponse<InventoryCount>>('/inventory-counts', { params }).then((r) => r.data),
  options: (officeIds: number[] = []) =>
    api.get<ApiResponse<InventoryCountOptions>>('/inventory-counts/options', { params: { office_ids: officeIds } }).then((r) => r.data.data),
  show: (id: number) => api.get<ApiResponse<InventoryCount>>(`/inventory-counts/${id}`).then((r) => r.data.data),
  create: (form: InventoryCountFormData) => api.post<ApiResponse<InventoryCount>>('/inventory-counts', payload(form)).then((r) => r.data),
  update: (id: number, form: InventoryCountFormData) => api.put<ApiResponse<InventoryCount>>(`/inventory-counts/${id}`, payload(form)).then((r) => r.data),
  remove: (id: number) => api.delete(`/inventory-counts/${id}`),
  open: (id: number) => api.post<ApiResponse<InventoryCount>>(`/inventory-counts/${id}/open`).then((r) => r.data),
  close: (id: number) => api.post<ApiResponse<InventoryCount>>(`/inventory-counts/${id}/close`).then((r) => r.data),
  cancel: (id: number) => api.post<ApiResponse<InventoryCount>>(`/inventory-counts/${id}/cancel`).then((r) => r.data),
  items: (id: number, params: Record<string, string | number>) =>
    api.get<PaginatedResponse<InventoryCountItem>>(`/inventory-counts/${id}/items`, { params }).then((r) => r.data),
  lookup: (id: number, code: string) => api.get<ApiResponse<InventoryCountLookup>>(`/inventory-counts/${id}/lookup`, { params: { code } }).then((r) => r.data),
  updateItem: (id: number, itemId: number, update: CountItemUpdate) =>
    api.put<ApiResponse<InventoryCountItem>>(`/inventory-counts/${id}/items/${itemId}`, update).then((r) => r.data.data),
  foundItems: (id: number, params: Record<string, string | number>) =>
    api.get<PaginatedResponse<InventoryCountFoundItem>>(`/inventory-counts/${id}/found-items`, { params }).then((r) => r.data),
  addFoundItem: (id: number, input: FoundItemInput) =>
    api.post<ApiResponse<InventoryCountFoundItem>>(`/inventory-counts/${id}/found-items`, input).then((r) => r.data.data),
}
