# Inventory Count (physical count of PPE and semi-expendable property)

**Status:** Phase 1 built (2026-11-03). Phase 2 not started.

## Purpose

Let the Inventory Committee record the annual (or semi-annual / turnover / spot) physical count inside IRIS, compare it against the Equipment records, and feed the result into the RPCPPE and RPCSP. Today the RPCPPE ([`RpcppeReport`](../../backend/app/Services/RpcppeReport.php)) derives "On Hand" from `disposition_status` and condition from the record. No one actually confirms either.

## Basis (DepEd / COA practice)

- Physical count of PPE presented **as of December 31** each year. Some divisions also count as of June 30. Semi-annual or annual is allowed.
- RPCPPE (Appendix 73) submitted to the COA Auditor and Accounting **by January 31**.
- Counting is done by an **Inventory Committee** (chair + members) and witnessed by a **COA representative**. The report is certified by the committee, approved by the agency head, and verified by COA.
- **COA Circular 2020-006** sets the count procedure. The count is documented daily on an **Inventory Count Form (ICF)**. Items without records are recognized as **"PPE found at station."**
- **COA Circular 2022-004**: ₱50,000 threshold. PPE (≥ ₱50,000) goes on the RPCPPE. Semi-expendable (< ₱50,000) goes on the **RPCSP**. This matches `Equipment::PPE_THRESHOLD`.
- Unserviceable items continue to the IIRUP / IIRUSP and disposal.

These come from secondary sources (division memos, COA circular copies). Verify against the official circulars and the current division memo before treating them as rules.

## Decisions

1. **A module in the existing app, not a separate login.** Same Sanctum + 2FA login, gated by granular permissions. Users whose only permissions are `inventory_count.conduct` (+ `view`) land on the count screen after login. Everyone else reaches it from the sidebar.
2. **One responsive set of screens** for PC and phone. Build only with existing UI components (`DataGrid` already switches to cards below `md`, and the sidebar collapses below `lg`). Design for phones first, with tap targets of at least 44px. Result states always carry a text label, never color alone.
3. **Naming:** free-text `name` that defaults to `Physical Count as of <as-of date>` and stays editable. It uses `SuggestInput` with previously used names as suggestions. There is no `type` field. Reports select a count explicitly, never by name or type.
4. **The record values are copied when a count opens.** Each expected item stores what the record said at that moment, so edits to Equipment during the count don't distort the comparison.
5. **Reconciliation is human-reviewed.** Discrepancies are applied back to Equipment through the existing transaction flow, by a separate permission. Nothing updates automatically.

## Data model (database)

**`inventory_counts`**: `name`, `as_of_date`, `status` (`draft` | `open` | `closed` | `cancelled`), `remarks`, `opened_by` / `opened_at`, `closed_by` / `closed_at`, timestamps.

- Offices in scope: JSON array of office ids (per the lookup ADR convention; no pivot).
- No committee or signatory fields (removed by the owner, 2026-11-03). Report signatories are entered when a report is generated, not stored on the count.

**`inventory_count_items`** (expected list, one row per equipment item):

- `inventory_count_id`, `equipment_id`, unique(`inventory_count_id`, `equipment_id`).
- Values copied from the record: `office_id`, `condition_id`, `accountable_officer_id`, `location`.
- What was found: `result` (`pending` | `found` | `not_found`), `found_condition_id`, `found_holder_id`, `found_location`, `remarks`, `counted_by`, `counted_at`.

**`inventory_count_found_items`** (found at station): `inventory_count_id`, `office_id`, optional `equipment_id` (an IRIS item that belongs elsewhere), otherwise `description` / `property_no` / `serial_no`, `condition_id`, `location`, `remarks`, `counted_by`, `counted_at`.

All three models use `LogsActivity`. Open, close, cancel and reconcile are logged with `activity()`.

## Comparison buckets

