# Stakeholder Profile

## Purpose

A single record describing the division office itself — governance level, location/address, contact persons (chief, admin staff, network administrator), community/access context. One edit form, no list, no multiple records. This is one of two modules the singleton pattern (see [`docs/architecture.md`](../architecture.md#3-singleton-stakeholder-profile-internet-connectivity-survey)) exists for.

## Architecture

- `App\Models\StakeholderProfile` — bare structural model, fillable/casts only, **no factory** (only one real row ever exists, so fake/seeded multiples don't make sense), no relationships to Equipment/Personnel/IspAccount (standalone survey/profile data).
- `App\Policies\StakeholderProfilePolicy` — deliberately only `view`/`update` (no `create`/`delete` to authorize, since neither action exists).
- `App\Http\Controllers\StakeholderProfileController` — deliberately only `edit()`/`update()`.

## Flow

1. **Edit** (`GET /stakeholder-profile`, `stakeholder-profile.edit`) — authorizes against a transient (unsaved) `StakeholderProfile` instance *before* touching the database, then `firstOrCreate([])`s the singleton row and renders it.
2. **Update** (`PUT /stakeholder-profile`, `stakeholder-profile.update`) — same `firstOrCreate([])`, then updates.

There is no `index`, `create`, `store`, or `destroy` route — `routes/web.php` declares only these two routes by hand (not `Route::resource()`), and neither takes an `{id}` parameter.

Every update writes an `AuditLog` entry (`stakeholder_profile.updated`) with a field-level before/after diff.

## Inertia routes / prop contracts

Requires `auth`+`verified`. Controller: `App\Http\Controllers\StakeholderProfileController`.

| Route | Page component | Key props |
|---|---|---|
| `GET /stakeholder-profile` (`stakeholder-profile.edit`) | `stakeholder-profile/Edit` | `stakeholderProfile` (the singleton record), `options` (governance level, nearby institution, access-road type, transportation, community engagement, transaction-type lookups) |

`stakeholderProfile` prop shape (every fillable column, plus one read-only derived field):

```php
[
    'id', 'governance_level', 'ro', 'sdo',
    'school_district', 'school_name', 'school_id',
    'province', 'city_municipality', 'legislative_district', 'barangay', 'street', 'psgc',
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

## Key files

- `app/Http/Controllers/StakeholderProfileController.php`
- `app/Models/StakeholderProfile.php`
- `app/Policies/StakeholderProfilePolicy.php`
- `app/Http/Requests/StakeholderProfileUpdateRequest.php`
- `database/migrations/2026_07_10_090000_create_stakeholder_profiles_table.php`, `2026_07_11_000000_add_singleton_guard_to_stakeholder_profiles_and_internet_connectivity_surveys_tables.php`
- `resources/js/pages/stakeholder-profile/` (`Edit.vue`, `Partials/`)

## Non-obvious design decisions

- **Singleton, not a list resource.** No `index`/`create`/`store`/`destroy` exist by design — see [`docs/architecture.md`](../architecture.md#3-singleton-stakeholder-profile-internet-connectivity-survey) for the full pattern, including the DB-level `singleton_guard` unique-constraint fix that closes a race condition where two concurrent first-load requests could both `INSERT` a duplicate row before that constraint existed.
- **`complete_address` is a MySQL STORED generated column**, computed by the database from the other address fields — deliberately excluded from `$fillable`, since it can never be mass-assigned, only read. It's included in the `stakeholderProfile` prop as a read-only display value.
- **Authorization checks a transient instance before any DB write.** `StakeholderProfilePolicy` never inspects the model instance (only `hasPermissionTo()`), so authorizing `new StakeholderProfile` is equivalent to authorizing the persisted row — but it means a `pending`-role user's `GET` request is 403'd *before* the singleton row gets created by `firstOrCreate([])`, rather than creating it and then denying access to it.
- **`stakeholder_profile.view` is kept separate from `.edit`**, unlike the admin-only `reference-data.manage`. The `viewer` role is an established read-only role spanning every other resource in this app, and this record holds contact/address information a non-editing stakeholder (a higher office, an auditor) may legitimately need to read without being able to change it.

## Future considerations

None flagged as blocking during review.
