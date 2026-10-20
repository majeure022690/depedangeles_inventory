# Audit Log

## Purpose

Read-only admin browse over `activity_log` (spatie/laravel-activitylog) — the append-only trail of record CRUD, role and role-assignment changes, accountability transfers, readings, admin 2FA resets and denied-permission attempts. What gets written, and how actor attribution survives account deletion, is described in [`docs/architecture.md`](../architecture.md#audit-logging); this doc covers the screen and its API.

## Architecture

- `Api\Management\AuditLogController` — `index()` and `options()` only. There are no write endpoints, and there never should be: the trail is append-only and never pruned.
- No Policy class: this is an aggregate listing with no per-record ability, so the controller authorizes the permission string directly (`$this->authorize(Permission::AuditLogView->value)`), same as the reference-data overview.

## Access model

One permission, `audit_log.view`, seeded **admin-only** and deliberately not paired with per-resource `.view` permissions. A role holding it alone would see other resources' PII inside `changes`/`properties` (e.g. Personnel or Stakeholder Profile contact fields) without that resource's own `.view`. It is safe today only because `admin` holds every `.view`; read the doc-comment on `Permission::AuditLogView` before granting it to any other role.

## API endpoints

| Method + path | Route permission | Response |
|---|---|---|
| `GET /api/audit-log?search&event&subject_type&causer_id&date_from&date_to&per_page` | `audit_log.view` | paginated `AuditLogResource`, newest first |
| `GET /api/audit-log/options` | `audit_log.view` | `{data: {events: string[], subject_types: {value: FQCN, label: basename}[], actors: {value: causer_id, label: name}[]}}` |

Filters (all optional, combinable):

| Param | Match | Notes |
|---|---|---|
| `search` | substring | `description` (e.g. `equipment.updated`), the live causer's name/email, **or** `properties.actor_snapshot.name`/`email`, so a deleted actor's rows stay findable under the name they had then |
| `event` | exact | `created`/`updated`/`deleted` or a dotted event (`equipment.transaction.recorded`, `authorization.denied`, ...). Options are a live `DISTINCT`, not a hard-coded list. |
| `subject_type` | exact | **Full FQCN** (`App\Models\Office`); the basename shown in the table matches nothing. Submit the `value` from options, not the `label`. |
| `causer_id` | exact | Options list up to 500 most recently active actors; deleted accounts are labelled from their latest snapshot with a `(deleted)` suffix. |
| `date_from` / `date_to` | date | both inclusive, compared in Manila time |

**`AuditLogResource`:** `{id, log_name, description, event, subject_type (basename|null), subject_id, actor: {id, name, email}|null, changes: {old, attributes}, properties, created_at}`. `actor` prefers the live causer and falls back to `properties.actor_snapshot`; `null` means a system/console action. `changes` holds the before/after attribute diff; `properties` holds event-specific data (and `migrated_from_audit_log_id` on rows copied from the pre-2026-10-19 `audit_logs` table).

## Frontend

`/audit-log` → `pages/management/AuditLog.tsx` (`audit_log.view`; sidebar "Administration" group). Filter bar with removable chips, click an actor to filter by them, and a details dialog showing the before/after diff. API: `services/auditLogService.ts`; types: `types/auditLog.ts`.

## Key files

- `backend/app/Http/Controllers/Api/Management/AuditLogController.php`
- `backend/app/Http/Resources/Management/AuditLogResource.php`
- `backend/app/Providers/AppServiceProvider.php` — `configureAuditTrail()` (actor snapshot hook)
- `backend/app/Enums/Permission.php` — `AuditLogView` and its admin-only-coupling note
- `backend/database/migrations/2026_10_19_000003_create_activity_log_table.php`, `2026_10_19_000004_copy_audit_logs_into_activity_log.php`

## Non-obvious design decisions

- **`subject_type` filters on the FQCN, not the display basename** — the column stores the class name; the basename is a display transform.
- **Actor options are capped at 500**, most recently active first, so the dropdown stays usable as the trail grows; search still finds older actors.
- **Admin-only coupling is an accepted, documented risk**, recorded on `Permission::AuditLogView` itself because that's where a future grant change will be made.

## Future considerations

- If non-admin roles are ever granted `audit_log.view`, pair it with a check (or data filter) that the grantee also holds `.view` on the resources appearing in `subject_type`/`changes` — otherwise it reopens the PII exposure the admin-only coupling avoids.
