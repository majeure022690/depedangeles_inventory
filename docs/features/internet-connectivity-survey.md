# Internet Connectivity Survey

## Purpose

A survey of a school or division-level office's connectivity situation — ISP availability and subscriptions, mobile signal/data quality, coverage areas, DICT free Wi-Fi, electricity source/reliability. One form per office, shared by every user assigned to that office.

Started (2026-07-10) as a global singleton; converted (2026-07-15) to **one row per `App\Models\Office`**, one day after Stakeholder Profile's identical conversion. The shared pattern is in [`docs/architecture.md`](../architecture.md#3-one-row-per-office-stakeholder-profile-internet-connectivity-survey); [`stakeholder-profile.md`](stakeholder-profile.md) is the precedent.

## Architecture

- `App\Models\InternetConnectivitySurvey` — `belongsTo(Office)`, **no factory** (rows are created lazily per office). `hasAnswers()` tells "filled in" from "opened once".
- `App\Models\Office::internetConnectivitySurvey()` — the inverse `hasOne`.
- `App\Policies\InternetConnectivitySurveyPolicy` — `view`/`update` (own office, or any office with `.view_all`) and `viewAny` (the cross-office list). No `create`/`delete`.

Audit: `LogsActivity`, `updated` events only (`internet_connectivity_survey.updated`).

## Access model

Identical to Stakeholder Profile, with `internet_connectivity.view`/`.edit` (own office only; another office returns 403 without creating its row) and `internet_connectivity.view_all` (any office plus the list, seeded to `admin` only). A `.view`/`.edit` holder with no `office_id` has nothing to open and the sidebar hides the item.

## API endpoints

Controller: `Api\Management\InternetConnectivitySurveyController`. `{office}` is an `offices.id`.

| Method + path | Route permission | Policy | Request → response |
|---|---|---|---|
| `GET /api/internet-connectivity-surveys?search&per_page` | `internet_connectivity.view_all` | `viewAny` | paginated **offices**: `{id, office_name, office_type, school_id, has_survey, updated_at}` (`updated_at` is the survey's, `null` until answered) |
| `GET /api/internet-connectivity-surveys/options` | `.view` or `.view_all` | — | `{data: {isp_provider, signal_quality, mobile_network_signal, coverage_area, source_of_electricity}}` — `signal_quality` is a Tier 2 string list; the others are id-valued (`isp_providers` ids, `connectivity_libraries` ids, and `stakeholder_libraries` ids for electricity) |
| `GET /api/internet-connectivity-surveys/{office}` | `.view` or `.view_all` | `view` on a transient model, **before** `firstOrCreate` | `{data: {office: {id, office_name, school_id}, survey: InternetConnectivitySurveyResource}}` |
| `PUT /api/internet-connectivity-surveys/{office}` | `.edit` or `.view_all` | `update` on a transient model | `UpdateInternetConnectivitySurveyRequest` → same shape as GET |

**Writable fields / resource** (all optional): `has_isp_in_area`, `available_isps` + `subscribed_isps` (arrays of active `isp_providers` ids), `available_isps_other`, `mobile_signal_types` and `coverage_areas` (arrays of `connectivity_libraries` ids), `coverage_areas_other`, `has_mobile_data_connectivity`, `mobile_data_quality`, `subscribes_to_isp`, `insufficient_bandwidth_explanation`, `dict_free_wifi_recipient`, `dict_free_wifi_rating`, `has_sufficient_bandwidth`, `no_subscription_reason`, `has_electricity_source`, `electricity_sources` (array of `stakeholder_libraries` ids), `primarily_solar_powered`, `frequent_brownouts`, `rooms_other_use`. The resource adds `id`, `office_id`, `updated_at`.

## Frontend

| Route | Page |
|---|---|
| `/internet-connectivity-surveys` | `pages/management/internet-connectivity-surveys/ConnectivitySurveyList.tsx` (`.view_all`) |
| `/internet-connectivity-surveys/:officeId` | `pages/management/internet-connectivity-surveys/ConnectivitySurveyEdit.tsx` |

Form body: `components/internet-connectivity-surveys/ConnectivitySurveyFields.tsx`; API: `services/connectivitySurveyService.ts`; types: `types/internet-connectivity-survey.ts`. Sidebar branching (list vs own office) as for Stakeholder Profile.

## Key files

- `backend/app/Http/Controllers/Api/Management/InternetConnectivitySurveyController.php`
- `backend/app/Http/Requests/Management/UpdateInternetConnectivitySurveyRequest.php`
- `backend/app/Http/Resources/Management/InternetConnectivitySurveyResource.php`, `ConnectivitySurveyOfficeResource.php`
- `backend/app/Models/InternetConnectivitySurvey.php`, `Office.php`, `backend/app/Policies/InternetConnectivitySurveyPolicy.php`
- `backend/database/migrations/2026_07_15_090000_convert_internet_connectivity_surveys_to_office_scoped.php`

## Non-obvious design decisions

- **One row per office, lazily created**, authorized against a transient instance before `firstOrCreate` so a wrong-office request never creates a row. Same as Stakeholder Profile.
- **`has_survey` uses `hasAnswers()`**, because opening the page already persists an empty row.
- **The pre-existing singleton row was discarded, not attached to an office** — a product-owner call. Unlike Stakeholder Profile's empty singleton, it held real seeded answers from the source workbook, but nothing said which office that division-wide answer set belonged to, and guessing would misattribute one office's data to another. `InternetConnectivitySurveySeeder` was deleted with it; every survey starts empty.
- **No aggregates panel.** The old "Protected summary" (Total ISPs, Total Cost/month, ...) was removed on 2026-07-15: `isp_accounts` has no `office_id`, so every office showed identical division-wide totals. While it existed it was additionally hidden from users without `isp_accounts.view` (a security-review finding). It was not ported to the API.
- **`office_id` is `NOT NULL` + unique**, replacing the old `singleton_guard`. This table's `down()` drops the FK with `dropConstrainedForeignId()` alone, because MySQL uses the unique index to back the FK.

## Future considerations

- A per-office ISP spend summary could return if `isp_accounts` gains an `office_id` (a `database`/`backend` schema change).
