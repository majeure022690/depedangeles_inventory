import { api } from '@/lib/api'
import { lookupService } from '@/services/lookupService'
import type { ApiResponse, PaginatedResponse } from '@/types/api'
import type {
  ProfileOffice,
  StakeholderProfile,
  StakeholderProfileEnvelope,
  StakeholderProfileFormData,
  StakeholderProfileOfficeRow,
  StakeholderProfileOptions,
} from '@/types/stakeholder-profile'

const OPTIONS_URL = '/stakeholder-profiles/options'

function text(value: string): string | null {
  return value.trim() === '' ? null : value
}

/** Empty -> null; anything non-numeric is passed through so the server's 422 message explains it. */
function numeric(value: string): number | string | null {
  if (value.trim() === '') return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : value
}

const pad = (n: number) => String(n).padStart(2, '0')

/** ISO-8601 timestamp -> `datetime-local` value in the browser's time zone. */
export function toDatetimeLocal(value: string | null): string {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

/** `datetime-local` (browser time) -> ISO-8601 UTC; the API converts it to its own timezone. */
function fromDatetimeLocal(value: string): string | null {
  if (!value) return null
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toISOString()
}

const ids = (value: (number | string)[] | null | undefined) => (value ?? []).map(Number).filter(Number.isFinite)

/**
 * School name and ID fall back to the office the profile belongs to (it's
 * already known from the URL) but stay plain editable strings.
 */
export function stakeholderProfileFormFrom(profile: StakeholderProfile, office: ProfileOffice): StakeholderProfileFormData {
  return {
    governance_level: profile.governance_level ?? '',
    ro: profile.ro ?? '',
    sdo: profile.sdo ?? '',
    school_district: profile.school_district ?? '',
    school_name: profile.school_name ?? office.office_name,
    school_id: profile.school_id ?? (office.school_id !== null ? String(office.school_id) : ''),
    province_id: profile.province_id,
    municipality_id: profile.municipality_id,
    legislative_district: profile.legislative_district ?? '',
    barangay_id: profile.barangay_id,
    street: profile.street ?? '',
    notes_corrections: profile.notes_corrections ?? '',
    notes_recent_development: profile.notes_recent_development ?? '',
    mobile_1: profile.mobile_1 ?? '',
    mobile_2: profile.mobile_2 ?? '',
    landline: profile.landline ?? '',
    chief_name: profile.chief_name ?? '',
    chief_position: profile.chief_position ?? '',
    chief_email: profile.chief_email ?? '',
    chief_mobile: profile.chief_mobile ?? '',
    admin_staff_name: profile.admin_staff_name ?? '',
    admin_staff_position: profile.admin_staff_position ?? '',
    admin_staff_email: profile.admin_staff_email ?? '',
    admin_staff_mobile: profile.admin_staff_mobile ?? '',
    network_administrator_name: profile.network_administrator_name ?? '',
    longitude: profile.longitude !== null ? String(profile.longitude) : '',
    latitude: profile.latitude !== null ? String(profile.latitude) : '',
    nearby_institutions: ids(profile.nearby_institutions),
    nearby_institutions_other: profile.nearby_institutions_other ?? '',
    travel_time_to_center_minutes: profile.travel_time_to_center_minutes !== null ? String(profile.travel_time_to_center_minutes) : '',
    access_paths: ids(profile.access_paths),
    transportation_options: ids(profile.transportation_options),
    transportation_other: profile.transportation_other ?? '',
    transportation_difficult: profile.transportation_difficult ?? false,
    considered_very_remote: profile.considered_very_remote ?? false,
    remote_context_notes: profile.remote_context_notes ?? '',
    gidca: profile.gidca ?? false,
    lms: profile.lms ?? false,
    community_engagement: ids(profile.community_engagement),
    community_context_remarks: profile.community_context_remarks ?? '',
    submitted_at: toDatetimeLocal(profile.submitted_at),
    transaction_type: profile.transaction_type ?? '',
  }
}

function payload(form: StakeholderProfileFormData) {
  return {
    governance_level: text(form.governance_level),
    ro: text(form.ro),
    sdo: text(form.sdo),
    school_district: text(form.school_district),
    school_name: text(form.school_name),
    school_id: text(form.school_id),
    province_id: form.province_id,
    municipality_id: form.municipality_id,
    legislative_district: text(form.legislative_district),
    barangay_id: form.barangay_id,
    street: text(form.street),
    notes_corrections: text(form.notes_corrections),
    notes_recent_development: text(form.notes_recent_development),
    mobile_1: text(form.mobile_1),
    mobile_2: text(form.mobile_2),
    landline: text(form.landline),
    chief_name: text(form.chief_name),
    chief_position: text(form.chief_position),
    chief_email: text(form.chief_email),
    chief_mobile: text(form.chief_mobile),
    admin_staff_name: text(form.admin_staff_name),
    admin_staff_position: text(form.admin_staff_position),
    admin_staff_email: text(form.admin_staff_email),
    admin_staff_mobile: text(form.admin_staff_mobile),
    network_administrator_name: text(form.network_administrator_name),
    longitude: numeric(form.longitude),
    latitude: numeric(form.latitude),
    nearby_institutions: form.nearby_institutions,
    nearby_institutions_other: text(form.nearby_institutions_other),
    travel_time_to_center_minutes: numeric(form.travel_time_to_center_minutes),
    access_paths: form.access_paths,
    transportation_options: form.transportation_options,
    transportation_other: text(form.transportation_other),
    transportation_difficult: form.transportation_difficult,
    considered_very_remote: form.considered_very_remote,
    remote_context_notes: text(form.remote_context_notes),
    gidca: form.gidca,
    lms: form.lms,
    community_engagement: form.community_engagement,
    community_context_remarks: text(form.community_context_remarks),
    submitted_at: fromDatetimeLocal(form.submitted_at),
    transaction_type: text(form.transaction_type),
  }
}

export const stakeholderProfileService = {
  list: (params: Record<string, string | number>) =>
    api.get<PaginatedResponse<StakeholderProfileOfficeRow>>('/stakeholder-profiles', { params }).then((r) => r.data),

  options: () => lookupService.options<StakeholderProfileOptions>(OPTIONS_URL),

  show: (officeId: number) =>
    api.get<ApiResponse<StakeholderProfileEnvelope>>(`/stakeholder-profiles/${officeId}`).then((r) => r.data.data),

  update: (officeId: number, form: StakeholderProfileFormData) =>
    api.put<ApiResponse<StakeholderProfileEnvelope>>(`/stakeholder-profiles/${officeId}`, payload(form)).then((r) => r.data),
}
