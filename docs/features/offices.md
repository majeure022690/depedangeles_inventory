# Offices

## Purpose

Full CRUD over `App\Models\Office` — every school and division-level office/unit under the division (95 seeded rows). Office is the FK backbone the office-scoped features hang off: `users.office_id`, `stakeholder_profiles.office_id`, `internet_connectivity_surveys.office_id`.

## Architecture

- **A dedicated resource, not part of the reference-data system.** Its columns (`office_name`, `office_type`, `school_id`, `address`, `region`, `district`, `division`, `contact_number`, `email`, `is_active`) fit neither reference-data tier, and it's a structural entity other tables reference by FK, not a closed vocabulary. So it has its own controller/Policy/Requests instead of an entry in `config/reference-data.php`.
- `App\Policies\OfficePolicy` — one permission per action, no office scoping (Office itself isn't office-scoped); `delete` denials are audited.
- Audit: `LogsActivity` (`office.created|updated|deleted`).

## Access model

`office.view`/`.create`/`.edit`/`.delete`, seeded **admin-only**. No encoder/viewer screen needs an office picklist today. Deliberately not folded into `reference-data.manage`: an FK backbone is higher-stakes than a dropdown list.

## API endpoints

Controller: `Api\Management\OfficeController`. There is no `/options` endpoint; other screens get office dropdowns from their own options (e.g. `GET /api/users/options` → `offices`, via `Office::activeOptions()`).

| Method + path | Route permission | Policy | Request → response |
|---|---|---|---|
| `GET /api/offices?search&sort&direction&per_page` | `office.view` | `viewAny` | paginated `OfficeResource`. Sort keys: `office_name` (default), `office_type`, `school_id`, `district`, `is_active`. |
| `POST /api/offices` | `office.create` | `create` (Form Request) | `StoreOfficeRequest` → `OfficeResource`, 201. `is_active` defaults to `true`. |
| `GET /api/offices/{id}` | `office.view` | `view` | `OfficeResource` |
| `PUT /api/offices/{id}` | `office.edit` | `update` | `UpdateOfficeRequest` → `OfficeResource` |
| `DELETE /api/offices/{id}` | `office.delete` | `delete` | 204, or **409** `"This office is still in use and cannot be deleted — deactivate it instead."` |

**Fields:** `office_name` (required, unique), `office_type`, `school_id` (integer), `address`, `region`, `district`, `division`, `contact_number`, `email`, `is_active`. `OfficeResource` returns all of them plus `id`, `created_at`, `updated_at`.

## Frontend

| Route | Page |
|---|---|
| `/offices` | `pages/management/offices/OfficeList.tsx` |
| `/offices/new` | `pages/management/offices/OfficeCreate.tsx` (`office.create`) |
| `/offices/:id/edit` | `pages/management/offices/OfficeEdit.tsx` — shows the 409 message on a blocked delete |

Form body: `components/offices/OfficeFields.tsx`; API: `services/officeService.ts`; types: `types/office.ts`. Reached from the sidebar's "Libraries" group and from the "Institutional data" card on `/reference-data` (`GET /api/reference-data` returns `office_count` for that card).

## Key files

- `backend/app/Http/Controllers/Api/Management/OfficeController.php`
- `backend/app/Http/Requests/Management/StoreOfficeRequest.php`, `UpdateOfficeRequest.php`
- `backend/app/Http/Resources/Management/OfficeResource.php`
- `backend/app/Models/Office.php`, `backend/app/Policies/OfficePolicy.php`, `backend/database/factories/OfficeFactory.php`
- `backend/database/migrations/2026_07_15_110000_restrict_office_delete_on_dependent_tables.php`, `2026_07_15_120000_add_unique_index_to_offices_office_name.php`

## Non-obvious design decisions

- **Restrict, not null/cascade, on delete.** Once an admin could delete an office, silently nulling users' office or wiping a school's profile/survey became the wrong failure mode, so all three dependent FKs are `restrictOnDelete()`. `destroy()` attempts the delete inside a transaction and turns the FK violation (SQLSTATE `23000`) into a 409 instead of pre-checking: the FK is atomic, a pre-query would race. The transaction also rolls back the `deleted` activity row, so a blocked delete leaves no trace. `is_active = false` is the "retire" path (no soft deletes).
- **`office_name` is unique at both layers.** The Form Request gives a friendly 422; a DB unique index closes the race two concurrent creates could otherwise win (security review, 2026-07-15).
- **`office_type` is free text, not a select** — the source data has no fixed vocabulary.

## Future considerations

- The office permissions have never been exercised below `admin`; revisit the seeded grants only if a real non-admin need appears.
