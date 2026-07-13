# Personnel

## Purpose

The division office's staff directory. This is the "who" behind Equipment's accountability tracking (`accountable_officer`, `end_user`, `received_by` on every transaction all resolve to a Personnel record) — a standard CRUD resource with search/filter and soft deletes, no special data-access pattern beyond the ordinary one.

## Architecture

- `App\Models\Personnel` — soft-deletes, `$table = 'personnel'` (explicit, since the plural of "personnel" isn't Eloquent's default guess).
- `App\Policies\PersonnelPolicy` — standard `viewAny`/`view`/`create`/`update`/`delete`/`restore`/`forceDelete`, each against its own `Permission` case; `restore`/`forceDelete` are hard-coded `false` (no UI path to either exists).
- Relationships back to Equipment/EquipmentTransaction (`currentlyAccountableForEquipment`, `currentlyEndUserForEquipment`, `equipmentTransactionsAsAccountableOfficer`, `equipmentTransactionsAsEndUser`, `equipmentTransactionsReceived`) exist on the Personnel side for querying "what does this person currently hold / what have they ever been party to," even though Equipment's docs describe the relationship from the Equipment side.

## Flow

1. **List** (`GET /personnel`, `personnel.index`) — searchable, filterable by employment status (`active`/`inactive`/`all`, defaults to `active`), paginated 15/page.
2. **Create** (`GET /personnel/create`, `personnel.create`) / **Store** (`POST /personnel`, `personnel.store`).
3. **Edit** (`GET /personnel/{personnel}/edit`, `personnel.edit`) / **Update** (`PUT/PATCH /personnel/{personnel}`, `personnel.update`).
4. **Delete** (`DELETE /personnel/{personnel}`, `personnel.destroy`) — soft delete; historical Equipment transactions referencing a deleted Personnel row keep their nullable FK intact (deletion doesn't cascade or null those out — Personnel is soft-deleted, so the row and its `id` still exist for the FK to point at).

Every create/update/delete writes an `AuditLog` entry (`personnel.created`/`personnel.updated`/`personnel.deleted`).

## Inertia routes / prop contracts

All routes require `auth`+`verified`. Controller: `App\Http\Controllers\PersonnelController`. No `show` route (`Route::resource(...)->except('show')` — same as every other resource-backed module in this app; there's no dedicated read-only detail page distinct from Edit).

| Route | Page component | Key props |
|---|---|---|
| `GET /personnel` (`personnel.index`) | `personnel/Index` | `personnel` (paginated), `filters` (`search`, `status`) |
| `GET /personnel/create` (`personnel.create`) | `personnel/Create` | `options` (position, name-suffix, RO offices, SDO offices, cause-of-separation, teachers-funding-source lookups) |
| `GET /personnel/{personnel}/edit` (`personnel.edit`) | `personnel/Edit` | `personnel` (full record), `options` (same lookup set as Create) |

`personnel.index` row shape:

```php
[
    'id', 'employee_id', 'full_name', 'last_name', 'first_name', 'middle_name', 'suffix',
    'position', 'division_unit', 'ro_division', 'mobile_1', 'deped_email',
    'oic', 'oic_office', 'inactive',
]
```

`personnel.edit`'s `personnel` prop is every fillable column plus computed `full_name`, dates formatted as `Y-m-d` strings (`date_hired`, `separation_date`).

## Key files

- `app/Http/Controllers/PersonnelController.php`
- `app/Models/Personnel.php`
- `app/Policies/PersonnelPolicy.php`
- `app/Http/Requests/PersonnelStoreRequest.php`, `PersonnelUpdateRequest.php`
- `database/migrations/2026_07_10_000002_create_personnel_table.php`
- `resources/js/pages/personnel/` (`Index.vue`, `Create.vue`, `Edit.vue`, `Partials/`)

## Non-obvious design decisions

- **Inactive/separated personnel are retained, not deleted.** The `inactive` boolean plus `separation_date`/`separation_cause`/`transferred_from`/`transferred_to` columns model an employment-status lifecycle distinct from soft-delete — a separated employee's record stays queryable (and their historical Equipment accountability stays intact) even though they no longer show up in the default `active`-filtered index view. Soft-delete (`deleted_at`) is reserved for records genuinely removed from the system, not for staff who've left the office.
- **`scopeActive()` excludes separated/transferred-out records** but this is independent of soft-deletes — a record can be `inactive = true` and still not soft-deleted, precisely so its accountability history stays queryable.
- **No `employee_id = 9999` "unknown" placeholder.** Explicitly called out in the model's doc-comment: "unknown accountable officer" on Equipment is represented by a `null` nullable FK, not a sentinel Personnel row.

## Future considerations

None flagged as blocking during review.
