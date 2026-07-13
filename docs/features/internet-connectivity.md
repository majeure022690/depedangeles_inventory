# Internet Connectivity Survey

## Purpose

A single record capturing the division office's own connectivity situation — available ISPs, mobile signal, electricity source, coverage areas, DICT free WiFi — mirroring the source Excel workbook's single 27-question "Internet Connectivity" sheet. Like Stakeholder Profile, this is a singleton (see [`docs/architecture.md`](../architecture.md#3-singleton-stakeholder-profile-internet-connectivity-survey)), but it additionally ships a **live-computed aggregates panel** sourced from ISP Accounts — the second data-access pattern this module demonstrates alongside the singleton pattern.

## Architecture

- `App\Models\InternetConnectivitySurvey` — bare structural model, fillable/casts only, no factory, no relationships. No FK to `isp_accounts`/`isp_subscription_costs` — deliberately, since the aggregate values are computed, not stored (see below).
- `App\Policies\InternetConnectivitySurveyPolicy` — only `view`/`update`, same shape as `StakeholderProfilePolicy`.
- `App\Http\Controllers\InternetConnectivitySurveyController` — only `edit()`/`update()`, plus a private `computeAggregates()`.

## Flow

1. **Edit** (`GET /internet-connectivity-survey`, `internet-connectivity-survey.edit`) — authorizes against a transient instance before touching the database, `firstOrCreate([])`s the singleton row, computes the live aggregates panel, renders both.
2. **Update** (`PUT /internet-connectivity-survey`, `internet-connectivity-survey.update`) — same `firstOrCreate([])`, then updates the survey's own (non-aggregate) fields.

No `index`/`create`/`store`/`destroy` — same hand-declared, no-`{id}` route shape as Stakeholder Profile.

Every update writes an `AuditLog` entry (`internet_connectivity_survey.updated`) with a field-level before/after diff.

## Inertia routes / prop contracts

Requires `auth`+`verified`. Controller: `App\Http\Controllers\InternetConnectivitySurveyController`.

| Route | Page component | Key props |
|---|---|---|
| `GET /internet-connectivity-survey` (`internet-connectivity-survey.edit`) | `internet-connectivity-survey/Edit` | `internetConnectivitySurvey` (the singleton record), `aggregates` (read-only, live-computed), `options` (ISP provider, mobile network signal, signal quality, coverage area, source-of-electricity lookups) |

`internetConnectivitySurvey` prop shape:

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
    'has_electricity_source', 'electricity_sources', 'primarily_solar_powered', 'frequent_brownouts',
    'rooms_other_use',
]
```

`aggregates` prop shape (all live-computed, none persisted anywhere):

```php
[
    'total_isps' => int,                    // count of isp_accounts rows
    'total_cost_per_month' => float,        // sum(isp_accounts.cost_per_month)
    'total_amount_spent' => float,          // sum(isp_subscription_costs.total_amount_spent)
    'total_projected_expenditure' => float, // sum(isp_subscription_costs.total_projected_expenditure)
    'rooms_covered_admin' => int,           // sum(isp_accounts.number_admin_area_covered)
    'rooms_covered_classroom' => int,       // sum(isp_accounts.number_classrooms_covered)
    'total_access_points' => int,           // sum(isp_accounts.number_access_points_linked)
]
```

## Key files

- `app/Http/Controllers/InternetConnectivitySurveyController.php`
- `app/Models/InternetConnectivitySurvey.php`
- `app/Policies/InternetConnectivitySurveyPolicy.php`
- `app/Http/Requests/InternetConnectivitySurveyUpdateRequest.php`
- `database/migrations/2026_07_10_090001_create_internet_connectivity_surveys_table.php`, `2026_07_11_000000_add_singleton_guard_to_stakeholder_profiles_and_internet_connectivity_surveys_tables.php`
- `resources/js/pages/internet-connectivity-survey/` (`Edit.vue`, `Partials/`)

## Non-obvious design decisions

- **Aggregates are never columns on this table, by design.** The source survey sheet marks Total ISPs, Total Cost/month, Total Amount Spent, Total Projected Expenditure, Rooms Covered (Admin/Classroom), and Total Access Points as "Protected, source data from..." — i.e. derived, not user-entered. Storing them here would mean they go stale the instant the underlying `IspAccount`/`IspSubscriptionCost` rows change, with no reliable way to know when to recompute. `InternetConnectivitySurveyController::computeAggregates()` queries fresh on every page load instead — correct by construction, at the cost of a handful of aggregate queries per view (acceptable at this application's scale). Because `IspAccount` uses soft deletes and its default query scope excludes trashed rows, these totals automatically only reflect active accounts with no extra filtering needed.
- **`rooms_other_use` is the one exception** — genuinely free-text user input in the source (not "protected"/derived), so unlike its neighbors it's a real column on this table.
- **No FK from this table to `isp_accounts`/`isp_subscription_costs`.** The relationship is "query them separately when rendering the survey," not a stored one — there's deliberately no Eloquent relationship method here for that reason.
- **Singleton pattern and its DB-level race-condition fix** are shared with Stakeholder Profile — see [`docs/architecture.md`](../architecture.md#3-singleton-stakeholder-profile-internet-connectivity-survey) rather than duplicating that explanation here.

## Future considerations

None flagged as blocking during review.
