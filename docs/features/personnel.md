# Personnel

## Purpose

The division office's staff directory. This is the "who" behind Equipment's accountability tracking (`accountable_officer`, `end_user`, `received_by` on every transaction resolve to a Personnel record). A standard CRUD resource with search/filter and soft deletes, no special data-access pattern.

## Architecture

- `App\Models\Personnel` — soft-deletes, `$table = 'personnel'` (explicit, since Eloquent can't pluralize "personnel"). Tier 1 FKs: `position_id`, `ro_office_id`, `sdo_office_id` (all nullable, matching the original string columns). `office_id` is a nullable FK to `offices` (the "School/office" field). It places the person at a school or office for the `school` [data scope](roles-and-permissions.md#data-scope). `user_id` is a nullable, unique FK to `users` (`nullOnDelete`): the explicit link from a user account to "their" Personnel record, which the `own` scope uses (`User::personnel()` is the inverse `hasOne`). `fund_source` is a JSON array of `personnel_libraries` ids (teachers' funding source).
- `App\Policies\PersonnelPolicy` — `viewAny`/`view`/`create`/`update`/`delete`, each against its own `Permission` case; `delete` denials are audited. `view`/`update`/`delete` also require the record to be in the user's data scope (`Personnel::isVisibleTo()`), otherwise **404**. `create` is refused (403) to `own`-scope users, because the new record would be out of their reach as soon as it was saved. `restore`/`forceDelete` are hard-coded `false` (no path to either exists).
- Relationships back to Equipment/EquipmentTransaction (`currentlyAccountableForEquipment`, `equipmentTransactionsAsAccountableOfficer`, ...) exist for "what does this person hold / what have they been party to" queries.

Audit: `LogsActivity` (`personnel.created|updated|deleted`).

## API endpoints

Controller: `Api\Management\PersonnelController`. All under `auth:sanctum` + `throttle:authenticated-api`.

| Method + path | Route permission | Policy | Request → response |
|---|---|---|---|
| `GET /api/personnel?search&status&sort&direction&per_page` | `personnel.view` | `viewAny` | paginated `PersonnelResource`. `status` = `active` (default) / `inactive` / `all`. Sort keys: `employee_id`, `full_name`, `last_name`, `created_at`; default last name, first name. |
| `GET /api/personnel/options` | `personnel.view` or `.create` or `.edit` | — | `{data: {position, ro_office, sdo_office, name_suffix, cause_of_separation, office, teachers_funding_source, user?}}`: the first three are Tier 1 (id-valued) and the next two Tier 2 (string-valued). `office` lists active offices (id-valued), and `teachers_funding_source` id-valued because it backs the `fund_source` array. `user` (all-scope actors only) is every account as `{value: user id, label: "Name (email)", linked_personnel_id: int\|null}`, so the form can flag accounts already linked elsewhere |
| `POST /api/personnel` | `personnel.create` | `create` (Form Request) | `StorePersonnelRequest` → `PersonnelResource`, 201 |
| `GET /api/personnel/{id}` | `personnel.view` | `view` | `PersonnelResource` |
| `PUT /api/personnel/{id}` | `personnel.edit` | `update` | `UpdatePersonnelRequest` → `PersonnelResource` |
| `DELETE /api/personnel/{id}` | `personnel.delete` | `delete` | 204, soft delete. Clears `user_id` in the same transaction, so the account can be linked to another record. |

The list, single-record endpoints and the dashboard's personnel counts are limited to the user's [data scope](roles-and-permissions.md#data-scope): `own` sees only their linked record, `school` the personnel at their office plus their linked record, `all` everyone.

**Request fields:** required `employee_id` (unique), `last_name`, `first_name`; optional `middle_name`, `suffix` (Tier 2), `position_id`/`ro_office_id`/`sdo_office_id` (active Tier 1 ids), `office_id` (an active office; on update the record's current office is also accepted, so an unchanged value still saves after that office is deactivated), `user_id` (an existing user not linked to another record), `oic`, `oic_office`, `mobile_1`, `mobile_2`, `deped_email`, `personal_email`, `date_hired`, `non_deped_funded`, `fund_source` (array of active library ids), `inactive`, `separation_date`, `separation_cause` (Tier 2), `transferred_from`, `transferred_to`.

**Scoped actors** (`school`; `own` can't create) are restricted by `Concerns\RestrictsScopedAssignments`: only `all`-scope users may set or change `office_id` or `user_id`. On create a scoped actor's `office_id` is forced to their own office (422 on `office_id` if their account has no office) and `user_id` must be empty; on update both must equal the record's current values. A rejected value is a 422 ("Only users with access to all records can change this.").

**`PersonnelResource`:** every request field, plus `id`, computed `full_name`, `created_at`, `updated_at`, the Tier 1 display names under their historical keys: `position`, `ro_division` (RO office), `division_unit` (SDO office), and `office` (office name), and `user: {id, name, email} | null` (the linked account). Dates as `Y-m-d`.

## Frontend

| Route | Page |
|---|---|
| `/personnel` | `pages/management/personnel/PersonnelList.tsx` |
| `/personnel/new` | `pages/management/personnel/PersonnelCreate.tsx` (`personnel.create`, and scope `school` or `all`) |
| `/personnel/:id/edit` | `pages/management/personnel/PersonnelEdit.tsx` |

Form body: `components/personnel/PersonnelFields.tsx`; API: `services/personnelService.ts`; types: `types/personnel.ts`. The sidebar and page title follow the scope ("My Record" / "Office Personnel" / "Personnel"); an `own` user's sidebar item opens their linked record directly. Scoped users see the office and user-account fields locked.

## Key files

- `backend/app/Http/Controllers/Api/Management/PersonnelController.php`
- `backend/app/Http/Requests/Management/StorePersonnelRequest.php`, `UpdatePersonnelRequest.php`
- `backend/app/Http/Resources/Management/PersonnelResource.php`
- `backend/app/Models/Personnel.php`, `backend/app/Policies/PersonnelPolicy.php`

## Non-obvious design decisions

- **Separated staff are retained, not deleted.** `inactive` plus `separation_date`/`separation_cause`/`transferred_from`/`transferred_to` model an employment lifecycle distinct from soft-delete. A separated employee stays queryable, with their Equipment accountability history intact, even though the default `active` list hides them. Soft-delete is for records genuinely removed from the system.
- **`scopeActive()` is independent of soft-deletes** — a record can be `inactive` and not deleted, precisely so its history stays queryable.
- **Soft delete keeps transaction FKs valid.** Historical Equipment transactions referencing a deleted person still point at an existing row.
- **No `employee_id = 9999` "unknown" placeholder.** Unknown accountable officer on Equipment is a `null` FK.
- **`user_id`, not `deped_email`, decides "own".** Personnel editors can change `deped_email`, so matching on it let anyone who could edit an email grant access. Migration `2026_10_26_000002` backfilled `user_id` only where exactly one live Personnel record and exactly one user share an email (case-insensitive); ambiguous matches were left unlinked for an admin to resolve. The link can be set from either side: `user_id` here, or `personnel_id` in the `/users` editor ([roles-and-permissions.md](roles-and-permissions.md#users)).

## Future considerations

None blocking.
