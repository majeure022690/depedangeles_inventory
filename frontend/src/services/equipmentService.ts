import { api } from '@/lib/api'
import { lookupService } from '@/services/lookupService'
import type { ApiResponse, PaginatedResponse } from '@/types/api'
import type {
  Equipment,
  EquipmentCreatePayload,
  EquipmentFormData,
  EquipmentOptions,
  EquipmentPayload,
  EquipmentPeripheralFormData,
  EquipmentPeripheralPayload,
  EquipmentTransaction,
  EquipmentTransactionFormData,
  EquipmentTransactionPayload,
  EquipmentUpdatePayload,
} from '@/types/equipment'

/** Blank text inputs are sent as `null` so nullable columns stay null rather than ''. */
function blankToNull<T extends object>(form: T): { [K in keyof T]: T[K] | null } {
  return Object.fromEntries(Object.entries(form).map(([key, value]) => [key, value === '' ? null : value])) as {
    [K in keyof T]: T[K] | null
  }
}

export function toEquipmentPayload(form: EquipmentFormData): EquipmentPayload {
  return blankToNull(form)
}

export function toTransactionPayload(form: EquipmentTransactionFormData): EquipmentTransactionPayload {
  return blankToNull(form)
}

export function toPeripheralPayload(form: EquipmentPeripheralFormData): EquipmentPeripheralPayload {
  return blankToNull(form)
}

export const equipmentService = {
  list: (params: Record<string, string | number>) =>
    api.get<PaginatedResponse<Equipment>>('/equipment', { params }).then((r) => r.data),
  options: () => lookupService.options<EquipmentOptions>('/equipment/options'),
  show: (id: number | string) => api.get<ApiResponse<Equipment>>(`/equipment/${id}`).then((r) => r.data.data),
  create: (payload: EquipmentCreatePayload) => api.post<ApiResponse<Equipment>>('/equipment', payload).then((r) => r.data),
  update: (id: number, payload: EquipmentUpdatePayload) =>
    api.put<ApiResponse<Equipment>>(`/equipment/${id}`, payload).then((r) => r.data),
  remove: (id: number) => api.delete(`/equipment/${id}`),
  /** The QR label PNG; the caller owns (and must revoke) an object URL made from it. */
  qrCode: (id: number) => api.get<Blob>(`/equipment/${id}/qr-code`, { responseType: 'blob' }).then((r) => r.data),
  recordTransaction: (id: number, payload: EquipmentTransactionPayload) =>
    api.post<ApiResponse<EquipmentTransaction>>(`/equipment/${id}/transactions`, payload).then((r) => r.data),
  addPeripherals: (id: number, peripherals: EquipmentPeripheralPayload[]) =>
    api.post<ApiResponse<Equipment[]>>(`/equipment/${id}/peripherals/batch`, { peripherals }).then((r) => r.data),
  linkPeripheral: (id: number, equipmentId: number) =>
    api.post<ApiResponse<Equipment>>(`/equipment/${id}/peripherals/link`, { equipment_id: equipmentId }).then((r) => r.data),
  unlinkPeripheral: (id: number, peripheralId: number) => api.delete(`/equipment/${id}/peripherals/${peripheralId}`),
}
