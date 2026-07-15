# Stakeholder Profile

## Purpose

A profile describing a school or division-level office — governance level, location/address, contact persons (chief, admin staff, network administrator), community/access context. One edit form per office, shared by every user assigned to that office.

Started (2026-07-10) as a true global singleton — one record for the whole application, following the same starting shape Internet Connectivity Survey used at the time. Converted (2026-07-14) to **one row per `App\Models\Office`** once the app grew to track multiple schools under the division (the `offices` table) rather than a single division office — see [`docs/architecture.md`](../architecture.md#3-one-row-per-tenant-stakeholder-profile-internet-connectivity-survey) for the full pattern description and how it differs from a true singleton. Internet Connectivity Survey went through the same conversion one day later (2026-07-15) — see [`docs/features/internet-connectivity-survey.md`](internet-connectivity-survey.md).

## Architecture

- `App\Models\StakeholderProfile` — bare structural model, fillable/casts/`belongsTo(Office)` only, **no factory** (profiles are created lazily per office, never seeded/faked in bulk), no relationships to Equipment/Personnel/IspAccount (standalone survey/profile data, scoped only to its owning Office).
- `App\Models\Office::stakeholderProfile()` — the inverse `hasOne`.
- `App\Policies\StakeholderProfilePolicy` — `view`/`update` (own-office scoped) plus `viewAny` (gates the cross-office admin list). No `create`/`delete` to authorize, since neither action exists.
- `App\Http\Controllers\StakeholderProfileController` — `index()` (admin list) plus `edit()`/`update()`, both always scoped to one specific `{office}`.

## Access model

- **Own office only** — a user holding `stakeholder_profile.view`/`.edit` can only read/write the profile matching their own `office_id` (`User::office_id`). Attempting another office's profile (by URL) 403s, and never creates that office's row as a side effect.
- **Cross-office oversight** — a user holding `stakeholder_profile.view_all` (seeded only to `admin`) can view and edit *any* office's profile, and is the only one who can reach the admin list. This is independent of whether they also have an `office_id` of their own.
- **No office assigned** — a `view`/`.edit` holder with no `office_id` has nothing to view (hidden from the sidebar entirely); a `.view_all` holder with no `office_id` still gets the full cross-office list.

## Flow

1. **Index** (`GET /stakeholder-profiles`, `stakeholder-profiles.index`, `view_all` only) — paginated/searchable list of every `Office`, each row showing whether it has a profile yet (`has_profile`) and when it was last updated. Office-centric, not StakeholderProfile-row-centric, since most offices won't have a profile row until their first edit.
2. **Edit** (`GET /stakeholder-profiles/{office}`, `stakeholder-profiles.edit`) — authorizes against a transient (unsaved) `StakeholderProfile` instance with `office_id` already set *before* touching the database, then `firstOrCreate(['office_id' => $office->id])`s that office's row and renders it.
3. **Update** (`PUT /stakeholder-profiles/{office}`, `stakeholder-profiles.update`) — same `firstOrCreate`, then updates.

There is no `create`, `store`, or `destroy` route — a profile is always reached via the lazy `firstOrCreate`, never explicitly created or deleted.

Every update writes an `AuditLog` entry (`stakeholder_profile.updated`) with a field-level before/after diff.

## Inertia routes / prop contracts

Requires `auth`+`verified`. Controller: `App\Http\Controllers\StakeholderProfileController`.

| Route | Page component | Key props |
|---|---|---|
| `GET /stakeholder-profiles` (`stakeholder-profiles.index`) | `stakeholder-profile/Index` | `offices` (paginated list: `id`, `office_name`, `office_type`, `school_id`, `has_profile`, `updated_at`), `filters` (`search`) |
| `GET /stakeholder-profiles/{office}` (`stakeholder-profiles.edit`) | `stakeholder-profile/Edit` | `office` (`id`, `office_name`, `school_id`), `stakeholderProfile` (that office's record), `options` (governance level, nearby institution, access-road type, transportation, community engagement, transaction-type lookups, plus the full PSGC `province`/`municipality`/`barangay` hierarchy — see below) |

`stakeholderProfile` prop shape (every fillable column except `office_id` itself, plus one read-only derived field):

```php
[
    'id', 'governance_level', 'ro', 'sdo',
    'school_district', 'school_name', 'school_id',
    'province_id', 'municipality_id', 'legislative_district', 'barangay_id', 'street',
    'complete_address', // read-only, see below — never in the writable payload
    'notes_corrections', 'notes_recent_development',
    'mobile_1', 'mobile_2', 'landline',
    'chief_name', 'chief_position', 'chief_email', 'chief_mobile',
    'admin_staff_name', 'admin_staff_position', 'admin_staff_email', 'admin_staff_mobile',
    'network_administrator_name',
    'longitude' /* float|null */, 'latitude' /* float|null */,
    'nearby_institutions', 'nearby_institutions_other',
    'travel_time_to_center_minutes',
    'access_paths',
    'transportation_options', 'transportation_other',
    'transportation_difficult', 'considered_very_remote', 'remote_context_notes',
    'gidca', 'lms',
    'community_engagement', 'community_context_remarks',
    'submitted_at', 'transaction_type',
]
```

Note `school_district`/`school_name`/`school_id` are free-text fields, not FKs — `school_name`/`school_id` are pre-filled from the owning `Office` row's `office_name`/`school_id` when the form first loads (Edit.vue, since the office is already known from the URL), but remain editable, plain strings; nothing keeps them in sync after that first pre-fill.

`options.province`/`.municipality`/`.barangay` (2026-07-14) are the full Region III PSGC hierarchy (~3,100 barangays), shipped in full on every edit-page load — `.municipality`/`.barangay` carry a `parent_id` so the form filters them client-side into cascading `<Select>`s (province → municipality → barangay) rather than round-tripping per selection. See `docs/architecture.md`'s PSGC section for why this is 3 dedicated tables, not 4 (no `psgc_regions`) or 1 flat table.

## Key files

- `app/Http/Controllers/StakeholderProfileController.php`
- `app/Models/StakeholderProfile.php`, `app/Models/Office.php`
- `app/Policies/StakeholderProfilePolicy.php`
- `app/Http/Requests/StakeholderProfileUpdateRequest.php`
- `database/migrations/2026_07_10_090000_create_stakeholder_profiles_table.php`, `2026_07_14_071751_make_stakeholder_profiles_office_scoped.php` (converts from global singleton to one-per-office; supersedes the now-removed `2026_07_11_000000_add_singleton_guard_...` guard for this table), `2026_07_14_083524_convert_stakeholder_profiles_address_to_psgc_foreign_keys.php` (province/city_municipality/barangay/psgc strings → real FKs)
- `app/Models/PsgcProvince.php`, `PsgcMunicipality.php`, `PsgcBarangay.php` + their migrations/seeders (`database/seeders/data/psgc_*.json`, imported from the division's legacy PSGC dump, Region III only)
- `resources/js/pages/stakeholder-profile/` (`Index.vue`, `Edit.vue`, `Partials/`)
- `resources/js/components/AppSidebar.vue` — conditional nav item (list for `.view_all`, own-office edit link otherwise)

## Non-obvious design decisions

- **One row per office, not a global singleton or a full CRUD list resource.** No `create`/`store`/`destroy` exist by design (a profile is always lazily `firstOrCreate`'d, never explicitly made or removed) — but unlike a true singleton, there is an `index()`, because there are now up to one row per office (95+ possible) rather than exactly one ever.
- **The same permission string means something different depending on whose office it's checked against.** `stakeholder_profile.view`/`.edit` are scoped to the acting user's own `office_id` by the Policy, not by the permission system itself — `stakeholder_profile.view_all` is what actually grants cross-office access, and it's a completely separate permission, not a modifier on the other two.
- **`office_id` is `NOT NULL`, unique.** The old `singleton_guard` column (a race-condition guard for "exactly one row globally") no longer applies and was dropped; a plain `unique` index on `office_id` provides the equivalent guarantee ("at most one profile per office") without needing a sentinel column, since MySQL/MariaDB's unique index semantics already give the right behavior for a `NOT NULL` column.
- **`complete_address` is a PHP accessor** (`StakeholderProfile::completeAddress()`), not a stored column — it used to be a MySQL STORED generated column, but generated columns can't `JOIN` to read the PSGC province/municipality/barangay names now that those are real FKs, so it's built from the three relations instead. Deliberately excluded from `$fillable`, since it can never be mass-assigned, only read. It's included in the `stakeholderProfile` prop as a read-only display value.
- **`province`/`municipality`/`barangay` are real FKs into dedicated PSGC tables** (`province_id`/`municipality_id`/`barangay_id`), not free text — see `docs/architecture.md`. There's no stored `psgc` column anymore: the granular PSGC code is simply the selected barangay's own `code`.
- **Authorization checks a transient instance (with `office_id` already set) before any DB write.** `StakeholderProfilePolicy` needs `$stakeholderProfile->office_id` to compare against the acting user's own — so the controller/Form Request build `new StakeholderProfile(['office_id' => $office->id])` and authorize that, rather than `firstOrCreate`-ing first. A wrong-office (or `pending`-role) user's `GET` is 403'd *before* that office's row gets created, rather than creating it and then denying access to it.

## Future considerations

- No trusted-device-style "recently viewed offices" shortcut for admins browsing many schools — the index list's search is the only navigation aid today.
