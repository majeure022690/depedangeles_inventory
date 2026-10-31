# Audit Log

## Purpose

Read-only browse over `activity_log` (spatie/laravel-activitylog) — the append-only trail of record CRUD, role and role-assignment changes, accountability transfers, readings, admin 2FA resets and denied-permission attempts. What gets written, and how actor attribution survives account deletion, is described in [`docs/architecture.md`](../architecture.md#audit-logging); this doc covers the screen and its API.

## Architecture

- `Api\Management\AuditLogController` — `index()` and `options()` only. There are no write endpoints, and there never should be: the trail is append-only and never pruned.
- No Policy class: this is an aggregate listing with no per-record ability, so the controller authorizes the permission string directly (`$this->authorize(Permission::AuditLogView->value)`), same as the reference-data overview.

## Access model

One permission, `audit_log.view`. `RolePermissionSeeder` grants it only to the two administrator roles, `super_administrator` (every permission) and `system_administrator` (every permission except `roles.create|edit|delete`). Both have data scope `all` and hold every per-resource `.view`. `encoder`, `office_encoder` and `viewer` don't have it. Only `super_administrator` is re-synced on every seed; the other roles are created once and then managed in the role editor, so a deployed database can differ from the seed. Holding `audit_log.view` also means the user can never skip the 2FA code on a trusted device (`TrustedDeviceService::ALWAYS_REQUIRE_CODE`).

What a holder sees depends on their data scope (the widest scope among their roles, `User::dataScope()`), applied by `AuditLogController::visibleActivities()` to both `index` and `options`:

- **`all`:** every row.
- **`school` / `own`:** rows they caused themselves, plus rows whose subject is within their reach: Personnel, Equipment and its transactions, ISP accounts and their speed tests and subscription costs, users in reach, and their own office's Stakeholder Profile and Connectivity Survey. Other people's `authorization.denied` and `auth.*` rows are hidden. Rows about roles, permissions, offices, reference data, settings and system jobs are hidden too, because their subject isn't on that list. Full detail: [roles-and-permissions.md](roles-and-permissions.md#data-scope).

**Scope limits which records a holder sees, not which fields.** `visibleActivities()` doesn't check per-resource `.view` permissions. A role granted `audit_log.view` without, say, `personnel.view` would still see in-reach Personnel rows, including contact fields inside `changes`/`properties`. The one-line comment on `Permission::AuditLogView` warns about this. Before granting it to a non-administrator role, make sure that role already holds `.view` on every resource whose rows it can reach.

## API endpoints

| Method + path | Route permission | Response |
|---|---|---|
| `GET /api/audit-log?search&event&subject_type&subject_id&causer_id&date_from&date_to&per_page` | `audit_log.view` | paginated `AuditLogResource`, newest first |
| `GET /api/audit-log/options` | `audit_log.view` | `{data: {events: string[], subject_types: {value: FQCN, label: basename}[], actors: {value: causer_id, label: name}[]}}` |

Filters (all optional, combinable):

| Param | Match | Notes |
|---|---|---|
| `search` | substring | `description` (e.g. `equipment.updated`), the live causer's name/email, **or** `properties.actor_snapshot.name`/`email`, so a deleted actor's rows stay findable under the name they had then |
| `event` | exact | `created`/`updated`/`deleted` or a dotted event (`equipment.transaction.recorded`, `authorization.denied`, ...). Options are a live `DISTINCT`, not a hard-coded list. |
| `subject_type` | exact | **Full FQCN** (`App\Models\Office`); the basename shown in the table matches nothing. Submit the `value` from options, not the `label`. |
| `subject_id` | exact (integer) | **Only applied together with `subject_type`**; on its own it is ignored. The pair narrows the list to one record's history, e.g. `subject_type=App\Models\Equipment&subject_id=42`. Ids are per table, so `subject_id` alone would mix unrelated records that share an id. |
| `causer_id` | exact | Options list up to 500 most recently active actors; deleted accounts are labelled from their latest snapshot with a `(deleted)` suffix. |
| `date_from` / `date_to` | date | both inclusive, compared in Manila time |

Every filter narrows `visibleActivities()`, the actor's [data-scoped](roles-and-permissions.md#data-scope) base query, so no combination reaches rows outside the actor's reach: a `subject_type`/`subject_id` pair naming an out-of-scope record just returns an empty page. Covered by `backend/tests/Feature/{AuditLogControllerTest,DataScope/AuditLogDataScopeTest}.php`.

**`AuditLogResource`:** `{id, log_name, description, event, subject_type (basename|null), subject_id, actor: {id, name, email}|null, changes: {old, attributes}, properties, created_at}`. `actor` prefers the live causer and falls back to `properties.actor_snapshot`; `null` means a system/console action. `changes` holds the before/after attribute diff; `properties` holds event-specific data (and `migrated_from_audit_log_id` on rows copied from the pre-2026-10-19 `audit_logs` table).

## Frontend

`/audit-log` → `pages/management/AuditLog.tsx` (`audit_log.view`; sidebar "Administration" group). Filter bar with removable chips, click an actor to filter by them, and a details dialog showing the before/after diff. API: `services/auditLogService.ts`; types: `types/auditLog.ts` (`AuditLogFilters`).

- **Applied filters live in the URL query string** (same names as the API params), so a filtered view can be bookmarked or shared and survives a reload. Malformed values are dropped on read: `causer_id`/`subject_id` must be digits, dates `YYYY-MM-DD`, and `subject_id` is kept only alongside a `subject_type`, mirroring the backend rule. Applying filters replaces the history entry rather than pushing a new one.
- **Per-record view:** a `subject_type` + `subject_id` pair shows a "Record: <Type> #<id>" chip. There is no form input for `subject_id`; it only arrives through a link. While it is shown, the separate type chip is hidden. Removing the record chip keeps the type filter (its chip reappears); changing the type in the filter bar also drops the record.
- **Linking in:** `lib/auditLog.ts` exports `auditLogHref(subjectType, subjectId)` (builds `/audit-log?subject_type=<FQCN>&subject_id=<id>`) and `EQUIPMENT_SUBJECT_TYPE`. The equipment edit page uses them for its "View audit history" link, shown only with `audit_log.view` (see [`equipment.md`](equipment.md#audit-history)). Other record pages can reuse the helper.

## Key files

- `backend/app/Http/Controllers/Api/Management/AuditLogController.php`
- `backend/app/Http/Resources/Management/AuditLogResource.php`
- `frontend/src/pages/management/AuditLog.tsx`, `frontend/src/lib/auditLog.ts`
- `backend/app/Providers/AppServiceProvider.php` — `configureAuditTrail()` (actor snapshot hook)
- `backend/app/Enums/Permission.php` — `AuditLogView` and its PII-exposure warning
- `backend/database/migrations/2026_10_19_000003_create_activity_log_table.php`, `2026_10_19_000004_copy_audit_logs_into_activity_log.php`

## Non-obvious design decisions

- **`subject_type` filters on the FQCN, not the display basename** — the column stores the class name; the basename is a display transform.
- **`subject_id` is ignored without `subject_type`** rather than rejected: an id alone names no record, and the SPA never sends one alone.
- **Actor options are capped at 500**, most recently active first, so the dropdown stays usable as the trail grows; search still finds older actors.
- **The missing per-resource `.view` check is an accepted, documented risk.** Today only the administrator roles hold `audit_log.view`. The warning sits on `Permission::AuditLogView` itself because that's where someone looks before granting it.

## Future considerations

- If non-administrator roles are ever granted `audit_log.view`, add a per-subject-type `.view` check to `visibleActivities()`. Data scope already limits which records they reach, but not whether they may read that resource's fields.
