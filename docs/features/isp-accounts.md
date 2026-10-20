# ISP Accounts

## Purpose

The office's internet service subscriptions — provider, plan, cost, contract dates, coverage — each with two independent, growing histories: speed tests over time and subscription-cost/budget periods over time. This is the module the append-only child-log pattern (see [`docs/architecture.md`](../architecture.md#2-append-only-child-log-isp-accounts)) exists for; unlike Equipment there is no "current state" pointer on the parent to keep in sync.

## Architecture

- `App\Models\IspAccount` — soft-deletes, `isp_provider_id` (Tier 1 FK). `hasMany(IspSpeedTest)`, `hasMany(IspSubscriptionCost)`.
- `App\Models\IspSpeedTest`, `IspSubscriptionCost` — append-only, no soft-deletes. Each `belongsTo(User)` as `recordedBy`.
- `App\Policies\IspAccountPolicy` — `viewAny`/`view`/`create`/`update`/`delete`; `update` also gates both child logs; `delete` denials are audited.

Audit: account CRUD via `LogsActivity` (`isp_account.created|updated|deleted`); logging a reading writes `isp_account.speed_test.recorded` / `isp_account.subscription_cost.recorded` against the parent account.

## API endpoints

Controllers: `Api\Management\IspAccountController`, `IspSpeedTestController`, `IspSubscriptionCostController`. The route parameter is `{ispAccount}` (camelCase) so the same name works in the nested routes and in `$this->route('ispAccount')`.

| Method + path | Route permission | Policy | Request → response |
|---|---|---|---|
| `GET /api/isp-accounts?search&isp&status&sort&direction&per_page` | `isp_accounts.view` | `viewAny` | paginated `IspAccountResource`. `isp` = provider id; `status` = `active`/`inactive` contract. Sort keys: `isp_billing_account_no`, `cost_per_month`, `contract_start_date`, `contract_end_date`, `created_at`; default groups by provider. |
| `GET /api/isp-accounts/options` | `isp_accounts.view` | — | `{data: {form: {isp_provider, school_level_coverage, subscription_type, isp_connection_type, purpose_of_subscription, signal_quality, mode_of_acquisition, source_of_acquisition, source_of_funds}, filters: {isp_provider}, speed_test: {signal_quality}}}`. `isp_provider` is id-valued; the rest are Tier 2 strings (the last three read from Equipment's library on purpose). |
| `POST /api/isp-accounts` | `isp_accounts.create` | `create` (Form Request) | `StoreIspAccountRequest` → `IspAccountResource`, 201 |
| `GET /api/isp-accounts/{ispAccount}` | `isp_accounts.view` | `view` | `IspAccountResource` with `speed_tests[]` and `subscription_costs[]` |
| `PUT /api/isp-accounts/{ispAccount}` | `isp_accounts.edit` | `update` | `UpdateIspAccountRequest` → `IspAccountResource` |
| `DELETE /api/isp-accounts/{ispAccount}` | `isp_accounts.delete` | `delete` | 204, soft delete |
| `POST /api/isp-accounts/{ispAccount}/speed-tests` | `isp_accounts.edit` | parent `update` | `StoreIspSpeedTestRequest` → `IspSpeedTestResource`, 201 |
| `POST /api/isp-accounts/{ispAccount}/subscription-costs` | `isp_accounts.edit` | parent `update` | `StoreIspSubscriptionCostRequest` → `IspSubscriptionCostResource`, 201 |

No update/delete endpoints exist for either log.

**Account fields:** required `isp_provider_id`, `cost_per_month`, `isp_billing_account_no`, `package_purchased_inclusion`, `school_area_coverage`, `subscription_type`, `isp_connection_type`, `purpose_of_subscription`, `mode_of_acquisition`, `source_of_acquisition`, `fund_source`, `overall_signal_quality`; optional `min_speed`, `max_speed` (≥ min), `contract_start_date`, `contract_end_date`, `inactive_contract`, `donor`, `number_access_points_linked`, `locations_access_points`, `number_admin_area_covered`, `rate_connectivity_admin_areas`, `number_classrooms_covered`, `rate_connectivity_classroom`, `rate_isp_service` (1-5).

**Speed test fields:** required `download_speed`, `upload_speed`, `tested_at` (datetime, converted to Manila time — see [timezone](../architecture.md#timezone-asiamanila)), `signal_quality`; optional `ping`, `rate_isp_service`.
**Subscription cost fields (all optional):** `contract_start_date`, `contract_end_date`, `total_amount_spent`, `contract_projected_start_date`, `contract_projected_end_date`, `total_projected_expenditure`.

**`IspAccountResource`:** every account field plus `id`, `isp` (provider name), `created_at`, `updated_at`; money/speeds as floats. On the detail endpoint, `speed_tests[]` = `{id, isp_account_id, download_speed, upload_speed, ping, tested_at, signal_quality, rate_isp_service, created_at}` and `subscription_costs[]` = `{id, isp_account_id, contract_start_date, contract_end_date, total_amount_spent, contract_projected_start_date, contract_projected_end_date, total_projected_expenditure, created_at}`, both newest-logged first.

## Frontend

| Route | Page |
|---|---|
| `/isp-accounts` | `pages/management/isp-accounts/IspAccountList.tsx` |
| `/isp-accounts/new` | `pages/management/isp-accounts/IspAccountCreate.tsx` (`isp_accounts.create`) |
| `/isp-accounts/:id/edit` | `pages/management/isp-accounts/IspAccountEdit.tsx` |

Components: `components/isp-accounts/IspAccountFields.tsx`, `SpeedTestLog.tsx`, `SubscriptionCostLog.tsx`; API: `services/ispAccountService.ts`; types: `types/isp-account.ts`.

## Key files

- `backend/app/Http/Controllers/Api/Management/IspAccountController.php`, `IspSpeedTestController.php`, `IspSubscriptionCostController.php`
- `backend/app/Http/Requests/Management/StoreIspAccountRequest.php`, `UpdateIspAccountRequest.php`, `StoreIspSpeedTestRequest.php`, `StoreIspSubscriptionCostRequest.php`
- `backend/app/Http/Resources/Management/IspAccountResource.php`, `IspSpeedTestResource.php`, `IspSubscriptionCostResource.php`
- `backend/app/Models/IspAccount.php`, `IspSpeedTest.php`, `IspSubscriptionCost.php`, `backend/app/Policies/IspAccountPolicy.php`

## Non-obvious design decisions

- **No dedicated permission for logging readings.** Unlike Equipment's `equipment.transactions.create` (which reassigns accountability for a physical asset), a speed test or cost period is just an append-only measurement. It rides on `isp_accounts.edit` and authorizes against the parent's `update`.
- **`min_speed`/`max_speed` live on the account, not on each test.** The source workbook repeats them per test row, an artifact of its flat sheet. `isp_accounts` models one current plan; compare a test's `download_speed`/`upload_speed` against the parent. A real plan-history need would be a new table, not duplicated columns.
- **`tested_at` vs `created_at`.** `tested_at` is when the test physically ran (can be backdated during an import); `created_at` is when it was logged. Both histories are ordered by `created_at`, so a backdated entry doesn't jump around the list.
- **The status filter is a hard-coded active/inactive pair**, not reference data — `inactive_contract` is a boolean column, not a vocabulary.

## Future considerations

None blocking.
