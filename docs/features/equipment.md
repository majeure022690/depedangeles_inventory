# Equipment

## Purpose

The ICT asset register for the division office: property number, item/spec details, acquisition/funding info, condition/disposition, and a full history of who has been accountable for each physical device over time. This is the module the accountability-transfer pattern (see [`docs/architecture.md`](../architecture.md#1-accountability-transfer-equipment)) exists for.

## Architecture

- `App\Models\Equipment` — the asset record. Soft-deletes. Holds `current_accountable_officer_id` / `current_end_user_id`, both `belongsTo(Personnel::class)`. Tier 1 reference fields are FKs (`item_type_id`, `brand_id`, `equipment_category_id`, `equipment_classification_id`, `equipment_condition_id`).
- `App\Models\EquipmentTransaction` — append-only log, one row per accountability/lifecycle event. `belongsTo(Personnel)` three ways (`accountableOfficer`, `endUser`, `receivedBy`) plus `belongsTo(User)` as `recordedBy` — the system user who entered it, distinct from the Personnel it's about.
- `App\Services\EquipmentAccountabilityService` — the only sanctioned way to record a transaction and move the `current_*` pointers, atomically in one DB transaction.
- `App\Policies\EquipmentPolicy` — `viewAny`/`view`/`create`/`update`/`delete` plus `createTransaction`, each checked against its own `Permission` case.
- `App\Services\EquipmentPeripheralService` — desktop bundles: creates, links, unlinks and renumbers peripherals (see [Desktop bundles](#desktop-bundles)).
- `App\Observers\EquipmentObserver` — fills `qr_code` with `Equipment::qrCodeUrl()` once the row has an id (see QR below).

Audit: create/update/delete come from `LogsActivity` (`equipment.created|updated|deleted`, `current_*` excluded); recording a transaction logs `equipment.transaction.recorded`; bundle events log `equipment.peripheral.{created,linked,unlinked,renumbered}`.

## API endpoints

All under `auth:sanctum` + `throttle:authenticated-api`. Controllers: `Api\Management\EquipmentController`, `EquipmentTransactionController`, `EquipmentPeripheralController`.

| Method + path | Route permission | Policy | Request → response |
|---|---|---|---|
| `GET /api/equipment?search&condition&category&disposition_status&sort&direction&per_page` | `equipment.view` | `viewAny` | paginated `EquipmentResource` (no `transactions`). `condition`/`category` are Tier 1 ids, `disposition_status` a string. Sort keys: `property_no`, `serial_number`, `model`, `disposition_status`, `acquisition_cost`, `received_date`, `created_at`; default puts items without a property number last. |
| `GET /api/equipment/options` | `equipment.view` | — | see options below |
| `POST /api/equipment` | `equipment.create` | `create` (Form Request) | `StoreEquipmentRequest` → `EquipmentResource` with `parent` and `children`, 201. Creates the asset, plus any `peripherals[]` rows in the same DB transaction ([Desktop bundles](#desktop-bundles)). No transaction, no accountable officer. |
| `GET /api/equipment/{id}` | `equipment.view` | `view` | `EquipmentResource` with `transactions[]` (newest first), `parent`, `children`, `original_bundle`, `renumberable_peripherals_count` |
| `PUT /api/equipment/{id}` | `equipment.edit` | `update` | `UpdateEquipmentRequest` (optional `renumber_peripherals`) → `EquipmentResource` with `parent`, `children`, `original_bundle`, `renumberable_peripherals_count` |
| `DELETE /api/equipment/{id}` | `equipment.delete` | `delete` (denial audited) | 204, soft delete |
| `GET /api/equipment/{id}/qr-code` | `equipment.view` | `view` | `image/png` (300px, `inline`). The SPA fetches it as a blob through axios; a plain `<img src>` would not send the session cookie cross-origin. |
| `POST /api/equipment/{id}/transactions` | `equipment.transactions.create` | `createTransaction` (denial audited) | `StoreEquipmentTransactionRequest` → `EquipmentTransactionResource`, 201 |
| `POST /api/equipment/{id}/peripherals/batch` | `equipment.edit` + `equipment.transactions.create` | `update` + `create` + `createTransaction` on the desktop | `{peripherals: [1..10 rows]}` → `EquipmentResource[]` + `message`, 201. All rows or none. |
| `POST /api/equipment/{id}/peripherals` | same | same | one row, flat (`StoreEquipmentPeripheralRequest`; here `brand_id` and `equipment_condition_id` are required) → `EquipmentResource`, 201. Still routed, but the SPA uses `/batch`. |
| `POST /api/equipment/{id}/peripherals/link` | same | `update` + `createTransaction` on the desktop | `{equipment_id}` → `EquipmentResource` |
| `DELETE /api/equipment/{id}/peripherals/{peripheral}` | `equipment.edit` | `update` on the desktop and on the peripheral | 204; 404 unless the peripheral is linked to that desktop |

There is no update or delete endpoint for transactions, by design.

The list and every per-record action (show, QR, update, delete, transactions) are also limited by the role's [data scope](roles-and-permissions.md#data-scope), and an out-of-scope id gets **404** (403 only when the permission itself is missing). Equipment has no office column; scope goes through personnel and the creator:

- an item currently issued (accountable officer or end user) to personnel in the user's reach;
- an **unissued** item (no officer and no end user) the user created (`equipment.created_by_user_id`); under `school`, also unissued items created by any user whose `users.office_id` is the same as theirs, read at request time.

`POST` sets `created_by_user_id` from the session user; it isn't a request field and isn't in `EquipmentResource`. Migration `2026_10_26_000003` backfilled it from the `created` rows in `activity_log`, so items created before then by a user still in the system are attributed. Transaction targets (`accountable_officer_id`, `end_user_id`, `received_by_id`) must be personnel in the user's reach (422 "Choose someone within your access."), and the `/options` `personnel` list is limited the same way.

**Request fields** (`StoreEquipmentRequest`; Update is the same with `property_no` unique-ignoring itself). Required: `item_type_id`, `brand_id`, `equipment_category_id`, `equipment_classification_id`, `equipment_condition_id` (active Tier 1 ids), `uom`, `mode_acquisition`, `source_acquisition`, `source_fund`, `allotment_class`, `disposition_status` (active Tier 2 values). Optional: `property_no` (unique), `old_property_no`, `serial_number`, `model`, `specifications`, `non_dcp`, `dcp_package`, `dcp_year`, `gl_sl_code`, `uacs`, `acquisition_cost`, `received_date`, `estimated_useful_life`, `donor`, `pm_plan`, `equipment_location`, `non_functional`, `remarks`, `under_warranty`, `end_warranty_date`, `supplier`. The `current_*` pointers are never accepted. Create also takes `peripherals[]` and update takes `renumber_peripherals` (see [Desktop bundles](#desktop-bundles)).

**Transaction fields:** `transaction_type` (required, Tier 2), `accountable_officer_id`, `end_user_id`, `received_by_id` (Personnel ids), `date_assigned_accountable_officer`, `date_assigned_end_user`, `date_received_new_accountable`, `supporting_documents1`/`supporting_documents2` (Tier 2 supporting-document types), `or_si_dr_iar_no`, `par_ics_rrsp_rs_wmr_no`.

### `EquipmentResource`

Every request field above, plus `id`, `qr_code`, `created_at`, `updated_at`, and:

- Tier 1 display names under their historical keys next to the FK id: `item`, `brand_manufacturer`, `category`, `classification`, `equipment_condition`.
- `acquisition_cost` as a float, or `null` ("not on file yet" is not zero). Dates as `Y-m-d`.
- `current_accountable_officer`, `current_end_user`: `{id, full_name} | null`.
- `parent` (`{id, property_no}`, the current bundle) and `children` (its peripherals) on create, detail and update.
- `original_bundle` (`{id, property_no} | null`) and `renumberable_peripherals_count` on detail and update only (`EquipmentResource::withBundleNumbering()`), because each costs a query. See [Desktop bundles](#desktop-bundles).
- `transactions` (detail only): `{id, equipment_id, transaction_type, accountable_officer, end_user, received_by, date_*..., supporting_documents1, or_si_dr_iar_no, supporting_documents2, par_ics_rrsp_rs_wmr_no, created_at}`, people as `{id, full_name} | null`.

### `GET /api/equipment/options`

```
{ data: {
    form:        { item_type, brand, category, classification, condition,          // Tier 1, id-valued
                   unit_of_measure, dcp_package, mode_of_acquisition, source_of_acquisition,
                   source_of_funds, allotment_class, disposition_status },        // Tier 2, string-valued
    filters:     { condition, category, disposition_status },
    transaction: { transaction_type, supporting_document_type },
    personnel?:  [{ value: id, label: full_name }]   // active personnel in the user's data scope; only for equipment.transactions.create holders
} }
```

`personnel` is withheld from users who cannot record transactions because it only feeds the transaction form, and it is a staff directory.

## Desktop bundles

A Desktop (package) or CPU (`ItemType::BUNDLE_PARENT_NAMES`) that isn't itself linked can carry Monitor/Keyboard/Mouse peripherals (`ItemType::PERIPHERAL_NAMES`) as child equipment rows (`parent_equipment_id`). A peripheral created from the desktop copies the desktop's acquisition, DCP, location, disposition, warranty and `current_*` fields and mirrors its latest transaction. It is numbered `<desktop property_no>-NNN`: the highest suffix among the desktop's children (soft-deleted included) plus one, skipping numbers taken anywhere. A linked existing peripheral keeps its own number. Issuance, transfer and return on the desktop move its peripherals with it.

**Peripheral row** (create's `peripherals[]` and `/batch`, at most 10 per request, `ValidatesDesktopBundles::MAX_PERIPHERAL_ROWS`): `item_type_id` required (active Monitor/Keyboard/Mouse). Optional: `serial_number`, `model`, `specifications`, `acquisition_cost`, `remarks`, and `brand_id`/`equipment_condition_id`, where `null` takes the desktop's value.

**`POST /api/equipment` with `peripherals`.** Allowed only when the new item is a bundle parent (otherwise 422 on `peripherals`), and `property_no` is then required (422 on `property_no`). The desktop and its rows are saved in one DB transaction, so a bad row or a failed save creates nothing. Row errors come back as `peripherals.N.<field>`. No permission beyond `equipment.create` is needed, since a new desktop has no accountability to copy.

**`POST /api/equipment/{id}/peripherals/batch`** errors: `peripherals` (missing, empty, more than 10, or a number taken by a concurrent request), `peripherals.N.<field>`, and `equipment` (the desktop isn't a bundle parent, is itself linked, or has no property number).

**Renumbering (`PUT` with `renumber_peripherals: true`).** When the desktop's `property_no` changes, rows numbered exactly `<old>-<digits>` are renamed to `<new>-<digits>`, keeping the suffix.

- Only Monitor/Keyboard/Mouse rows the editor can see (`Equipment::visibleTo`) are renamed, whether still linked, since moved to another desktop, or soft-deleted. Rows of any other item type, and rows outside the editor's data scope, are left alone.
- `old_property_no` is set to the previous number only if it was empty.
- If any target number is already used by any row (a global check), nothing changes: 422 on `renumber_peripherals`, listing only the taken numbers the editor can see, plus "and N other(s)".
- 422 on `renumber_peripherals` unless the item type (after the update) is a bundle parent. Without the flag, or when the old number was blank, nothing is renumbered.
- The desktop is re-read with `lockForUpdate`, and the update and renames run in one transaction. A unique-index violation from a concurrent request is a 422, not a 500.
- Audit: one `equipment.peripheral.renumbered` row per peripheral (`{old, new, renumbered_from_equipment_id}`) and one on the desktop (`{old, new, renumbered_equipment_ids}`).

`renumberable_peripherals_count` is how many rows a renumber would rename for the requesting user (same rules), and always `0` unless this record is a Desktop (package) or CPU. `original_bundle` is set only on a peripheral: it is the live Desktop (package) or CPU whose `property_no` is this record's number without its trailing `-<digits>`. It is `null` when there's no such row or the user can't see it.

**Owner decisions (2026-10-28):**

- Supply issues one property number per desktop set, so peripherals are numbered after it instead of getting their own.
- The `-NNN` suffix records the **original** bundle; `parent_equipment_id` records the **current** one. A peripheral moved to another desktop keeps its number, and the record page shows both.
- Batch flows (create, `/batch`) have no manual number override. A peripheral that needs its own number is created on its own and then linked.
- Existing and legacy data is never changed automatically: no backfill of numbers or links, and renumbering happens only when the editor opts in.
- The create page's Peripherals tab starts empty, so saving a desktop never creates peripherals the encoder didn't add. One click ("Add monitor, keyboard and mouse") adds the usual set. The edit page's batch dialog still opens with that set filled in (only a Monitor when the desktop already has peripherals), because there the encoder has already chosen to add peripherals.

**Why renumbering is limited to `visibleTo`:** without it, an office-scoped editor could change a desktop's number and mass-rename other offices' rows that share the prefix (security review, 2026-10-28). The collision check stays global because the unique index is global, but the message names only visible numbers so it doesn't disclose other offices' property numbers.

## Frontend

| Route | Page |
|---|---|
| `/equipment` | `pages/management/equipment/EquipmentList.tsx` |
| `/equipment/new` | `pages/management/equipment/EquipmentCreate.tsx` (`equipment.create`) |
| `/equipment/:id/edit` | `pages/management/equipment/EquipmentEdit.tsx` — record, QR image with print/download, transaction history and form |
| `/equipment/:id/edit` | alias redirecting to the portal edit page (QR scans) |

Form body: `components/equipment/EquipmentFields.tsx`; history and transaction form: `components/equipment/TransactionLog.tsx`; API: `services/equipmentService.ts`; types: `types/equipment.ts`.

Bundle UI:

- **Create:** a bundle-parent item type adds a Peripherals tab (`components/equipment/PeripheralRowsEditor.tsx`). It starts empty: "Add monitor, keyboard and mouse" adds one of each in a single click, and with no rows nothing but the desktop is created. Brand and condition show "Same as desktop" (sent as `null`). The tab previews the `-NNN` numbers and links to the Item tab while `property_no` is blank. A row 422 switches to the tab (`extractRowErrors` in `lib/errors.ts`).
- **Edit, Peripherals tab** (`components/equipment/BundledPeripherals.tsx`): "Add new peripherals" opens a dialog with the same row editor, pre-filled with a Monitor, Keyboard and Mouse (only a Monitor when the desktop already has peripherals), and posts to `/batch`; link and unlink are unchanged.
- **Edit, Overview:** a bundle summary such as "1 Monitor, 2 Mice".
- **Edit, banner:** "Part of desktop X"; "Currently with desktop X … Original bundle: Y" when the original and current bundles differ; "Original bundle: desktop Y" for a peripheral that isn't linked now.
- **Edit, save:** if the form's selected item type is Desktop (package) or CPU, `property_no` changed and `renumberable_peripherals_count > 0`, a dialog offers Cancel, Keep their numbers (`renumber_peripherals: false`) and Renumber (`true`).

## Key files

- `backend/app/Http/Controllers/Api/Management/EquipmentController.php`, `EquipmentTransactionController.php`
- `backend/app/Http/Requests/Management/StoreEquipmentRequest.php`, `UpdateEquipmentRequest.php`, `StoreEquipmentTransactionRequest.php`
- `backend/app/Http/Resources/Management/EquipmentResource.php`, `EquipmentTransactionResource.php`
- `backend/app/Models/Equipment.php`, `EquipmentTransaction.php`, `backend/app/Observers/EquipmentObserver.php`
- `backend/app/Services/EquipmentAccountabilityService.php`, `backend/app/Exceptions/AccountabilitySyncRequiredException.php`
- `backend/app/Policies/EquipmentPolicy.php`
- Bundles: `EquipmentPeripheralController.php`, `backend/app/Services/EquipmentPeripheralService.php`, `backend/app/Http/Requests/Management/{StoreEquipmentPeripheralRequest,StoreEquipmentPeripheralBatchRequest,LinkEquipmentPeripheralRequest}.php`, `Concerns/ValidatesDesktopBundles.php`; tests in `backend/tests/Feature/{EquipmentBundleTest,EquipmentPeripheralTest,EquipmentPeripheralScopeTest}.php`
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
- **No `equipment.office_id`.** Data scope follows who holds the item, so a stored office would be a second source of truth that drifts on every transfer ([ADR](../architecture-decisions/unified-data-scope.md)). The consequence: an item returned to nobody falls back to its creator's reach, so an office encoder who didn't create it stops seeing it. That's intended (returned items go back to division custody).

## Future considerations

None blocking. `EquipmentTransaction` has no factory; add one if transaction-focused test coverage grows.
