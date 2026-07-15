# Audit Log

## Purpose

Read-only admin browse screen over `App\Models\AuditLog` — the append-only trail already written to by `AuditLog::record()` across nearly every controller/policy in the app (CRUD, role/permission changes, denied-permission checks). Existed as a table with no admin UI until now; this adds the ability to actually look at it.

## Architecture

- `App\Http\Controllers\AuditLogController` — single `index()` action. No `create`/`store`/`edit`/`update`/`destroy` exist, or ever should — the table is append-only by design (see `AuditLog`'s own doc-comment).
- No Policy class. Same pattern as `ReferenceDataController::index()`/`UserController::index()`: this is an aggregate listing with no single model instance to check per-record, so `$this->authorize(Permission::AuditLogView->value)` checks the permission string directly.

## Access model

One permission, `audit_log.view`, seeded **admin-only** via the automatic `Permission::cases()` mechanism — not granted to `encoder`/`viewer`.

Deliberately not paired with per-resource `.view` permissions. A role holding `audit_log.view` alone would see other resources' PII inside `properties` diffs (e.g. Personnel/StakeholderProfile contact fields) without holding that resource's own `.view` permission. This is safe today only because `admin` — the only role that currently holds it — already holds every `.view` permission in the app; see the doc-comment on `Permission::AuditLogView` before ever seeding this to a lesser role.

## Flow / filtering

`GET /audit-log` (`audit-log.index`) — paginated (20/page), newest-first. Filters, all optional and combinable:

| Param | Match | Notes |
|---|---|---|
| `search` | substring | `action` OR the actor's live `users.name`/`email` OR — when the actor's `User` account was later deleted — `properties->actor_snapshot->name`/`email` |
| `action` | exact | Options (`filterOptions.actions`) are a live `DISTINCT` query, not a hardcoded list — actions are added ad-hoc with no central enum |
| `subject_type` | exact | Requires the full FQCN (e.g. `App\Models\Office`), not the basename shown in the table. Options (`filterOptions.subjectTypes`) ship as `{value: FQCN, label: basename}` pairs |
| `from` / `to` | date range | Both boundaries inclusive |

**Actor resolution** (`AuditLogController::resolveActor()`): prefers the live `actor()` relation; falls back to `properties.actor_snapshot` (captured at write time by `AuditLog::record()` — see [`docs/architecture.md`](../architecture.md#audit-logging)) when the live relation is null (actor's account was since deleted, `actor_id` nulled via `nullOnDelete`); `null` (rendered "System") for genuinely actor-less rows (console/system-initiated). Search matches the snapshot too, so a deleted actor's historical rows stay findable by the name/email they had at write time.

**Properties viewer**: each row's `properties` (shape varies per action — before/after diff, created-attribute snapshot, or ad-hoc fields) is shown via a "View details" button opening a `Dialog` with `JSON.stringify(..., null, 2)` in a `<pre>`. Not rendered inline (payload size varies too much per action) and not special-cased per action type, matching this app's existing philosophy of one generic screen rather than fragmenting by type.

Reached via a top-level sidebar item ("Audit Log", `History` icon), placed after Roles and before Libraries — grouped with the other admin-capability screens (Users/Roles), not nested under Libraries.

## Inertia routes / prop contracts

Requires `auth`+`verified`+`audit_log.view`. Controller: `App\Http\Controllers\AuditLogController`.

| Route | Page component | Key props |
|---|---|---|
| `GET /audit-log` (`audit-log.index`) | `audit-log/Index` | `logs` (paginated: `id`, `created_at`, `action`, `actor` (`{id, name, email}` \| `null`), `subject_type` (basename \| `null`), `subject_id`, `properties`), `filterOptions` (`actions: string[]`, `subjectTypes: {value, label}[]`), `filters` (`search`, `action`, `subject_type`, `from`, `to`) |

## Key files

- `app/Http/Controllers/AuditLogController.php`
- `app/Models/AuditLog.php`
- `app/Enums/Permission.php` — `AuditLogView` case and its doc-comment (the admin-only-coupling risk note)
- `resources/js/pages/audit-log/Index.vue`
- `resources/js/types/audit-log.ts`
- `resources/js/components/AppSidebar.vue` — "Audit Log" nav item
- `tests/Feature/AuditLogControllerTest.php` — 17 tests: authorization per role, pagination order, all four filter shapes (including the FQCN-vs-basename `subject_type` trap and inclusive date boundaries), actor resolution (live/snapshot/null), `filterOptions` correctness, and that no mutation route exists (405 on `POST /audit-log`)

## Non-obvious design decisions

- **No Policy class.** Consistent with `ReferenceDataController`/`UserController`'s index-only admin screens — a Policy earns its cost when there's a per-record ability to check (`view`/`update` on one model instance); here there's only ever "can this user see the aggregate listing at all."
- **`subject_type` filters on the raw FQCN, not the display basename.** The column stores the full class name; the basename is a display-only transform applied in `through()`. Filtering by `'Office'` silently matches nothing — the frontend `<Select>` must submit the FQCN values `filterOptions.subjectTypes` provides, not their labels.
- **Admin-only coupling is a known, accepted risk, not an oversight.** Documented directly on `Permission::AuditLogView` rather than only here, since that's where a future permission-seeding change is most likely to be made without reading this doc first.

## Future considerations

- If a custom-role feature ever lets non-admin roles be granted `audit_log.view`, it must be paired with a check (or a data-filtering rule) that the grantee also holds `.view` on whatever resources appear in `subject_type`/`properties` — otherwise it reopens the PII-leak risk the current admin-only coupling avoids by construction. See `Permission::AuditLogView`'s doc-comment.
