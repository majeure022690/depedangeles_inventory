# Equipment

## Purpose

The ICT asset register for the division office: property number, item/spec details, acquisition/funding info, condition/disposition, and — distinctively — a full history of who has been accountable for each physical device over time. This is the module the accountability-transfer pattern (see [`docs/architecture.md`](../architecture.md#1-accountability-transfer-equipment)) exists for.

## Architecture

- `App\Models\Equipment` — the asset record. Soft-deletes. Holds `current_accountable_officer_id` / `current_end_user_id`, both `belongsTo(Personnel::class)`.
- `App\Models\EquipmentTransaction` — append-only log, one row per accountability/lifecycle event (`equipment_transactions`, `belongsTo(Equipment)`, plus `belongsTo(Personnel)` three ways: `accountableOfficer`, `endUser`, `receivedBy`, and `belongsTo(User)` as `recordedBy` — the system user who entered the transaction, distinct from the Personnel it's about).
- `App\Services\EquipmentAccountabilityService` — the only sanctioned way to record a transaction and move `current_*` pointers, done atomically in one DB transaction.
- `App\Policies\EquipmentPolicy` — `viewAny`/`view`/`create`/`update`/`delete` plus a custom `createTransaction` ability, each checked against its own `Permission` case.

## Flow

1. **List** (`GET /equipment`, `equipment.index`) — searchable/filterable, paginated 15/page.
2. **Create** (`GET /equipment/create`, `equipment.create`) / **Store** (`POST /equipment`, `equipment.store`) — creates the Equipment row only; no transaction, no accountable officer assigned yet (that happens via a separate transaction).
3. **Edit** (`GET /equipment/{equipment}/edit`, `equipment.edit`) — shows the record plus its full transaction history (newest first) and a form to record a new transaction.
4. **Record a transaction** (`POST /equipment/{equipment}/transactions`, `equipment.transactions.store`) — the only way `current_accountable_officer_id`/`current_end_user_id` change. Append-only: no update/destroy route exists for `equipment_transactions`.
5. **Update** (`PUT/PATCH /equipment/{equipment}`, `equipment.update`) — edits Equipment's own descriptive fields; blocked from touching `current_*` columns by a model-level guard (throws `AccountabilitySyncRequiredException` if attempted).
6. **Delete** (`DELETE /equipment/{equipment}`, `equipment.destroy`) — soft delete.

Every create/update/delete writes an `AuditLog` entry (`equipment.created`/`equipment.updated`/`equipment.deleted`); recording a transaction writes `equipment.transaction.recorded`.

## Inertia routes / prop contracts

All routes require `auth`+`verified` middleware. Controller: `App\Http\Controllers\EquipmentController` (+ `EquipmentTransactionController` for the nested transaction route).

| Route | Page component | Key props |
|---|---|---|
| `GET /equipment` (`equipment.index`) | `equipment/Index` | `equipment` (paginated), `filters` (`search`, `condition`, `category`, `disposition_status`), `options` (lookup options for `condition`/`category`/`disposition_status` filter dropdowns) |
| `GET /equipment/create` (`equipment.create`) | `equipment/Create` | `options` (full form lookup set — item type, UOM, brand, DCP package, category, classification, mode/source of acquisition, source of funds, allotment class, condition, disposition status) |
| `GET /equipment/{equipment}/edit` (`equipment.edit`) | `equipment/Edit` | `equipment` (full record + nested `transactions[]`, each with resolved `accountable_officer`/`end_user`/`received_by`), `options` (form lookups), `transactionOptions` (transaction-type + supporting-document-type lookups), `personnelOptions` (active personnel, `{value, label}` pairs, for the accountable officer / end user / received-by selects) |

`equipment.index` row shape (via `->through()`):

```php
[
    'id', 'property_no', 'item', 'brand_manufacturer', 'model', 'serial_number',
    'category', 'equipment_condition', 'disposition_status', 'acquisition_cost' /* float */,
    'current_accountable_officer' => ['id', 'full_name']|null,
    'current_end_user' => ['id', 'full_name']|null,
]
```

`equipment.edit`'s `equipment` prop includes every fillable Equipment column (see `Equipment::$fillable`) plus `current_accountable_officer`/`current_end_user` (resolved `{id, full_name}`) and `transactions[]`, each shaped:

```php
[
    'id', 'transaction_type',
    'accountable_officer' => ['id', 'full_name']|null,
    'end_user' => ['id', 'full_name']|null,
    'received_by' => ['id', 'full_name']|null,
    'date_assigned_accountable_officer', 'date_assigned_end_user', 'date_received_new_accountable',
    'supporting_documents1', 'or_si_dr_iar_no', 'supporting_documents2', 'par_ics_rrsp_rs_wmr_no',
    'created_at',
]
```

## Key files

- `app/Http/Controllers/EquipmentController.php`, `app/Http/Controllers/EquipmentTransactionController.php`
- `app/Models/Equipment.php`, `app/Models/EquipmentTransaction.php`
- `app/Services/EquipmentAccountabilityService.php`
- `app/Exceptions/AccountabilitySyncRequiredException.php`
- `app/Policies/EquipmentPolicy.php`
- `app/Http/Requests/EquipmentStoreRequest.php`, `EquipmentUpdateRequest.php`, `EquipmentTransactionStoreRequest.php`
- `database/migrations/2026_07_10_000003_create_equipment_table.php`, `2026_07_10_000004_create_equipment_transactions_table.php`, `2026_07_10_040000_add_recorded_by_user_id_to_equipment_transactions_table.php`
- `resources/js/pages/equipment/` (`Index.vue`, `Create.vue`, `Edit.vue`, `Partials/`)

## Non-obvious design decisions

- **Accountability sync guard.** `Equipment`'s `updating` model hook throws `AccountabilitySyncRequiredException` if `current_accountable_officer_id`/`current_end_user_id` is dirty outside `Equipment::withAccountabilitySync()` — the closure `EquipmentAccountabilityService::recordTransaction()` runs inside. This makes it structurally impossible for a controller (now or added later) to drift the "current holder" pointers out of sync with the audit log by updating them directly. The guard only fires on `UPDATE`, not `CREATE` — initial creation (factories, seeders, a future import pipeline) has no prior holder to reconcile against.
- **Sync policy is "last transaction wins," always.** `current_*` is unconditionally overwritten to match the new transaction's `accountable_officer_id`/`end_user_id`, including being cleared to `null` if the transaction doesn't name one — deliberately no per-transaction-type branching, to keep the invariant trivial to reason about.
- **`equipment.transactions.create` is its own permission**, separate from `equipment.edit`. Reassigning accountability for a physical asset was judged materially more sensitive than editing the asset's descriptive fields (spec, remarks, location), so `encoder` gets both, but a hypothetical future role could hold one without the other.
- **`Permission::EquipmentDelete` denials are audit-logged.** Deletion is Equipment's most consequential action, so `EquipmentPolicy::delete()` records an `authorization.denied` entry on denial rather than silently 403ing.
- **No `employee_id=9999` "unknown officer" placeholder row.** Personnel's doc-comment notes this deliberately: `current_accountable_officer_id`/`current_end_user_id` are nullable FKs, and "unknown/unassigned" is represented as `null`, not a sentinel Personnel row.

## Future considerations

None flagged as blocking. `App\Models\EquipmentTransaction`'s doc-comment notes "no factory was requested for this model; add one if Backend needs it for tests" — worth revisiting if transaction-focused test coverage expands.
