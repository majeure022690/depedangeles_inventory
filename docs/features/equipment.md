# Equipment

## Purpose

The ICT asset register for the division office: property number, item/spec details, acquisition/funding info, condition/disposition, and a full history of who has been accountable for each physical device over time. This is the module the accountability-transfer pattern (see [`docs/architecture.md`](../architecture.md#1-accountability-transfer-equipment)) exists for.

## Architecture

- `App\Models\Equipment` — the asset record. Soft-deletes. Holds `current_accountable_officer_id` / `current_end_user_id`, both `belongsTo(Personnel::class)`. Tier 1 reference fields are FKs (`item_type_id`, `brand_id`, `equipment_category_id`, `equipment_classification_id`, `equipment_condition_id`).
- `App\Models\EquipmentTransaction` — append-only log, one row per accountability/lifecycle event. `belongsTo(Personnel)` three ways (`accountableOfficer`, `endUser`, `receivedBy`) plus `belongsTo(User)` as `recordedBy` — the system user who entered it, distinct from the Personnel it's about.
- `App\Services\EquipmentAccountabilityService` — the only sanctioned way to record a transaction and move the `current_*` pointers, atomically in one DB transaction.
- `App\Policies\EquipmentPolicy` — `viewAny`/`view`/`create`/`update`/`delete` plus `createTransaction`, each checked against its own `Permission` case.
- `App\Observers\EquipmentObserver` — fills `qr_code` with `Equipment::qrCodeUrl()` once the row has an id (see QR below).

Audit: create/update/delete come from `LogsActivity` (`equipment.created|updated|deleted`, `current_*` excluded); recording a transaction logs `equipment.transaction.recorded`.

## API endpoints

All under `auth:sanctum` + `throttle:authenticated-api`. Controllers: `Api\Management\EquipmentController`, `EquipmentTransactionController`.

| Method + path | Route permission | Policy | Request → response |
|---|---|---|---|
| `GET /api/equipment?search&condition&category&disposition_status&sort&direction&per_page` | `equipment.view` | `viewAny` | paginated `EquipmentResource` (no `transactions`). `condition`/`category` are Tier 1 ids, `disposition_status` a string. Sort keys: `property_no`, `serial_number`, `model`, `disposition_status`, `acquisition_cost`, `received_date`, `created_at`; default puts items without a property number last. |
| `GET /api/equipment/options` | `equipment.view` | — | see options below |
| `POST /api/equipment` | `equipment.create` | `create` (Form Request) | `StoreEquipmentRequest` → `EquipmentResource`, 201. Creates the asset only: no transaction, no accountable officer. |
| `GET /api/equipment/{id}` | `equipment.view` | `view` | `EquipmentResource` with `transactions[]` (newest first) |
| `PUT /api/equipment/{id}` | `equipment.edit` | `update` | `UpdateEquipmentRequest` → `EquipmentResource` |
| `DELETE /api/equipment/{id}` | `equipment.delete` | `delete` (denial audited) | 204, soft delete |
| `GET /api/equipment/{id}/qr-code` | `equipment.view` | `view` | `image/png` (300px, `inline`). The SPA fetches it as a blob through axios; a plain `<img src>` would not send the session cookie cross-origin. |
| `POST /api/equipment/{id}/transactions` | `equipment.transactions.create` | `createTransaction` (denial audited) | `StoreEquipmentTransactionRequest` → `EquipmentTransactionResource`, 201 |

There is no update or delete endpoint for transactions, by design.

The list and every per-record action (show, QR, update, delete, transactions) are also limited by the role's [data scope](roles-and-permissions.md#data-scope). Under `own`/`school` a user only reaches items currently issued to personnel within their scope, and an out-of-scope id gets 403. `POST` and `/options` are not scoped.

**Request fields** (`StoreEquipmentRequest`; Update is the same with `property_no` unique-ignoring itself). Required: `item_type_id`, `brand_id`, `equipment_category_id`, `equipment_classification_id`, `equipment_condition_id` (active Tier 1 ids), `uom`, `mode_acquisition`, `source_acquisition`, `source_fund`, `allotment_class`, `disposition_status` (active Tier 2 values). Optional: `property_no` (unique), `old_property_no`, `serial_number`, `model`, `specifications`, `non_dcp`, `dcp_package`, `dcp_year`, `gl_sl_code`, `uacs`, `acquisition_cost`, `received_date`, `estimated_useful_life`, `donor`, `pm_plan`, `equipment_location`, `non_functional`, `remarks`, `under_warranty`, `end_warranty_date`, `supplier`. The `current_*` pointers are never accepted.

**Transaction fields:** `transaction_type` (required, Tier 2), `accountable_officer_id`, `end_user_id`, `received_by_id` (Personnel ids), `date_assigned_accountable_officer`, `date_assigned_end_user`, `date_received_new_accountable`, `supporting_documents1`/`supporting_documents2` (Tier 2 supporting-document types), `or_si_dr_iar_no`, `par_ics_rrsp_rs_wmr_no`.

### `EquipmentResource`

Every request field above, plus `id`, `qr_code`, `created_at`, `updated_at`, and:

- Tier 1 display names under their historical keys next to the FK id: `item`, `brand_manufacturer`, `category`, `classification`, `equipment_condition`.
- `acquisition_cost` as a float, or `null` ("not on file yet" is not zero). Dates as `Y-m-d`.
- `current_accountable_officer`, `current_end_user`: `{id, full_name} | null`.
- `transactions` (detail only): `{id, equipment_id, transaction_type, accountable_officer, end_user, received_by, date_*..., supporting_documents1, or_si_dr_iar_no, supporting_documents2, par_ics_rrsp_rs_wmr_no, created_at}`, people as `{id, full_name} | null`.

### `GET /api/equipment/options`

```
{ data: {
    form:        { item_type, brand, category, classification, condition,          // Tier 1, id-valued
                   unit_of_measure, dcp_package, mode_of_acquisition, source_of_acquisition,
                   source_of_funds, allotment_class, disposition_status },        // Tier 2, string-valued
    filters:     { condition, category, disposition_status },
    transaction: { transaction_type, supporting_document_type },
    personnel?:  [{ value: id, label: full_name }]   // active personnel; only for equipment.transactions.create holders
} }
```

`personnel` is withheld from users who cannot record transactions because it only feeds the transaction form, and it is a staff directory.

## Frontend

| Route | Page |
|---|---|
| `/equipment` | `pages/management/equipment/EquipmentList.tsx` |
| `/equipment/new` | `pages/management/equipment/EquipmentCreate.tsx` (`equipment.create`) |
| `/equipment/:id/edit` | `pages/management/equipment/EquipmentEdit.tsx` — record, QR image with print/download, transaction history and form |
| `/equipment/:id/edit` | alias redirecting to the portal edit page (QR scans) |

Form body: `components/equipment/EquipmentFields.tsx`; history and transaction form: `components/equipment/TransactionLog.tsx`; API: `services/equipmentService.ts`; types: `types/equipment.ts`.

## Key files

- `backend/app/Http/Controllers/Api/Management/EquipmentController.php`, `EquipmentTransactionController.php`
- `backend/app/Http/Requests/Management/StoreEquipmentRequest.php`, `UpdateEquipmentRequest.php`, `StoreEquipmentTransactionRequest.php`
- `backend/app/Http/Resources/Management/EquipmentResource.php`, `EquipmentTransactionResource.php`
- `backend/app/Models/Equipment.php`, `EquipmentTransaction.php`, `backend/app/Observers/EquipmentObserver.php`
- `backend/app/Services/EquipmentAccountabilityService.php`, `backend/app/Exceptions/AccountabilitySyncRequiredException.php`
- `backend/app/Policies/EquipmentPolicy.php`
- `backend/routes/web.php` — the QR redirect route
- `backend/app/Console/Commands/RegenerateEquipmentQrCodes.php` — `equipment:regenerate-qr-codes`

## Non-obvious design decisions

- **Accountability sync guard.** `Equipment`'s `updating` hook throws `AccountabilitySyncRequiredException` if `current_accountable_officer_id`/`current_end_user_id` is dirty outside `Equipment::withAccountabilitySync()` — the closure `EquipmentAccountabilityService::recordTransaction()` runs inside. A controller (now or later) cannot drift the pointers out of sync with the log. The guard fires only on `UPDATE`, not `CREATE`: initial creation (factories, seeders, a future import) has no prior holder to reconcile.
- **"Last transaction wins," always.** `current_*` is overwritten to match the new transaction, including `null` when the transaction names no one. No per-transaction-type branching, so the invariant stays trivial.
- **`equipment.transactions.create` is its own permission**, separate from `equipment.edit`: reassigning accountability for a physical asset is more sensitive than editing its description. `encoder` holds both, but a role can hold one without the other.
- **QR labels must outlive the URL scheme.** Labels encode `APP_URL/equipment/{id}/edit`. That path stayed valid through the SPA conversion via the backend redirect and the SPA alias. Don't change the observer's URL without a plan for already-printed labels.
- **QR URLs come from `APP_URL`, never the request host.** The observer and the `/qr-code` fallback both use `Equipment::qrCodeUrl()`. Until 2026-10-23 the observer used `route('equipment.edit')`, which takes its host from the incoming request, so a spoofed `Host`/`X-Forwarded-Host` on a create request could store a foreign URL that then got printed on a physical label. That is a phishing vector which outlives any server-side fix. (For the same reason, trusted proxies now honour only `X-Forwarded-For` by default: see [`architecture.md`](../architecture.md#trusted-proxy-headers).)
- **Existing rows: `php artisan equipment:regenerate-qr-codes`.** Run it once after deploying that change (see the README's Plesk steps), and again if `APP_URL` ever changes.
  - Without flags it's a dry run. It lists every row whose `qr_code` isn't `qrCodeUrl()`, soft-deleted rows included, as `#id property_no: old -> new`, and changes nothing.
  - `--force` rewrites those rows and writes one `equipment.qr_code.regenerated` activity row each, keeping the old value in `properties.old.qr_code`.
  - Review the dry run before forcing. A mismatch can be harmless, e.g. a row created before `APP_URL` changed. A row pointing at a host the division never used should be treated as a possible phishing label: find it and physically reprint it, don't just rewrite the database. Any rewritten row's printed label still encodes the old URL until it is reprinted.
- **No `employee_id = 9999` "unknown officer" placeholder.** Unknown/unassigned is a `null` FK, not a sentinel Personnel row.

## Future considerations

None blocking. `EquipmentTransaction` has no factory; add one if transaction-focused test coverage grows.
