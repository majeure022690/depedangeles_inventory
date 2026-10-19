/** `AuditLogResource`. `actor` is the live causer, else the snapshot taken when the row was written, else null (system). */
export interface AuditLogEntry {
  id: number
  log_name: string | null
  description: string
  event: string | null
  /** Class basename, e.g. `Equipment`. */
  subject_type: string | null
  subject_id: number | null
  actor: { id: number | null; name: string | null; email: string | null } | null
  changes: {
    old?: Record<string, unknown> | null
    attributes?: Record<string, unknown> | null
  }
  properties: Record<string, unknown>
  created_at: string
}

/**
 * `GET /api/audit-log/options`. `subject_types[].value` is the FQCN the `subject_type` filter expects;
 * `actors[].value` is the user id the `causer_id` filter expects (deleted accounts are labelled "<name> (deleted)").
 */
export interface AuditLogOptions {
  events: string[]
  subject_types: { value: string; label: string }[]
  actors: { value: number; label: string }[]
}
