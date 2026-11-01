import { api } from '@/lib/api'
import { downloadFile } from '@/lib/download'
import type { ApiResponse, PaginatedResponse } from '@/types/api'
import { RADIO_GRADES, type GspIrisData, type GspProfile, type GspProfileFormData, type GspProfileOptions, type RadioGrade } from '@/types/gsp-profile'

function text(value: string): string | null {
  return value.trim() === '' ? null : value.trim()
}

function count(value: string): number | string | null {
  if (value.trim() === '') return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : value
}

/** School years start in June; the GSP counts as of March 31 of the closing year. */
export function defaultSchoolYear(today = new Date()): {
  school_year: string
  as_of: string
} {
  const start = today.getMonth() >= 5 ? today.getFullYear() : today.getFullYear() - 1
  return { school_year: `${start}-${start + 1}`, as_of: `${start + 1}-03-31` }
}

function emptyRadio(): GspProfileFormData['radio_teachers'] {
  return Object.fromEntries(RADIO_GRADES.map((grade) => [grade, { literacy: '', numeracy: '' }])) as GspProfileFormData['radio_teachers']
}

export function emptyGspProfileForm(officeId = ''): GspProfileFormData {
  return {
    office_id: officeId,
    ...defaultSchoolYear(),
    level: 'elementary',
    radio_teachers: emptyRadio(),
    dict_free_wifi: '',
    dict_free_wifi_installed: '',
    isp_reliability: {},
    school_head_id: '',
    ito_id: '',
    planning_officer_id: '',
  }
}

export function gspProfileFormFrom(profile: GspProfile): GspProfileFormData {
  const radio = emptyRadio()
  for (const grade of RADIO_GRADES) {
    const saved = profile.radio_teachers[grade]
    radio[grade] = {
      literacy: saved?.literacy == null ? '' : String(saved.literacy),
      numeracy: saved?.numeracy == null ? '' : String(saved.numeracy),
    }
  }

  return {
    office_id: String(profile.office_id),
    school_year: profile.school_year,
    as_of: profile.as_of,
    level: profile.level,
    radio_teachers: radio,
    dict_free_wifi: profile.dict_free_wifi ?? '',
    dict_free_wifi_installed: profile.dict_free_wifi_installed ?? '',
    isp_reliability: { ...profile.isp_reliability },
    school_head_id: profile.school_head_id === null ? '' : String(profile.school_head_id),
    ito_id: profile.ito_id === null ? '' : String(profile.ito_id),
    planning_officer_id: profile.planning_officer_id === null ? '' : String(profile.planning_officer_id),
  }
}

function payload(form: GspProfileFormData) {
  const radio: Partial<Record<RadioGrade, { literacy: number | string | null; numeracy: number | string | null }>> = {}
  for (const grade of RADIO_GRADES) {
    const { literacy, numeracy } = form.radio_teachers[grade]
    if (literacy !== '' || numeracy !== '') radio[grade] = { literacy: count(literacy), numeracy: count(numeracy) }
  }

  return {
    office_id: form.office_id === '' ? null : Number(form.office_id),
    school_year: form.school_year.trim(),
    as_of: form.as_of,
    level: form.level,
    radio_teachers: radio,
    dict_free_wifi: text(form.dict_free_wifi),
    dict_free_wifi_installed: text(form.dict_free_wifi_installed),
    isp_reliability: Object.fromEntries(Object.entries(form.isp_reliability).filter(([, value]) => value !== '')),
    school_head_id: form.school_head_id === '' ? null : Number(form.school_head_id),
    ito_id: form.ito_id === '' ? null : Number(form.ito_id),
    planning_officer_id: form.planning_officer_id === '' ? null : Number(form.planning_officer_id),
  }
}

export const gspProfileService = {
  list: (params: Record<string, string | number>) => api.get<PaginatedResponse<GspProfile>>('/gsp-profiles', { params }).then((r) => r.data),
  options: (officeId?: number) =>
    api
      .get<ApiResponse<GspProfileOptions>>('/gsp-profiles/options', {
        params: officeId ? { office_id: officeId } : {},
      })
      .then((r) => r.data.data),
  preview: (params: { office_id: number; school_year: string; level: string }) =>
    api.get<ApiResponse<GspIrisData>>('/gsp-profiles/preview', { params }).then((r) => r.data.data),
  show: (id: number) => api.get<ApiResponse<GspProfile>>(`/gsp-profiles/${id}`).then((r) => r.data.data),
  create: (form: GspProfileFormData) => api.post<ApiResponse<GspProfile>>('/gsp-profiles', payload(form)).then((r) => r.data),
  update: (id: number, form: GspProfileFormData) => api.put<ApiResponse<GspProfile>>(`/gsp-profiles/${id}`, payload(form)).then((r) => r.data),
  remove: (id: number) => api.delete(`/gsp-profiles/${id}`),
  download: (id: number) => downloadFile(`/gsp-profiles/${id}/download`, {}, 'GSP_ICT_Report.xlsx'),
}