| Bucket | Rule |
|---|---|
| Matched | `found`, condition and holder equal the copied record values |
| Condition changed | `found`, condition differs |
| Wrong holder / location | `found`, holder or location differs |
| Not found | `not_found` |
| Found at station | row in `inventory_count_found_items` |
| Pending | `pending` |
| Unserviceable (flag) | found condition is Unserviceable / For Repair, which feeds IIRUP / IIRUSP follow-up |

## Permissions (auth)

| Permission | Allows |
|---|---|
| `inventory_count.view` | See counts, progress and results |
| `inventory_count.conduct` | Mark items and add found-at-station items |
| `inventory_count.manage` | Create, open, close and cancel counts; set their offices |
| `inventory_count.reconcile` | Phase 2: apply discrepancies to Equipment through transactions |

The migration grants `view` to roles holding `equipment.view`, `conduct` to roles holding `equipment.transactions.create`, and `manage` to roles holding `equipment.delete`. It also adds an "Inventory Team" role (`view` + `conduct`, data scope All). A user who sees no dashboard section and holds a count permission is redirected from the dashboard to `/inventory-counts`. Every lookup and list is constrained by `Equipment::visibleTo($user)` and the offices the user's scope reaches.

## API (backend, `routes/api/management.php`)

```
GET    /inventory-counts                          view|conduct
GET    /inventory-counts/options                  any       ?office_ids[]= adds personnel at those offices
POST   /inventory-counts                          manage    (saved as draft)
GET    /inventory-counts/{count}                  view|conduct  (progress per office, bucket sizes)
PUT    /inventory-counts/{count}                  manage    (draft or open; offices and as-of date fixed once open)
DELETE /inventory-counts/{count}                  manage    (draft only)
POST   /inventory-counts/{count}/open             manage    (copies the expected list)
POST   /inventory-counts/{count}/close            manage
POST   /inventory-counts/{count}/cancel           manage
GET    /inventory-counts/{count}/items            view|conduct  ?office_id=&bucket=&search=  (bucket=discrepancies for all mismatches)
GET    /inventory-counts/{count}/lookup           conduct   ?code=  (QR label URL / property no. / old property no. / serial)
PUT    /inventory-counts/{count}/items/{item}     conduct   (open counts only)
GET    /inventory-counts/{count}/found-items      view|conduct
POST   /inventory-counts/{count}/found-items      conduct   (open counts only)
POST   /inventory-counts/{count}/reconcile        reconcile (phase 2, closed counts only)
```

Logic lives in `InventoryCountService`: building the expected list (same scope as the RPCPPE: `visibleTo` + `atOffices` + `inCustody`), recording results, bucket sizes and open/close/cancel. Lookup, item and found-item endpoints are rate-limited. QR labels encode `APP_URL/equipment/{id}/edit`, so the lookup reads the id from a scanned URL.

## Screens (frontend, `pages/management/inventory-counts/`)

1. **Inventory Count list:** counts with as-of date, status and progress. `[+ New Count]` opens a form with Name (auto, editable), As-of date, Offices and Remarks.
2. **Count details:** progress per office, the discrepancy table filterable by bucket, Close / Reconcile actions, and "Report due Jan 31" for Dec 31 counts.
3. **Count screen (`/inventory-counts/:id/count`):** pick an office, then scan (camera button only where a camera exists, using `qr-scanner` so it also works on iPhone) or type a property or serial number. Tapping an item opens a dialog prefilled with the recorded condition, holder and location, so a match is a single tap on Found. Add found-at-station items. Shows "12 / 48 counted." The same screen serves phone and PC.

New files: `services/inventoryCountService.ts`, `types/inventory-count.ts`, `components/inventory-counts/*`.

## Reports

- RPCPPE and a new RPCSP download take an optional closed count. When one is given, "On Hand" and condition come from the count results. Signatories are picked in the download dialog, as the RPCPPE does today.
- Printable ICF per day / counter from `counted_by` / `counted_at`.

## Delivery

- **Phase 1:** tables, permissions, counts CRUD and open/close, count screen, discrepancy list.
- **Phase 2:** reconcile into transactions, RPCPPE/RPCSP from a count, ICF print.

Each phase is signed off by qa, security (lookup scoping, validation, rate limits) and reviewer.
