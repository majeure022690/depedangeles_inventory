# Equipment

## Purpose

The ICT asset register for the division office: property number, item/spec details, acquisition/funding info, condition/disposition, and a full history of who has been accountable for each physical device over time. This is the module the accountability-transfer pattern (see [`docs/architecture.md`](../architecture.md#1-accountability-transfer-equipment)) exists for.

## Architecture

- `App\Models\Equipment` — the asset record. Soft-deletes. Holds `current_accountable_officer_id` / `current_end_user_id`, both `belongsTo(Personnel::class)`. Tier 1 reference fields are FKs (`item_type_id`, `brand_id`, `equipment_category_id`, `equipment_classification_id`, `equipment_condition_id`).
- `App\Models\EquipmentTransaction` — append-only log, one row per accountability/lifecycle event. `belongsTo(Personnel)` three ways (`accountableOfficer`, `endUser`, `receivedBy`) plus `belongsTo(User)` as `recordedBy` — the system user who entered it, distinct from the Personnel it's about.
- `App\Services\EquipmentAccountabilityService` — the only sanctioned way to record a transaction and move the `current_*` pointers, atomically in one DB transaction.
- `App\Policies\EquipmentPolicy` — `viewAny`/`view`/`create`/`update`/`delete` plus `createTransaction`, each checked against its own `Permission` case.
- `App\Services\EquipmentPeripheralService` — desktop bundles: creates, links and unlinks peripherals (see [Desktop bundles](#desktop-bundles)).
- `App\Services\EquipmentPropertyNumberService` — generates `property_no` on create (see [Property numbers](#property-numbers)).
- `App\Concerns\DerivesEquipmentFields` (used by `Equipment`) — a `saving` hook that fills category, GL-SL code and other blank fields (see [Derived fields](#derived-fields)).
- `App\Models\EquipmentAttachment` — scanned documents and photos attached to a record (see [Attachments](#attachments)).
- `App\Observers\EquipmentObserver` — fills `qr_code` with `Equipment::qrCodeUrl()` once the row has an id (see QR below).

Audit: create/update/delete come from `LogsActivity` (`equipment.created|updated|deleted`, `current_*` excluded); recording a transaction, including the initial accountability on create, logs `equipment.transaction.recorded`; bundle events log `equipment.peripheral.{created,linked,unlinked}`; attachments log `equipment.attachment.{uploaded,deleted}`.

## API endpoints

All under `auth:sanctum` + `throttle:authenticated-api`. Controllers: `Api\Management\EquipmentController`, `EquipmentTransactionController`, `EquipmentPeripheralController`, `EquipmentAttachmentController`.

| Method + path | Route permission | Policy | Request → response |
|---|---|---|---|
| `GET /api/equipment?search&condition&category&disposition_status&sort&direction&per_page` | `equipment.view` | `viewAny` | paginated `EquipmentResource` (no `transactions`). `condition`/`category` are Tier 1 ids, `disposition_status` a string. Sort keys: `property_no`, `serial_number`, `model`, `disposition_status`, `acquisition_cost`, `received_date`, `created_at`; default puts items without a property number last. |
| `GET /api/equipment/options` | `equipment.view` | — | see options below |
| `GET /api/equipment/preview-property-number?item_type_id&received_date` | `equipment.create` | `create` (Form Request) | `PreviewEquipmentPropertyNumberRequest` → `{data: {property_no: string \| null}, message}`. Read-only, reserves nothing; see [Property numbers](#property-numbers). |
| `POST /api/equipment` | `equipment.create` | `create` (Form Request) | `StoreEquipmentRequest` → `EquipmentResource` with `parent` and `children`, 201. Generates `property_no` ([Property numbers](#property-numbers)), records the initial `accountability` transaction, and creates any `peripherals[]` rows, all in one DB transaction ([Desktop bundles](#desktop-bundles)). |
| `GET /api/equipment/{id}` | `equipment.view` | `view` | `EquipmentResource` with `transactions[]` and `attachments[]` (both newest first), `parent`, `children`, `original_bundle` |
| `PUT /api/equipment/{id}` | `equipment.edit` | `update` | `UpdateEquipmentRequest` → `EquipmentResource` with `parent`, `children`, `original_bundle` |
| `DELETE /api/equipment/{id}` | `equipment.delete` | `delete` (denial audited) | 204, soft delete |
| `GET /api/equipment/{id}/qr-code` | `equipment.view` | `view` | `image/png` (300px, `inline`). The SPA fetches it as a blob through axios; a plain `<img src>` would not send the session cookie cross-origin. |
| `POST /api/equipment/{id}/transactions` | `equipment.transactions.create` | `createTransaction` (denial audited) | `StoreEquipmentTransactionRequest` → `EquipmentTransactionResource`, 201 |
| `POST /api/equipment/{id}/peripherals/batch` | `equipment.edit` + `equipment.transactions.create` | `update` + `create` + `createTransaction` on the desktop | `{peripherals: [1..10 rows]}` → `EquipmentResource[]` + `message`, 201. All rows or none. |
| `POST /api/equipment/{id}/peripherals` | same | same | one row, flat (`StoreEquipmentPeripheralRequest`; here `brand_id` and `equipment_condition_id` are required) → `EquipmentResource`, 201. Still routed, but the SPA uses `/batch`. |
| `POST /api/equipment/{id}/peripherals/link` | same | `update` + `createTransaction` on the desktop | `{equipment_id}` → `EquipmentResource` |
| `DELETE /api/equipment/{id}/peripherals/{peripheral}` | `equipment.edit` | `update` on the desktop and on the peripheral | 204; 404 unless the peripheral is linked to that desktop |
| `POST /api/equipment/{id}/attachments` | `equipment.edit` + `throttle:attachment-upload` | `update` (Form Request) | multipart `StoreEquipmentAttachmentRequest` → `EquipmentAttachmentResource`, 201. See [Attachments](#attachments). |
| `GET /api/equipment/{id}/attachments/{attachment}` | `equipment.view` | `view` | the stored file as a download (`Content-Type` from the stored MIME, `X-Content-Type-Options: nosniff`); 404 if the attachment belongs to another record or the file is missing |
| `DELETE /api/equipment/{id}/attachments/{attachment}` | `equipment.edit` | `update` | 204; deletes the row and the file; 404 if the attachment belongs to another record |

There is no update or delete endpoint for transactions, by design.

The list and every per-record action (show, QR, update, delete, transactions, attachments) are also limited by the role's [data scope](roles-and-permissions.md#data-scope), and an out-of-scope id gets **404** (403 only when the permission itself is missing). Equipment has no office column; scope goes through personnel and the creator:

- an item currently issued (accountable officer or end user) to personnel in the user's reach;
- an **unissued** item (no officer and no end user) the user created (`equipment.created_by_user_id`); under `school`, also unissued items created by any user whose `users.office_id` is the same as theirs, read at request time.

`POST` sets `created_by_user_id` from the session user; it isn't a request field and isn't in `EquipmentResource`. Migration `2026_10_26_000003` backfilled it from the `created` rows in `activity_log`, so items created before then by a user still in the system are attributed. Transaction targets (`accountable_officer_id`, `end_user_id`, `received_by_id`) must be personnel in the user's reach (422 "Choose someone within your access."), and the `/options` `personnel` list is limited the same way.

**Request fields.** Both `StoreEquipmentRequest` and `UpdateEquipmentRequest` take the same rules, from `Concerns\ValidatesEquipmentFields`. The required set follows the division's field spreadsheet (`b77afdf`):

- **Required:** `item_type_id`, `brand_id`, `equipment_classification_id`, `equipment_condition_id` (active Tier 1 ids); `uom`, `mode_acquisition`, `source_acquisition`, `source_fund`, `used_by`, `disposition_status` (active Tier 2 values); `serial_number`, `model` (max 255), `specifications`, `equipment_location`; `non_dcp`, `non_functional` (booleans, so the client must send `false` explicitly); `acquisition_cost` (0 to 9,999,999,999.99, at most 2 decimals: more is a 422, not rounded); `received_date` (1900-01-01 to 9999-12-31; its year goes into the property number).
- **Conditionally required:** `dcp_package` and `dcp_year` (4 digits) unless `non_dcp` is true; `donor` when `source_acquisition` is one of `Equipment::DONOR_SOURCES`. Before validation the request clears `dcp_package`/`dcp_year` when `non_dcp` is true and `donor` for any other source, so a stale value left in a hidden field is never saved.
- **Optional:** `old_property_no`, `uacs`, `estimated_useful_life`, `allotment_class`, `pm_plan`, `remarks`, `under_warranty`, `end_warranty_date`, `supplier`.
- **Never accepted:** `property_no` is `prohibited` (422) on both: it is generated on create and can't be changed afterwards. `equipment_category_id` and `gl_sl_code` aren't in the rules (silently dropped) because they are [derived](#derived-fields) from the cost. The `current_*` pointers and `qr_code` change only through transactions and the observer.

Create additionally requires an `accountability` object (the first transaction, see below) and accepts `peripherals[]` ([Desktop bundles](#desktop-bundles)); update ignores both. Because update uses the same rules, a record created before these rules can't be saved until its newly required fields are filled; nothing on it changes until the request is valid (`EquipmentCreateEdgeCasesTest`).

### Derived fields

`DerivesEquipmentFields` runs on every save, so all write paths (API, peripherals, seeders) agree:

- **Category and GL-SL code** follow `acquisition_cost`: at or above `Equipment::PPE_THRESHOLD` (50,000) the "High-value" category and GL-SL `10605030`, below it "Low-value" and `10405030`. On create they're filled only if blank; on update they're recomputed whenever the cost changes. `/options` sends the same rule as `category_rules`, so the form previews the result without duplicating the numbers.
- Blank `uacs` and `estimated_useful_life` take the classification's `default_uacs`/`default_useful_life`; blank `allotment_class` becomes "Not Applicable"; a null `under_warranty` is set from `end_warranty_date`.

**Transaction fields:** `transaction_type` (required, Tier 2), `accountable_officer_id`, `end_user_id`, `received_by_id` (Personnel ids), `date_assigned_accountable_officer`, `date_assigned_end_user`, `date_received_new_accountable`, `supporting_documents1`/`supporting_documents2` (Tier 2 supporting-document types), `or_si_dr_iar_no`, `par_ics_rrsp_rs_wmr_no`. On create they come nested under `accountability.*`; there the three people and the two assignment dates are required, while `date_received_new_accountable` stays optional (as it is on `POST /api/equipment/{id}/transactions`).

### Property numbers

`POST /api/equipment` numbers every new record `AC-<YYYY>-IT-<ABV>-<NNNNNN>` (`EquipmentPropertyNumberService`): the year of `received_date`, the item type's 3-character abbreviation (`[A-Z0-9]{3}`), and a 6-digit series per abbreviation that runs across all years, counts soft-deleted rows and never resets. Create fails with 422 on `item_type_id` when the item type has no valid abbreviation.

`GET /api/equipment/preview-property-number?item_type_id=<int>&received_date=<date>` returns the number the next create would get: `{data: {property_no: string | null}, message}`. `item_type_id` must be an active item type and `received_date` a date from 1900-01-01 to 9999-12-31 (otherwise 422). It is gated by `equipment.create` twice, by the route's `permission:` middleware and by the Form Request's `create` policy check, so only someone who may create equipment can read the series.

- It is **read-only and reserves nothing.** Two encoders previewing at once see the same number; whoever saves second gets the next free one. Only the save, which locks the item type row and the series, is authoritative.
- `property_no` is `null` (not an error) when the item type has no valid abbreviation or the series is past 999999, so the form simply shows no preview.

### `EquipmentResource`

Every request field above, plus `id`, `property_no`, the derived `equipment_category_id` and `gl_sl_code`, `qr_code`, `parent_equipment_id`, `created_at`, `updated_at`, and:

- Tier 1 display names under their historical keys next to the FK id: `item`, `brand_manufacturer`, `category`, `classification`, `equipment_condition`; plus `dashboard_group` from the item type.
- `acquisition_cost` as a float, or `null` ("not on file yet" is not zero). Dates as `Y-m-d`.
- `warranty_active`: `null` without an `end_warranty_date`, else whether it is today or later (computed per response, unlike the stored `under_warranty`).
- `current_accountable_officer`, `current_end_user`: `{id, full_name} | null`.
- `parent` (`{id, property_no}`, the current bundle) and `children` (its peripherals) on create, detail and update.
- `original_bundle` (`{id, property_no} | null`) on detail and update only (`EquipmentResource::withOriginalBundle()`), because it costs a query. See [Desktop bundles](#desktop-bundles).
- `attachments` (detail only): `EquipmentAttachmentResource[]`, see [Attachments](#attachments).
- `transactions` (detail only): `{id, equipment_id, transaction_type, accountable_officer, end_user, received_by, date_*..., supporting_documents1, or_si_dr_iar_no, supporting_documents2, par_ics_rrsp_rs_wmr_no, created_at}`, people as `{id, full_name} | null`.

### `GET /api/equipment/options`

```
{ data: {
    form:        { item_type, brand, category, classification, condition,          // Tier 1, id-valued
                   unit_of_measure, dcp_package, mode_of_acquisition, source_of_acquisition,
                   source_of_funds, allotment_class, disposition_status, used_by }, // Tier 2, string-valued
    filters:     { condition, category, disposition_status },
    transaction: { transaction_type, supporting_document_type },
    classification_defaults:   { [classification_id]: { default_uacs, default_useful_life } },
    donor_sources:             string[],      // source_acquisition values that require a donor
    category_rules:            { threshold, low: { category, gl_sl_code }, high: { category, gl_sl_code } },
    attachment_document_types: string[],      // "Procurement Management Plan (PMP)" + active supporting-document types
    personnel?:  [{ value: id, label: full_name }]   // active personnel in the user's data scope; only for equipment.create or equipment.transactions.create holders
} }
```

`personnel` is withheld from users who can neither create equipment nor record transactions, because it only feeds the create form's accountability and the transaction form, and it is a staff directory.

## Desktop bundles

A Desktop (package) or CPU (`ItemType::BUNDLE_PARENT_NAMES`) that isn't itself linked can carry Monitor/Keyboard/Mouse peripherals (`ItemType::PERIPHERAL_NAMES`) as child equipment rows (`parent_equipment_id`). A peripheral created from the desktop copies the desktop's acquisition, DCP, location, disposition, warranty and `current_*` fields and mirrors its latest transaction. It is numbered `<desktop property_no>-NNN`: the highest suffix among the desktop's children (soft-deleted included) plus one, skipping numbers taken anywhere. A linked existing peripheral keeps its own number. Issuance, transfer and return on the desktop move its peripherals with it.

**Peripheral row** (create's `peripherals[]` and `/batch`, at most 10 per request, `ValidatesDesktopBundles::MAX_PERIPHERAL_ROWS`): `item_type_id` required (active Monitor/Keyboard/Mouse). Optional: `serial_number`, `model`, `specifications`, `acquisition_cost`, `remarks`, and `brand_id`/`equipment_condition_id`, where `null` takes the desktop's value.

**`POST /api/equipment` with `peripherals`.** Allowed only when the new item is a bundle parent (otherwise 422 on `peripherals`). The desktop's number is generated first, then each row is numbered after it, so the encoder never types a number. The desktop, its initial accountability transaction and its rows are saved in one DB transaction, so a bad row or a failed save creates nothing. Row errors come back as `peripherals.N.<field>`. No permission beyond `equipment.create` is needed: each new peripheral mirrors the desktop's just-recorded accountability.

**`POST /api/equipment/{id}/peripherals/batch`** errors: `peripherals` (missing, empty, more than 10, or a number taken by a concurrent request), `peripherals.N.<field>`, and `equipment` (the desktop isn't a bundle parent, is itself linked, or has no property number, which only legacy rows can lack).

**No renumbering.** Until `b77afdf` (2026-10-29; the SPA's renumber dialog went in `c8e7073`), `PUT` accepted `renumber_peripherals` to rename a desktop's `<old>-NNN` peripherals after its number was edited, and the resource carried `renumberable_peripherals_count`. Both were removed, with the `equipment.peripheral.renumbered` event, because `property_no` is now generated and can't be edited, so a desktop's number never changes. Existing `equipment.peripheral.renumbered` rows stay in the audit trail.

`original_bundle` is set only on a peripheral: it is the live Desktop (package) or CPU whose `property_no` is this record's number without its trailing `-<digits>`. It is `null` when there's no such row or the user can't see it.

**Owner decisions (2026-10-28):**

- Supply issues one property number per desktop set, so peripherals are numbered after it instead of getting their own.
- The `-NNN` suffix records the **original** bundle; `parent_equipment_id` records the **current** one. A peripheral moved to another desktop keeps its number, and the record page shows both.
- Batch flows (create, `/batch`) have no manual number override. A peripheral that needs its own number is created on its own and then linked.
- Existing and legacy data is never changed automatically: no backfill of numbers or links.
- The create page's Peripherals tab starts empty, so saving a desktop never creates peripherals the encoder didn't add. One click ("Add monitor, keyboard and mouse") adds the usual set. The edit page's batch dialog still opens with that set filled in (only a Monitor when the desktop already has peripherals), because there the encoder has already chosen to add peripherals.

## Attachments

Scanned documents and photos (PMP, PAR, ICS, delivery receipts, …) attached to an existing record (`5b96ef9`). Table `equipment_attachments` (migration `2026_10_29_000005`): `equipment_id` (FK, cascade on hard delete), `document_type`, `document_no`, `original_filename`, `disk`, `path`, `mime_type`, `file_size`, `file_hash` (SHA-256 of the stored file), `uploaded_by_user_id` (FK, null on user delete), timestamps. No soft deletes: deleting an attachment removes it (the audit row keeps its metadata and hash).

**Upload** (`POST /api/equipment/{id}/attachments`, multipart), `StoreEquipmentAttachmentRequest`:

- `file`: required, at most 5 MB (`EquipmentAttachment::MAX_KILOBYTES` = 5120), extension `pdf`, `jpg`, `jpeg`, `png` or `webp`.
- `document_type`: required, one of `EquipmentAttachment::documentTypes()`: "Procurement Management Plan (PMP)" plus the active Tier 2 supporting-document types (the same list `/options` sends as `attachment_document_types`).
- `document_no`: optional, max 255.
- Authorized by the `update` policy on the record, so it needs `equipment.edit` and the record in the user's data scope. An out-of-scope record is a **404**, the same as download, delete and the rest of the module: `StoreEquipmentAttachmentRequest::authorize()` returns the policy's `Response` (`Gate::inspect('update', …)`) rather than a bool, so the policy's `denyAsNotFound` survives. (Until 2026-10-31 it returned `can('update')` as a bool, which collapsed that into a 403 and confirmed the record existed.) The `attachment-upload` limiter allows 30 uploads a minute per user.

**Storage** (`FileUploadService::storeDocument()`): files go on the private `local` disk (`storage/app/private`, never web-served) under `equipment-attachments/<equipment id>/<uuid>.<ext>`; the client filename is kept only as `original_filename` (truncated to 255) for display and the download name.

- A PDF must have a real MIME of `application/pdf` (sniffed with `finfo`, the client's declared type is ignored) and start with `%PDF-`; it is stored as uploaded.
- An image goes through `storeImage()`: real-MIME check, 50-8000 px per side, and a re-encode through GD to a fresh file, which strips EXIF (including GPS) and any embedded payload.
- A rejection is a 422 on `file`.

**Download** streams the file through the API (the SPA fetches it as a blob and opens or saves it), since the disk is private and a plain link wouldn't carry the session cookie cross-origin. `scopeBindings()` makes an attachment id from another record a 404.

**Audit:** `equipment.attachment.uploaded` and `equipment.attachment.deleted`, logged on the equipment with `{attachment_id, document_type, document_no, original_filename, file_hash}`.

**`EquipmentAttachmentResource`:** `{id, document_type, document_no, original_filename, mime_type, file_size, uploaded_by (user name | null), created_at}`. `disk`, `path` and `file_hash` are never exposed.

Soft-deleting the equipment leaves its attachments and files in place (the FK cascade only fires on a hard delete, which the API never does).

Tests: `backend/tests/Feature/EquipmentAttachmentTest.php`.

## Audit history

A record's change history (create/update/delete, transactions, bundle and attachment events) is read through the [Audit Log](audit-log.md), not the equipment API: `GET /api/audit-log?subject_type=App\Models\Equipment&subject_id=<id>`. The edit page shows a "View audit history" link to `/audit-log?subject_type=…&subject_id=<id>` (built by `auditLogHref()` in `lib/auditLog.ts`) only when the user holds `audit_log.view`; the audit log's own data scoping still applies.

History is **deliberately not embedded** in `GET /api/equipment/{id}`. Activity rows carry actor emails, `authorization.denied` attempts and raw before/after attribute diffs, which `equipment.view` holders (encoders, office staff) shouldn't see; `audit_log.view` is the permission that gates that exposure. The edit page's History tab is the accountability transaction log, a different thing.

## Frontend

| Route | Page |
|---|---|
| `/equipment` | `pages/management/equipment/EquipmentList.tsx` |
| `/equipment/new` | `pages/management/equipment/EquipmentCreate.tsx` (`equipment.create`) |
| `/equipment/:id/edit` | `pages/management/equipment/EquipmentEdit.tsx` — record, QR image with print/download, transaction history and form; also the URL printed on QR labels. Header actions: "View audit history" (`audit_log.view`, see [Audit history](#audit-history)) and Delete |

Form body: `components/equipment/EquipmentFields.tsx`, split into the field spreadsheet's sections (`EQUIPMENT_FIELD_SECTIONS`, `c8e7073`): Equipment, Acquisition, Accountability, Status / Location, Documents. The create page shows those tabs (plus Peripherals for a bundle parent); the edit page adds Overview before them and Peripherals and History after. Required fields carry a red asterisk and `aria-required` (`FormField`'s `required` prop); a 422 opens the first tab with an error, and nested `accountability.*` errors keep their column (`extractFieldErrors(e, EQUIPMENT_NESTED_ERROR_FIELDS)`). On create the Accountability tab collects the initial transaction; on edit it shows the current holders read-only (moves go through History). On create its read-only Property number field shows a live preview: once item type and acquisition date are both set, `EquipmentCreate.tsx` calls the preview endpoint (debounced 300 ms, superseded requests aborted), and the field reads "Will be generated" until then or when the preview is `null`. History and transaction form: `components/equipment/TransactionLog.tsx`; API: `services/equipmentService.ts`; types: `types/equipment.ts`.

Bundle UI:

- **Create:** a bundle-parent item type adds a Peripherals tab (`components/equipment/PeripheralRowsEditor.tsx`). It starts empty: "Add monitor, keyboard and mouse" adds one of each in a single click, and with no rows nothing but the desktop is created. Brand and condition show "Same as desktop" (sent as `null`). Since the desktop's number doesn't exist until save, rows preview as `[desktop no.]-001`, `-002`, …. A row 422 switches to the tab (`extractRowErrors` in `lib/errors.ts`).
- **Edit, Peripherals tab** (`components/equipment/BundledPeripherals.tsx`): "Add new peripherals" opens a dialog with the same row editor, pre-filled with a Monitor, Keyboard and Mouse (only a Monitor when the desktop already has peripherals), and posts to `/batch`; link and unlink are unchanged.
- **Edit, Overview:** a bundle summary such as "1 Monitor, 2 Mice".
- **Edit, banner:** "Part of desktop X"; "Currently with desktop X … Original bundle: Y" when the original and current bundles differ; "Original bundle: desktop Y" for a peripheral that isn't linked now.

Attachments UI (`components/equipment/EquipmentAttachments.tsx`), rendered below the fields on the edit page's Documents tab; the create page's Documents tab only says files can be attached after saving, since there's no record id yet:

- Lists each file with type, number, size, date and uploader, plus View (opens the blob in a new tab with `opener` cleared) and Download.
- With `equipment.edit`: a document-type select (from `attachment_document_types`), an optional document number, "Choose file" (PDF/JPG/PNG/WEBP) and "Take photo" (`capture="environment"`, for phones), and a delete button behind a confirm dialog. Files over 5 MB are refused client-side with the server's message before upload; the server still enforces every rule.
- API: `equipmentService.uploadAttachment`, `attachmentFile` (blob), `removeAttachment`; type `EquipmentAttachment` in `types/equipment.ts`.

## Key files

- `backend/app/Http/Controllers/Api/Management/EquipmentController.php`, `EquipmentTransactionController.php`
- `backend/app/Http/Requests/Management/StoreEquipmentRequest.php`, `UpdateEquipmentRequest.php`, `StoreEquipmentTransactionRequest.php`
- `backend/app/Http/Resources/Management/EquipmentResource.php`, `EquipmentTransactionResource.php`
- `backend/app/Models/Equipment.php`, `EquipmentTransaction.php`, `backend/app/Observers/EquipmentObserver.php`
- `backend/app/Http/Requests/Management/Concerns/{ValidatesEquipmentFields,ValidatesEquipmentTransactions}.php`, `backend/app/Concerns/DerivesEquipmentFields.php`; tests in `backend/tests/Feature/{EquipmentControllerTest,EquipmentCreateEdgeCasesTest,EquipmentDerivedFieldsTest}.php`
- `backend/app/Services/EquipmentPropertyNumberService.php`, `backend/app/Http/Requests/Management/PreviewEquipmentPropertyNumberRequest.php`; tests in `backend/tests/Feature/EquipmentPropertyNumberTest.php`
- Attachments: `EquipmentAttachmentController.php`, `StoreEquipmentAttachmentRequest.php`, `EquipmentAttachmentResource.php`, `backend/app/Models/EquipmentAttachment.php`, `backend/app/Services/FileUploadService.php` (`storeDocument`), migration `2026_10_29_000005_create_equipment_attachments_table.php`; frontend `components/equipment/EquipmentAttachments.tsx`
- `backend/app/Services/EquipmentAccountabilityService.php`, `backend/app/Exceptions/AccountabilitySyncRequiredException.php`
- `backend/app/Policies/EquipmentPolicy.php`
- Bundles: `EquipmentPeripheralController.php`, `backend/app/Services/EquipmentPeripheralService.php`, `backend/app/Http/Requests/Management/{StoreEquipmentPeripheralRequest,StoreEquipmentPeripheralBatchRequest,LinkEquipmentPeripheralRequest}.php`, `Concerns/ValidatesDesktopBundles.php`; tests in `backend/tests/Feature/{EquipmentBundleTest,EquipmentPeripheralTest,EquipmentPeripheralScopeTest}.php`
- `backend/routes/web.php` — the QR redirect route
- `backend/app/Console/Commands/RegenerateEquipmentQrCodes.php` — `equipment:regenerate-qr-codes`

## Non-obvious design decisions

- **Accountability sync guard.** `Equipment`'s `updating` hook throws `AccountabilitySyncRequiredException` if `current_accountable_officer_id`/`current_end_user_id` is dirty outside `Equipment::withAccountabilitySync()` — the closure `EquipmentAccountabilityService::recordTransaction()` runs inside. A controller (now or later) cannot drift the pointers out of sync with the log. The guard fires only on `UPDATE`, not `CREATE`: initial creation (factories, seeders, a future import) has no prior holder to reconcile.
- **"Last transaction wins," always.** `current_*` is overwritten to match the new transaction, including `null` when the transaction names no one. No per-transaction-type branching, so the invariant stays trivial.
- **`equipment.transactions.create` is its own permission**, separate from `equipment.edit`: reassigning accountability for a physical asset is more sensitive than editing its description. `encoder` holds both, but a role can hold one without the other.
- **QR labels must outlive the URL scheme.** Labels encode `APP_URL/equipment/{id}/edit`. That path stayed valid through the SPA conversion via the backend route and the SPA's own `equipment/:id/edit` route. Don't change the observer's URL without a plan for already-printed labels.
- **QR URLs come from `APP_URL`, never the request host.** The observer and the `/qr-code` fallback both use `Equipment::qrCodeUrl()`. Until 2026-10-23 the observer used `route('equipment.edit')`, which takes its host from the incoming request, so a spoofed `Host`/`X-Forwarded-Host` on a create request could store a foreign URL that then got printed on a physical label. That is a phishing vector which outlives any server-side fix. (For the same reason, trusted proxies now honour only `X-Forwarded-For` by default: see [`architecture.md`](../architecture.md#trusted-proxy-headers).)
- **Existing rows: `php artisan equipment:regenerate-qr-codes`.** Run it once after deploying that change (see the README's Plesk steps), and again if `APP_URL` ever changes.
  - Without flags it's a dry run. It lists every row whose `qr_code` isn't `qrCodeUrl()`, soft-deleted rows included, as `#id property_no: old -> new`, and changes nothing.
  - `--force` rewrites those rows and writes one `equipment.qr_code.regenerated` activity row each, keeping the old value in `properties.old.qr_code`.
  - Review the dry run before forcing. A mismatch can be harmless, e.g. a row created before `APP_URL` changed. A row pointing at a host the division never used should be treated as a possible phishing label: find it and physically reprint it, don't just rewrite the database. Any rewritten row's printed label still encodes the old URL until it is reprinted.
- **No `employee_id = 9999` "unknown officer" placeholder.** Unknown/unassigned is a `null` FK, not a sentinel Personnel row.
- **No `equipment.office_id`.** Data scope follows who holds the item, so a stored office would be a second source of truth that drifts on every transfer ([ADR](../architecture-decisions/unified-data-scope.md)). The consequence: an item returned to nobody falls back to its creator's reach, so an office encoder who didn't create it stops seeing it. That's intended (returned items go back to division custody).

## Future considerations

None blocking.

- `EquipmentTransaction` has no factory; add one if transaction-focused test coverage grows.
