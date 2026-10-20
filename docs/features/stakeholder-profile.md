# Stakeholder Profile

## Purpose

A profile describing a school or division-level office — governance level, location/address, contact persons (chief, admin staff, network administrator), community and access context. One form per office, shared by every user assigned to that office.

Started (2026-07-10) as a global singleton; converted (2026-07-14) to **one row per `App\Models\Office`** once the app began tracking every school under the division. The shared pattern is described in [`docs/architecture.md`](../architecture.md#3-one-row-per-office-stakeholder-profile-internet-connectivity-survey); Internet Connectivity Survey followed the same conversion a day later.

## Architecture

- `App\Models\StakeholderProfile` — `belongsTo(Office)` plus the three PSGC relations; **no factory** (rows are created lazily per office). `hasAnswers()` tells "filled in" from "opened once" (see below).
- `App\Models\Office::stakeholderProfile()` — the inverse `hasOne`.
- `App\Policies\StakeholderProfilePolicy` — `view`/`update` (own office, or any office with `.view_all`) and `viewAny` (the cross-office list, `.view_all` only). No `create`/`delete`: neither action exists.

Audit: `LogsActivity`, `updated` events only (`stakeholder_profile.updated`) — the lazy empty insert isn't worth a row.

## Access model

- **Own office only** — `stakeholder_profile.view`/`.edit` reach only the profile whose `office_id` matches the user's own. Another office's id returns 403 and does **not** create that office's row.
- **Cross-office** — `stakeholder_profile.view_all` (seeded to `admin` only) can view and edit any office's profile and is the only permission that reaches the list. It is a separate permission, not a modifier on the other two.
- **No office assigned** — a `.view`/`.edit` holder without an `office_id` has nothing to open; the sidebar hides the item. A `.view_all` holder without an office still gets the list.

## API endpoints

Controller: `Api\Management\StakeholderProfileController`. `{office}` is an `offices.id`.

| Method + path | Route permission | Policy | Request → response |
|---|---|---|---|
| `GET /api/stakeholder-profiles?search&per_page` | `stakeholder_profile.view_all` | `viewAny` | paginated **offices** (not profile rows): `{id, office_name, office_type, school_id, has_profile, updated_at}`. `updated_at` is the profile's, `null` until answered. |
| `GET /api/stakeholder-profiles/options` | `.view` or `.view_all` | — | see options below |
| `GET /api/stakeholder-profiles/{office}` | `.view` or `.view_all` | `view` on a transient model, **before** `firstOrCreate` | `{data: {office: {id, office_name, school_id}, profile: StakeholderProfileResource}}` |
| `PUT /api/stakeholder-profiles/{office}` | `.edit` or `.view_all` | `update` on a transient model | `UpdateStakeholderProfileRequest` → same shape as GET |

**`StakeholderProfileResource` / writable fields** (all optional): `governance_level`, `ro`, `sdo`, `school_district`, `school_name`, `school_id`, `province_id`, `municipality_id`, `barangay_id` (PSGC ids), `legislative_district`, `street`, `notes_corrections`, `notes_recent_development`, `mobile_1`, `mobile_2`, `landline`, `chief_*`/`admin_staff_*` (name, position, email, mobile), `network_administrator_name`, `longitude`, `latitude`, `nearby_institutions`, `access_paths`, `transportation_options`, `community_engagement` (arrays of `stakeholder_libraries` ids), `nearby_institutions_other`, `transportation_other`, `travel_time_to_center_minutes`, `transportation_difficult`, `considered_very_remote`, `remote_context_notes`, `gidca`, `lms`, `community_context_remarks`, `submitted_at` (datetime, converted to Manila time), `transaction_type`. The resource adds `id`, `office_id`, `updated_at`, and read-only `complete_address`.

**Options:** `governance_level`, `transaction_type` (Tier 2 strings; `transaction_type` is read from Equipment's library on purpose), `nearby_institution`, `type_of_access_road`, `by_transportation`, `community_engagement` (id-valued, for the checkbox groups), and `province`, `municipality`, `barangay` — the whole Region III PSGC tree (~3,100 barangays) with `parent_id` on municipalities (→ province) and barangays (→ municipality). It is sent once and filtered client-side into cascading selects rather than round-tripping per selection.

## Frontend

| Route | Page |
|---|---|
| `/stakeholder-profiles` | `pages/management/stakeholder-profiles/StakeholderProfileList.tsx` (`.view_all`) |
| `/stakeholder-profiles/:officeId` | `pages/management/stakeholder-profiles/StakeholderProfileEdit.tsx` — completion bar and section stepper, one section rendered at a time |

Form body: `components/stakeholder-profiles/StakeholderProfileFields.tsx`; API: `services/stakeholderProfileService.ts`; types: `types/stakeholder-profile.ts`. The sidebar link goes to the list for `.view_all` holders and to the user's own `:officeId` otherwise (`layouts/ManagementLayout.tsx`).

## Key files

- `backend/app/Http/Controllers/Api/Management/StakeholderProfileController.php`
- `backend/app/Http/Requests/Management/UpdateStakeholderProfileRequest.php`
- `backend/app/Http/Resources/Management/StakeholderProfileResource.php`, `StakeholderProfileOfficeResource.php`
- `backend/app/Models/StakeholderProfile.php`, `Office.php`, `PsgcProvince.php`, `PsgcMunicipality.php`, `PsgcBarangay.php`
- `backend/app/Policies/StakeholderProfilePolicy.php`
- `backend/database/migrations/2026_07_14_071751_make_stakeholder_profiles_office_scoped.php`, `2026_07_14_083524_convert_stakeholder_profiles_address_to_psgc_foreign_keys.php`

## Non-obvious design decisions

- **One row per office, lazily created.** No create/store/destroy; `GET`/`PUT` `firstOrCreate(['office_id' => ...])`. There is a list because there are up to one row per office (95+), not one ever.
- **Authorize a transient instance before writing.** The Policy needs `office_id` to compare against the user's own, so the controller authorizes `new StakeholderProfile(['office_id' => $office->id])` first. A wrong-office or zero-permission request 403s without creating the row.
- **`has_profile` uses `hasAnswers()`, not row existence.** Opening the page creates an empty row, so existence alone would mark every visited office as answered. The four booleans default to `false` at the DB level and are excluded from the check, since a default can't count as an answer.
- **`office_id` is `NOT NULL` + unique.** That index is the whole "at most one per office" guarantee; it replaced the old `singleton_guard` column.
- **`complete_address` is a PHP accessor**, not a stored column. It used to be a MySQL STORED generated column, but generated columns can't join the PSGC tables once those became FKs. Never mass-assignable; returned as a read-only display value.
- **PSGC location is real FKs** (`province_id`/`municipality_id`/`barangay_id`). There is no stored `psgc` code; it is the selected barangay's own `code`.
- **`school_name`/`school_id` are free text**, pre-filled from the office in `services/stakeholderProfileService.ts` when empty, then independent of the office row. Nothing keeps them in sync afterwards.

## Future considerations

- No "recently viewed offices" shortcut for admins browsing many schools; the list's search is the only navigation aid.
