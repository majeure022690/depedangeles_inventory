export type AuditLogActor = {
    id: number | null;
    name: string | null;
    email: string | null;
};

/**
 * `properties` is a pass-through JSON blob (before/after diff, created
 * attributes, or ad-hoc fields depending on `action` — see
 * AuditLogController::index()). Deliberately untyped beyond
 * `Record<string, unknown>`; the UI renders it as pretty-printed JSON
 * rather than special-casing every action's shape.
 */
export type AuditLogEntry = {
    id: number;
    created_at: string | null;
    action: string;
    actor: AuditLogActor | null;
    subject_type: string | null;
    subject_id: number | null;
    properties: Record<string, unknown>;
};

export type AuditLogFilterOptions = {
    actions: string[];
    subjectTypes: { value: string; label: string }[];
};

export type AuditLogFilters = {
    search: string | null;
    action: string | null;
    subject_type: string | null;
    from: string | null;
    to: string | null;
};
