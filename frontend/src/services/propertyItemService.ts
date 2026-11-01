import { api } from '@/lib/api'
import { downloadFile } from '@/lib/download'
import type { ApiResponse, PaginatedResponse } from '@/types/api'
import type { PropertyItem, PropertyItemFilters, PropertyItemFormData, PropertyItemOptions } from '@/types/property-item'

function text(value: string): string | null {
  return value.trim() === '' ? null : value.trim()
}

/** Empty -> null; anything non-numeric is passed through so the server's 422 message explains it. */
function numeric(value: string): number | string | null {
  if (value.trim() === '') return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : value
}

function id(value: string): number | null {
  return value === '' ? null : Number(value)
}

export function emptyPropertyItemForm(officeId = ''): PropertyItemFormData {
  return {
    office_id: officeId,
    category: '',
    article: '',
    description: '',
    property_no: '',
    unit_of_measure: '',
    date_acquired: '',
    unit_value: '',
    quantity: '1',
    on_hand_count: '1',
    grade_level: '',
    source: '',
    condition: 'functional',
    with_are: false,
    accountable_officer_id: '',
    end_user_id: '',
    location: '',
    remarks: '',
  }
}

export function propertyItemFormFrom(item: PropertyItem): PropertyItemFormData {
  return {
    office_id: String(item.office_id),
    category: item.category,
    article: item.article,
    description: item.description ?? '',
    property_no: item.property_no ?? '',
    unit_of_measure: item.unit_of_measure ?? '',
    date_acquired: item.date_acquired ?? '',
    unit_value: item.unit_value === null ? '' : String(item.unit_value),
    quantity: String(item.quantity),
    on_hand_count: String(item.on_hand_count),
    grade_level: item.grade_level ?? '',
    source: item.source ?? '',
    condition: item.condition ?? '',
    with_are: item.with_are,
    accountable_officer_id: item.accountable_officer_id === null ? '' : String(item.accountable_officer_id),
    end_user_id: item.end_user_id === null ? '' : String(item.end_user_id),
    location: item.location ?? '',
    remarks: item.remarks ?? '',
  }
}

function payload(form: PropertyItemFormData) {
  return {
    office_id: id(form.office_id),
    category: form.category,
    article: form.article.trim(),
    description: text(form.description),
    property_no: text(form.property_no),
    unit_of_measure: text(form.unit_of_measure),
    date_acquired: text(form.date_acquired),
    unit_value: numeric(form.unit_value),
    quantity: numeric(form.quantity),
    on_hand_count: numeric(form.on_hand_count),
    grade_level: text(form.grade_level),
    source: text(form.source),
    condition: text(form.condition),
    with_are: form.with_are,
    accountable_officer_id: id(form.accountable_officer_id),
    end_user_id: id(form.end_user_id),
    location: text(form.location),
    remarks: text(form.remarks),
  }
}

export const propertyItemService = {
  list: (params: Record<string, string | number>) => api.get<PaginatedResponse<PropertyItem>>('/property-items', { params }).then((r) => r.data),
  options: (officeId?: number) =>
    api
      .get<ApiResponse<PropertyItemOptions>>('/property-items/options', {
        params: officeId ? { office_id: officeId } : {},
      })
      .then((r) => r.data.data),
  show: (itemId: number) => api.get<ApiResponse<PropertyItem>>(`/property-items/${itemId}`).then((r) => r.data.data),
  create: (form: PropertyItemFormData) => api.post<ApiResponse<PropertyItem>>('/property-items', payload(form)).then((r) => r.data),
  update: (itemId: number, form: PropertyItemFormData) => api.put<ApiResponse<PropertyItem>>(`/property-items/${itemId}`, payload(form)).then((r) => r.data),
  remove: (itemId: number) => api.delete(`/property-items/${itemId}`),
  export: (filters: PropertyItemFilters) => downloadFile('/property-items/export', filters, 'School_Property.xlsx'),
}
