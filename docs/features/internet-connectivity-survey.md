# Internet Connectivity Survey

## Purpose

A survey describing a school or division-level office's internet/connectivity situation — ISP availability and subscriptions, mobile signal/data quality, coverage areas, DICT free Wi-Fi, electricity source/reliability. One edit form per office, shared by every user assigned to that office.

Started (2026-07-10) as a true global singleton — one record for the whole application, following the pattern documented in [`docs/architecture.md`](../architecture.md#3-one-row-per-tenant-stakeholder-profile-internet-connectivity-survey). Converted (2026-07-15) to **one row per `App\Models\Office`**, mirroring the same conversion Stakeholder Profile went through a day earlier (2026-07-14) — see [`docs/features/stakeholder-profile.md`](stakeholder-profile.md) for that precedent and [`docs/architecture.md`](../architecture.md) for the shared pattern description.

## Architecture

- `App\Models\InternetConnectivitySurvey` — bare structural model, fillable/casts/`belongsTo(Office)` only, **no factory** (surveys are created lazily per office, never seeded/faked in bulk).
- `App\Models\Office::internetConnectivitySurvey()` — the inverse `hasOne`.
- `App\Policies\InternetConnectivitySurveyPolicy` — `view`/`update` (own-office scoped) plus `viewAny` (gates the cross-office admin list). No `create`/`delete` to authorize, since neither action exists.
- `App\Http\Controllers\InternetConnectivitySurveyController` — `index()` (admin list) plus `edit()`/`update()`, both always scoped to one specific `{office}`.

## Access model

- **Own office only** — a user holding `internet_connectivity.view`/`.edit` can only read/write the survey matching their own `office_id` (`User::office_id`). Attempting another office's survey (by URL) 403s, and never creates that office's row as a side effect.
- **Cross-office oversight** — a user holding `internet_connectivity.view_all` (seeded only to `admin`) can view and edit *any* office's survey, and is the only one who can reach the admin list. This is independent of whether they also have an `office_id` of their own.
- **No office assigned** — a `view`/`.edit` holder with no `office_id` has nothing to view (hidden from the sidebar entirely); a `.view_all` holder with no `office_id` still gets the full cross-office list.

## Flow

1. **Index** (`GET /internet-connectivity-surveys`, `internet-connectivity-surveys.index`, `.view_all` only) — paginated/searchable list of every `Office`, each row showing whether it has a survey yet (`has_survey`) and when it was last updated. Office-centric, not `InternetConnectivitySurvey`-row-centric, since most offices won't have a survey row until their first edit.
2. **Edit** (`GET /internet-connectivity-surveys/{office}`, `internet-connectivity-surveys.edit`) — authorizes against a transient (unsaved) `InternetConnectivitySurvey` instance with `office_id` already set *before* touching the database, then `firstOrCreate(['office_id' => $office->id])`s that office's row and renders it.
3. **Update** (`PUT /internet-connectivity-surveys/{office}`, `internet-connectivity-surveys.update`) — same `firstOrCreate`, then updates.

There is no `create`, `store`, or `destroy` route — a survey is always reached via the lazy `firstOrCreate`, never explicitly created or deleted.

Every update writes an `AuditLog` entry (`internet_connectivity_survey.updated`) with a field-level before/after diff.

## Inertia routes / prop contracts

Requires `auth`+`verified`. Controller: `App\Http\Controllers\InternetConnectivitySurveyController`.

| Route | Page component | Key props |
|---|---|---|
| `GET /internet-connectivity-surveys` (`internet-connectivity-surveys.index`) | `internet-connectivity-survey/Index` | `offices` (paginated list: `id`, `office_name`, `office_type`, `school_id`, `has_survey`, `updated_at`), `filters` (`search`) |
| `GET /internet-connectivity-surveys/{office}` (`internet-connectivity-surveys.edit`) | `internet-connectivity-survey/Edit` | `office` (`id`, `office_name`), `internetConnectivitySurvey` (that office's record), `options` (ISP provider, mobile network signal, signal quality, coverage area, source-of-electricity lookups) |

`internetConnectivitySurvey` prop shape (every fillable column except `office_id` itself):

```php
[
    'id',
    'has_isp_in_area',
    'available_isps', 'available_isps_other',
    'mobile_signal_types',
    'has_mobile_data_connectivity', 'mobile_data_quality',
    'subscribes_to_isp', 'subscribed_isps',
    'insufficient_bandwidth_explanation',
    'coverage_areas', 'coverage_areas_other',
    'dict_free_wifi_recipient', 'dict_free_wifi_rating',
    'has_sufficient_bandwidth', 'no_subscription_reason',
    'has_electricity_source', 'electricity_sources',
    'primarily_solar_powered', 'frequent_brownouts',
    'rooms_other_use',
]
```

## Key files

- `app/Http/Controllers/InternetConnectivitySurveyController.php`
- `app/Models/InternetConnectivitySurvey.php`, `app/Models/Office.php`
- `app/Policies/InternetConnectivitySurveyPolicy.php`
- `app/Http/Requests/InternetConnectivitySurveyUpdateRequest.php`
- `database/migrations/2026_07_15_090000_convert_internet_connectivity_surveys_to_office_scoped.php` (converts from global singleton to one-per-office; supersedes the now-removed `2026_07_11_000000_add_singleton_guard_...` guard for this table)
- `resources/js/pages/internet-connectivity-survey/` (`Index.vue`, `Edit.vue`, `Partials/`)
- `resources/js/types/internet-connectivity-survey.ts`
- `resources/js/components/AppSidebar.vue` — conditional nav item (list for `.view_all`, own-office edit link otherwise), same branching shape as Stakeholder Profile's

## Non-obvious design decisions

- **One row per office, not a global singleton or a full CRUD list resource.** No `create`/`store`/`destroy` exist by design (a survey is always lazily `firstOrCreate`'d, never explicitly made or removed) — but unlike a true singleton, there is an `index()`, because there are now up to one row per office rather than exactly one ever. This mirrors Stakeholder Profile's conversion exactly, one day later.
- **The pre-existing singleton row was discarded, not reattached to an office — a deliberate product-owner call, not the Stakeholder Profile precedent repeating by default.** Unlike Stakeholder Profile's old singleton row (empty junk, nothing of value lost), this table's one row held real seeded survey answers from `InternetConnectivitySurveySeeder` / the source Excel workbook's "Internet Connectivity" sheet. It was still deleted outright rather than migrated to a specific office, because there was no reliable way to determine which office that single division-wide answer set was ever meant to represent — the source workbook recorded it as one combined answer set, not tied to any one school/office row in `offices`. Guessing wrong would have silently attributed one office's public-facing survey data to another. `InternetConnectivitySurveySeeder.php` and its data file were deleted in the same change — there is no longer "the one global survey" to seed; every office's survey now starts empty, exactly like Stakeholder Profile for offices that have never been edited.
- **Authorization checks a transient instance (with `office_id` already set) before any DB write.** `InternetConnectivitySurveyPolicy` needs `$internetConnectivitySurvey->office_id` to compare against the acting user's own — so the controller/Form Request build `new InternetConnectivitySurvey(['office_id' => $office->id])` and authorize that, rather than `firstOrCreate`-ing first. A wrong-office (or `pending`-role) user's `GET`/`PUT` is 403'd *before* that office's row gets created, rather than creating it and then denying access to it. Same pattern as Stakeholder Profile, applied here to both `edit()` (controller) and `update()` (both the controller's pre-check and the Form Request's `authorize()`, which independently re-derives the office from `$this->route('office')`).
- **The old "Protected summary" aggregates block (Total ISPs, Total Cost/month, etc.) was removed from the edit page (2026-07-15), shortly after this office-scoping conversion shipped.** It was computed division-wide across *all* `IspAccount`/`IspSubscriptionCost` rows — `isp_accounts` has no `office_id` column, so there was no per-office subset to show. That made sense when this was one global survey for the whole division, but once every office got its own survey page, every office showed the exact same division-wide totals, which was confusing rather than useful. It was pulled rather than reworked to be office-scoped, since that would require adding `isp_accounts.office_id` and backfilling it — a real schema change, not a quick fix (see Future considerations).
- **`office_id` is `NOT NULL`, unique.** The old `singleton_guard` column (a race-condition guard for "exactly one row globally") no longer applies and was dropped; a plain `unique` index on `office_id` provides the equivalent guarantee ("at most one survey per office") without needing a sentinel column, since MySQL/MariaDB's unique index semantics already give the right behavior for a `NOT NULL` column. (Unlike the `stakeholder_profiles` conversion, this table's `down()` migration drops the FK via `dropConstrainedForeignId()` alone rather than dropping the unique index first — MySQL used the `unique(office_id)` index itself to satisfy the FK's required index here, so dropping it first would fail with "needed in a foreign key constraint".)

## Future considerations

- A per-office ISP spend/coverage summary could come back if `isp_accounts` ever gains an `office_id` column — revisit only if that reporting need becomes real, since it's a `database`/`backend` schema change, not something this doc solves.
- No trusted-device-style "recently viewed offices" shortcut for admins browsing many schools — the index list's search is the only navigation aid today, same gap as Stakeholder Profile's.
