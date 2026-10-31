export const EQUIPMENT_SUBJECT_TYPE = 'App\\Models\\Equipment'

export function auditLogHref(subjectType: string, subjectId: number): string {
  return `/audit-log?${new URLSearchParams({ subject_type: subjectType, subject_id: String(subjectId) }).toString()}`
}
