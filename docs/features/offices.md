# Offices

## Purpose

Full CRUD over `App\Models\Office` — every school and division-level office/unit under the division (95 seeded rows). Office is the FK backbone other office-scoped features hang off of: `users.office_id`, `stakeholder_profiles.office_id`, `internet_connectivity_surveys.office_id`.

## Architecture

- **Dedicated resource, not part of the reference-data system.** `/reference-data` covers 13 small lookup tables shaped either `name`/`description`/`sort_order`/`is_active` (Tier 1) or `type`/`value`/... (Tier 2) — see [`docs/features/reference-data.md`](reference-data.md). Office's columns (`office_name`, `office_type`, `school_id`, `address`, `region`, `district`, `division`, `contact_number`, `email`, `is_active`) fit neither shape, and Office is a structural entity other tables reference via FK, not a closed lookup vocabulary — so it gets its own Controller/Policy/Requests/Vue pages instead of being folded into `config/reference-data.php`.
- `App\Http\Controllers\OfficeController` — full `index`/`create`/`store`/`edit`/`update`/`destroy` (no `show`).
- `App\Policies\OfficePolicy` — one permission per action, no scoping logic (unlike Stakeholder Profile/Internet Connectivity Survey's own-office checks — Office itself isn't office-scoped).

## Access model

Four granular permissions (`office.view`/`.create`/`.edit`/`.delete`), seeded **admin-only** in `RolePermissionSeeder` — `encoder`/`viewer` hold none of them. No existing encoder/viewer-facing screen shows an Office picklist today, so there was nothing to weigh against keeping this admin-only.

## Flow

1. **Index** (`GET /offices`, `offices.index`) — paginated/searchable list.
2. **Create/Store** (`offices.create`/`.store`) — redirects to the new office's edit page.
3. **Edit/Update** (`offices.edit`/`.update`) — redirects back to edit.
4. **Destroy** (`offices.destroy`) — hard-delete, guarded by DB-level `restrictOnDelete()` FKs (see below); a blocked delete throws a friendly `ValidationException`, not a raw SQL error.

Reached via the "Libraries" sidebar entry → `reference-data/Index.vue`'s "Institutional data" section (a card linking to `offices.index()`, gated `can('office.view')`, distinguished from the Tier 1/Tier 2 grid by a "Full CRUD" badge instead of "Tier 1"/"Tier 2"). Not a separate top-level sidebar item. `ReferenceDataController::index()` gained one prop (`officeCount`) purely so that card can show a row count consistent with the other cards — Office itself is still not part of `config/reference-data.php`.

Every mutation is audited: `office.created`/`.updated`/`.deleted` via `AuditLog::record()`, before/after diff on update, full attribute snapshot on create/delete — same convention as Equipment/Personnel.

## Inertia routes / prop contracts

Requires `auth`+`verified`. Controller: `App\Http\Controllers\OfficeController`.

| Route | Page component | Key props |
|---|---|---|
| `GET /offices` (`offices.index`) | `offices/Index` | `offices` (paginated: `id`, `office_name`, `office_type`, `school_id`, `district`, `division`, `region`, `is_active`), `filters` (`search`) |
| `GET /offices/create` (`offices.create`) | `offices/Create` | none |
| `GET /offices/{office}/edit` (`offices.edit`) | `offices/Edit` | `office` (all 11 columns except timestamps: `id`, `office_name`, `office_type`, `school_id`, `address`, `region`, `district`, `division`, `contact_number`, `email`, `is_active`) |

## Key files

- `app/Http/Controllers/OfficeController.php`
- `app/Models/Office.php`
- `app/Policies/OfficePolicy.php`
- `app/Http/Requests/OfficeStoreRequest.php`, `OfficeUpdateRequest.php`
- `database/factories/OfficeFactory.php`
- `database/migrations/2026_07_15_110000_restrict_office_delete_on_dependent_tables.php`, `2026_07_15_120000_add_unique_index_to_offices_office_name.php`
- `resources/js/pages/offices/` (`Index.vue`, `Create.vue`, `Edit.vue`, `Partials/Form.vue`)
- `resources/js/types/office.ts`
- `resources/js/pages/reference-data/Index.vue` — "Institutional data" section

## Non-obvious design decisions

- **Restrict, not null/cascade, on delete.** Before this CRUD existed, `users.office_id` was `nullOnDelete()` and `stakeholder_profiles.office_id`/`internet_connectivity_surveys.office_id` were `cascadeOnDelete()` — fine when Office had no delete UI at all (seed-only). Once an admin can delete a real office by mistake, silently nulling a user's office assignment or wiping out a school's survey/profile data is the wrong failure mode. All three FKs switched to `restrictOnDelete()`; `OfficeController::destroy()` attempts the delete and catches the resulting `QueryException` (SQLSTATE `23000`) rather than pre-checking — the FK is already atomic and authoritative, so a pre-query would just be a second query with no added safety. `Office.is_active` remains the "retire without deleting" path (no soft-deletes added — redundant given `is_active` already exists).
- **Delete + audit log share one transaction.** `destroy()` wraps `$office->delete()` and `AuditLog::record()` in `DB::transaction()`, so a delete blocked by the FK leaves zero trace — no partial delete, no orphaned audit-log row.
- **`office_name` uniqueness is enforced at both layers.** `Rule::unique('offices', 'office_name')` in both Form Requests gives a friendly validation message; a real DB `unique` index (added after the fact, confirmed no duplicates existed in seeded data first) closes the TOCTOU race the app-layer check alone can't — two concurrent requests could otherwise both pass the pre-write `SELECT`. Flagged by security review, fixed same day.
- **`office_type` stays a plain `<Input>`, not a `<Select>`.** The source data (`Division Office`, `Elementary School`, `High School`, `Integrated School`, `Senior High School`, `Unit`, and null) has no fixed vocabulary — matches the pre-existing model doc-comment, confirmed against actual seeded values rather than assumed.
- **Admin-only, deliberately not folded into `reference-data.manage`.** Office is higher-stakes than the 13 lookup tables that single permission covers (it's a real FK backbone, not a closed dropdown vocabulary), so it gets its own four-permission bundle instead.

## Future considerations

- No encoder/viewer-facing Office picklist exists yet, so the permission set has never been exercised below admin — revisit the seeded grants only if a real non-admin use case for browsing/picking offices surfaces.
