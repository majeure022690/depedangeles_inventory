# Internet Connectivity Survey

## Purpose

A survey of a school or division-level office's connectivity situation — ISP availability and subscriptions, mobile signal/data quality, coverage areas, DICT free Wi-Fi, electricity source/reliability. One form per office, shared by every user assigned to that office.

Started (2026-07-10) as a global singleton; converted (2026-07-15) to **one row per `App\Models\Office`**, one day after Stakeholder Profile's identical conversion. The shared pattern is in [`docs/architecture.md`](../architecture.md#3-one-row-per-office-stakeholder-profile-internet-connectivity-survey); [`stakeholder-profile.md`](stakeholder-profile.md) is the precedent.

## Architecture

- `App\Models\InternetConnectivitySurvey` — `belongsTo(Office)`, **no factory** (rows are created lazily per office). `hasAnswers()` tells "filled in" from "opened once".
- `App\Models\Office::internetConnectivitySurvey()` — the inverse `hasOne`.
- `App\Policies\InternetConnectivitySurveyPolicy` — `view`/`update` (`.view`/`.edit` plus `User::reachesOffice()`) and `viewAny` (the cross-office list: `.view` and data scope `all`). No `create`/`delete`.

Audit: `LogsActivity`, `updated` events only (`internet_connectivity_survey.updated`).

## Access model

Identical to [Stakeholder Profile's](stakeholder-profile.md#access-model), with `internet_connectivity.view`/`.edit`: `own` and `school` reach only the user's own office (another office returns 404 without creating its row, the list 403), `all` reaches every office plus the list. A scoped user with no `office_id` has nothing to open and the sidebar hides the item. `internet_connectivity.view_all` was removed on 2026-10-26 the same way as `stakeholder_profile.view_all`.

## API endpoints

Controller: `Api\Management\InternetConnectivitySurveyController`. `{office}` is an `offices.id`.

| Method + path | Route permission | Policy | Request → response |
|---|---|---|---|
| `GET /api/internet-connectivity-surveys?search&per_page` | `internet_connectivity.view` | `viewAny` (scope `all`) | paginated **offices**: `{id, office_name, office_type, school_id, has_survey, updated_at}` (`updated_at` is the survey's, `null` until answered) |
| `GET /api/internet-connectivity-surveys/options` | `.view` | — | `{data: {isp_provider, signal_quality, mobile_network_signal, coverage_area, source_of_electricity}}` — `signal_quality` is a Tier 2 string list; the others are id-valued (`isp_providers` ids, `connectivity_libraries` ids, and `stakeholder_libraries` ids for electricity) |
| `GET /api/internet-connectivity-surveys/{office}` | `.view` | `view` on a transient model, **before** `firstOrCreate` | `{data: {office: {id, office_name, school_id}, survey: InternetConnectivitySurveyResource}}` |
| `PUT /api/internet-connectivity-surveys/{office}` | `.edit` | `update` on a transient model | `UpdateInternetConnectivitySurveyRequest` → same shape as GET |

**Writable fields / resource** (all optional): `has_isp_in_area`, `available_isps` + `subscribed_isps` (arrays of active `isp_providers` ids), `available_isps_other`, `mobile_signal_types` and `coverage_areas` (arrays of `connectivity_libraries` ids), `coverage_areas_other`, `has_mobile_data_connectivity`, `mobile_data_quality`, `subscribes_to_isp`, `insufficient_bandwidth_explanation`, `dict_free_wifi_recipient`, `dict_free_wifi_rating`, `has_sufficient_bandwidth`, `no_subscription_reason`, `has_electricity_source`, `electricity_sources` (array of `stakeholder_libraries` ids), `primarily_solar_powered`, `frequent_brownouts`, `rooms_other_use`. The resource adds `id`, `office_id`, `updated_at`.

## Frontend

| Route | Page |
|---|---|
| `/internet-connectivity-surveys` | `pages/management/internet-connectivity-surveys/ConnectivitySurveyList.tsx` (`.view`, scope `all`) |
| `/internet-connectivity-surveys/:officeId` | `pages/management/internet-connectivity-surveys/ConnectivitySurveyEdit.tsx` |

Form body: `components/internet-connectivity-surveys/ConnectivitySurveyFields.tsx`; API: `services/connectivitySurveyService.ts`; types: `types/internet-connectivity-survey.ts`. Sidebar branching as for Stakeholder Profile: "Internet Connectivity" (list) for `all`, "Our Office Connectivity" (own office) otherwise.

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
- **No aggregates panel.** The old "Protected summary" (Total ISPs, Total Cost/month, ...) was removed on 2026-07-15: `isp_accounts` had no `office_id` then, so every office showed identical division-wide totals. While it existed it was additionally hidden from users without `isp_accounts.view` (a security-review finding). It was not ported to the API.
- **`office_id` is `NOT NULL` + unique**, replacing the old `singleton_guard`. This table's `down()` drops the FK with `dropConstrainedForeignId()` alone, because MySQL uses the unique index to back the FK.

## Future considerations

- A per-office ISP spend summary is now possible: `isp_accounts.office_id` was added on 2026-10-26 (for data scope). It is only reliable once production ISP accounts have their office backfilled.
