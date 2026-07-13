# ISP Accounts

## Purpose

The office's internet service subscriptions — provider, plan, cost, contract dates, coverage — each with two independent, growing histories: speed-test results over time and subscription-cost/budget periods over time. This is the module the append-only child-log pattern (see [`docs/architecture.md`](../architecture.md#2-append-only-child-log-isp-accounts)) exists for, distinct from Equipment's accountability-transfer pattern because there's no "current state" pointer on the parent that needs syncing.

## Architecture

- `App\Models\IspAccount` — soft-deletes. `hasMany(IspSpeedTest)`, `hasMany(IspSubscriptionCost)`.
- `App\Models\IspSpeedTest` — append-only, no soft-deletes (nothing to "undo," only to append to). `belongsTo(IspAccount)`, `belongsTo(User)` as `recordedBy`.
- `App\Models\IspSubscriptionCost` — same shape as `IspSpeedTest`, budget-period history instead of bandwidth measurements.
- `App\Policies\IspAccountPolicy` — standard `viewAny`/`view`/`create`/`update`/`delete`; `update` doubles as the authorization gate for both nested logs (see below).

## Flow

1. **List** (`GET /isp-accounts`, `isp-accounts.index`) — searchable, filterable by provider and active/inactive contract status, paginated 15/page.
2. **Create** (`GET /isp-accounts/create`, `isp-accounts.create`) / **Store** (`POST /isp-accounts`, `isp-accounts.store`).
3. **Edit** (`GET /isp-accounts/{ispAccount}/edit`, `isp-accounts.edit`) — shows the account plus both history lists (each ordered by `created_at` descending — row-insertion order, not the domain date columns like `tested_at`/contract dates, which can legitimately be backdated relative to when they were logged).
4. **Log a speed test** (`POST /isp-accounts/{ispAccount}/speed-tests`, `isp-accounts.speed-tests.store`) — append-only, no update/destroy route.
5. **Log a subscription cost period** (`POST /isp-accounts/{ispAccount}/subscription-costs`, `isp-accounts.subscription-costs.store`) — append-only, no update/destroy route.
6. **Update** (`PUT/PATCH /isp-accounts/{ispAccount}`, `isp-accounts.update`) — edits the account's own fields.
7. **Delete** (`DELETE /isp-accounts/{ispAccount}`, `isp-accounts.destroy`) — soft delete.

Every create/update/delete writes an `AuditLog` entry (`isp_account.created`/`.updated`/`.deleted`); logging a speed test or subscription cost writes `isp_account.speed_test.recorded` / `isp_account.subscription_cost.recorded`.

## Inertia routes / prop contracts

All routes require `auth`+`verified`. Controllers: `App\Http\Controllers\IspAccountController`, `IspSpeedTestController`, `IspSubscriptionCostController`.

The resource's route parameter is explicitly mapped to `ispAccount` (camelCase) rather than the hyphenated resource name's default snake_case derivation, specifically so the same wildcard name works verbatim in the nested `speed-tests`/`subscription-costs` routes and in the three Form Requests' `$this->route('ispAccount')` lookups — no snake/camel mismatch to remember at each call site (see `routes/web.php` comment).

| Route | Page component | Key props |
|---|---|---|
| `GET /isp-accounts` (`isp-accounts.index`) | `isp-accounts/Index` | `ispAccounts` (paginated), `filters` (`search`, `isp`, `status`), `options` (ISP provider lookup, for the filter dropdown) |
| `GET /isp-accounts/create` (`isp-accounts.create`) | `isp-accounts/Create` | `options` (provider, school-level coverage, subscription type, connection type, purpose, mode/source of acquisition, source of funds, signal quality lookups) |
| `GET /isp-accounts/{ispAccount}/edit` (`isp-accounts.edit`) | `isp-accounts/Edit` | `ispAccount` (full record + `speed_tests[]` + `subscription_costs[]`), `options` (form lookups), `speedTestOptions` (signal-quality lookup, for the speed-test log form) |

`isp-accounts.index` row shape:

```php
[
    'id', 'isp', 'isp_billing_account_no', 'subscription_type', 'isp_connection_type',
    'cost_per_month' /* float */, 'contract_start_date', 'contract_end_date',
    'inactive_contract', 'overall_signal_quality',
]
```

`isp-accounts.edit`'s `ispAccount` prop includes every fillable IspAccount column plus:

```php
'speed_tests' => [
    ['id', 'download_speed' /* float */, 'upload_speed' /* float */, 'ping',
     'tested_at', 'signal_quality', 'rate_isp_service', 'created_at'],
    ...
],
'subscription_costs' => [
    ['id', 'contract_start_date', 'contract_end_date', 'total_amount_spent' /* float|null */,
     'contract_projected_start_date', 'contract_projected_end_date',
     'total_projected_expenditure' /* float|null */, 'created_at'],
    ...
],
```

## Key files

- `app/Http/Controllers/IspAccountController.php`, `IspSpeedTestController.php`, `IspSubscriptionCostController.php`
- `app/Models/IspAccount.php`, `IspSpeedTest.php`, `IspSubscriptionCost.php`
- `app/Policies/IspAccountPolicy.php`
- `app/Http/Requests/IspAccountStoreRequest.php`, `IspAccountUpdateRequest.php`, `IspSpeedTestStoreRequest.php`, `IspSubscriptionCostStoreRequest.php`
- `database/migrations/2026_07_10_070151_create_isp_accounts_table.php`, `2026_07_10_070152_create_isp_speed_tests_table.php` (same file also creates `isp_subscription_costs`), `2026_07_10_080000_add_recorded_by_user_id_to_isp_speed_tests_and_subscription_costs_tables.php`
- `resources/js/pages/isp-accounts/` (`Index.vue`, `Create.vue`, `Edit.vue`, `Partials/`)

## Non-obvious design decisions

- **No dedicated permission for logging a speed test or subscription cost.** Unlike Equipment's `equipment.transactions.create` (which reassigns accountability for a physical asset — judged materially more sensitive than editing the asset itself), a speed-test/subscription-cost entry is just an append-only measurement/cost log with no accountability-transfer semantics. It rides along with `isp_accounts.edit`: whoever may edit the account may also log readings against it. Both nested store actions authorize via `$user->can('update', $ispAccount)` against the **parent** IspAccount, the same mechanism Equipment transactions use against the parent Equipment.
- **`min_speed`/`max_speed` (the promised plan bandwidth) are not duplicated onto `isp_speed_tests`.** The source Excel workbook repeats them on every test row, but that's an artifact of its flat one-sheet-per-entity shape, not a claim that the promised speed varies per test. `isp_accounts` models exactly one *current* plan per account (no versioned plan-history table), so a test row can only mean "measured against the account's current plan speed." Consumers read the promised range off the parent `IspAccount` (`min_speed`/`max_speed`) and compare it against the test row's `download_speed`/`upload_speed`. If a genuine plan-tier-history requirement appears later, that's a new `isp_account_plan_history` concern, not a reason to duplicate columns on every speed test.
- **`tested_at` vs. `created_at`.** `tested_at` is the user-entered/imported moment the physical test actually ran (can be backdated, e.g. during a bulk import); `created_at` is just when the row was inserted into this system. Both history lists in `isp-accounts.edit` are deliberately ordered by `created_at`, not the domain date column, for the same reason.
- **Status filter (`active`/`inactive` contract) is a plain hardcoded two-option dropdown, not reference-data-backed** — `inactive_contract` is a real boolean column, not a shared vocabulary list, so there's no reference-data row backing it.

## Future considerations

None flagged as blocking during review.
