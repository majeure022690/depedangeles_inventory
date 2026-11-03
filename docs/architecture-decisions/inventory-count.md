# Inventory Count (physical count of PPE and semi-expendable property)

**Status:** Proposed (owner, 2026-11-03). Not yet built.

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
- Committee: `committee_chair_id`, `committee_member_ids` (JSON array), `coa_representative_id`. These reference Personnel and are used as report signatories.

**`inventory_count_items`** (expected list, one row per equipment item):

- `inventory_count_id`, `equipment_id`, unique(`inventory_count_id`, `equipment_id`).
- Values copied from the record: `office_id`, `condition_id`, `accountable_officer_id`, `location`.
- What was found: `result` (`pending` | `found` | `not_found`), `found_condition_id`, `found_holder_id`, `found_location`, `remarks`, `counted_by`, `counted_at`.

**`inventory_count_found_at_station`**: `inventory_count_id`, `office_id`, optional `equipment_id` (an IRIS item that belongs elsewhere), otherwise `description` / `property_no` / `serial_no`, `condition_id`, `remarks`, `counted_by`, `counted_at`.

All three models use `LogsActivity`. Open, close, cancel and reconcile are logged with `activity()`.

## Comparison buckets

| Bucket | Rule |
|---|---|
| Matched | `found`, condition and holder equal the copied record values |
| Condition changed | `found`, condition differs |
| Wrong holder / location | `found`, holder or location differs |
| Not found | `not_found` |
| Found at station | row in `inventory_count_found_at_station` |
| Pending | `pending` |
| Unserviceable (flag) | found condition is Unserviceable / For Repair, which feeds IIRUP / IIRUSP follow-up |

## Permissions (auth)

| Permission | Allows |
|---|---|
| `inventory_count.view` | See counts, progress and results |
| `inventory_count.conduct` | Mark items and add found-at-station items |
| `inventory_count.manage` | Create, open, close and cancel counts; set offices and committee |
| `inventory_count.reconcile` | Apply discrepancies to Equipment through transactions |

Seed an "Inventory Team" role with `view` + `conduct`. Every lookup and list is constrained by `Equipment::visibleTo($user)`.

## API (backend, `routes/api/management.php`)

```
GET    /inventory-counts                         view
GET    /inventory-counts/options                 view      (offices, personnel, name suggestions)
POST   /inventory-counts                         manage
PUT    /inventory-counts/{count}                 manage    (draft only)
POST   /inventory-counts/{count}/open            manage    (copies the expected list)
POST   /inventory-counts/{count}/close           manage
POST   /inventory-counts/{count}/cancel          manage
GET    /inventory-counts/{count}                 view      (progress per office)
GET    /inventory-counts/{count}/items           conduct|view   ?office=&result=&q=
GET    /inventory-counts/{count}/lookup          conduct   ?code=  (QR value / property no. / serial)
PUT    /inventory-counts/{count}/items/{item}    conduct   (open counts only)
POST   /inventory-counts/{count}/found-at-station conduct
GET    /inventory-counts/{count}/discrepancies   view
POST   /inventory-counts/{count}/reconcile       reconcile (closed counts only)
```

Logic lives in `InventoryCountService`: building the expected list (same scope as the RPCPPE: `visibleTo` + `atOffices` + `inCustody`), sorting items into buckets, and open/close. Counting endpoints are rate-limited.

## Screens (frontend, `pages/management/inventory-counts/`)

1. **Inventory Count list:** counts with as-of date, status and progress. `[+ New Count]` opens a form with Name (auto, editable), As-of date, Offices, Committee (chair, members, COA rep) and Remarks.
2. **Count details:** progress per office, the discrepancy table filterable by bucket, Close / Reconcile actions, and "Report due Jan 31" for Dec 31 counts.
3. **Count screen (`/inventory-counts/:id/count`):** pick an office, then scan (camera button only where a camera exists; Equipment already has `qr_code`) or type a property number. Found / Not found, condition, holder, remarks. Add found-at-station items. Shows "12 / 48 counted." On PC the list and selected item sit side by side; on phone they stack.

New files: `services/inventoryCountService.ts`, `types/inventoryCount.ts`.

## Reports

- RPCPPE and a new RPCSP download take an optional closed count. When one is given, "On Hand" and condition come from the count results and the committee fills the signatories.
- Printable ICF per day / counter from `counted_by` / `counted_at`.

## Delivery

- **Phase 1:** tables, permissions, counts CRUD and open/close, count screen, discrepancy list.
- **Phase 2:** reconcile into transactions, RPCPPE/RPCSP from a count, ICF print.

Each phase is signed off by qa, security (lookup scoping, validation, rate limits) and reviewer.
