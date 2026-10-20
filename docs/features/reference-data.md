# Reference Data

## Purpose

Every dropdown/reference list in the app — item types, brands, equipment categories/classifications/conditions, positions, RO/SDO offices, ISP providers, and 26 smaller closed vocabularies (units of measure, disposition statuses, name suffixes, signal-quality ratings, ...) — originally sourced from the "Referential Data" sheets of the Excel workbook this system replaces.

This superseded the original single generic `lookups` table (35 types discriminated by `App\Enums\LookupType`), which is gone from the codebase and the database. The full reasoning is in the [lookup-normalization ADR](../architecture-decisions/lookup-normalization.md); this doc covers the system as it exists now. The SPA conversion changed only the transport (JSON endpoints instead of Inertia props), not the tiers or rules.

## Architecture

- **Tier 1 — 9 dedicated tables with real FKs** for concepts that are large, reused, or filtered/reported on: `item_types`, `brands`, `equipment_categories`, `equipment_classifications`, `equipment_conditions`, `positions`, `ro_offices`, `sdo_offices`, `isp_providers`. Shape: `id`, `name` (unique), `description`, `sort_order`, `is_active`, timestamps. Consumers hold `_id` FKs (`restrictOnDelete`) and read the name through `belongsTo`.
- **Tier 2 — 4 domain-grouped library tables** for the 26 small vocabularies: `equipment_libraries`, `personnel_libraries`, `stakeholder_libraries`, `connectivity_libraries`, each discriminated by `type` (enums `EquipmentLibraryType`, `PersonnelLibraryType`, `StakeholderLibraryType`, `ConnectivityLibraryType`). Consumers store the `value` string, validated with `Rule::in()`.
- **JSON-array multi-select columns** (e.g. `StakeholderProfile.nearby_institutions`, `InternetConnectivitySurvey.available_isps`, `Personnel.fund_source`) store referenced row **ids**, validated per element; no pivot tables.
- **`config/reference-data.php`** is the single registry: one entry per table, keyed by the URL segment (`item-types`, `equipment-libraries`, ...), with `label`, `model`, `tier`, and `type_enum` for Tier 2. `ReferenceDataController`, its Form Requests and `App\Services\ReferenceDataResolver` read it instead of hard-coding 13 routes.
- **`App\Policies\ReferenceDataPolicy`** — one shared Policy bound to all 13 models in `AppServiceProvider::registerReferenceDataPolicies()` (auto-discovery only finds `{Model}Policy`). Every ability is the same `reference-data.manage` check.
- **Audit:** every model uses `App\Concerns\LogsReferenceDataActivity` (`reference_data.created|updated|deleted`, with `properties.table` = the registry key).

### Options for consuming forms

Each resource's `GET /api/<resource>/options` builds its own dropdowns via `Api\Management\Concerns\HasLookupOptions`, active rows only, in `sort_order`:

- Tier 1: `{value: id, label: name}` (`referenceOptions()`, backed by each model's `activeOptions()`).
- Tier 2: `{value: string, label: string}` (`libraryOptions()`).
- Tier 2 backing a JSON-array column: `{value: id, label: value}` (`libraryOptionsById()`).

## Permission: `reference-data.manage`

One umbrella permission for all 13 tables, admin-only by seed. They are one low-stakes kind of admin capability; splitting it into 13 permissions would add nothing. Successor to the old `lookups.manage`.

## API endpoints

Controller: `Api\Management\ReferenceDataController`; every route carries `permission:reference-data.manage`. `{table}` is a registry key (`[a-z-]+`); an unknown key is a 404.

| Method + path | Policy | Request → response |
|---|---|---|
| `GET /api/reference-data` | permission string | `{data: {tables: [{key, label, tier, row_count}], office_count}}` — `office_count` only feeds the "Institutional data → Offices" card (Office is not one of the 13) |
| `GET /api/reference-data/{table}?search&type&per_page` | `viewAny` | paginated `ReferenceDataRowResource` plus `meta.table = {key, label, tier, types: {value, label}[] | null}`. `search` matches name/value or description; `type` filters Tier 2. |
| `POST /api/reference-data/{table}` | `create` (Form Request) | `StoreReferenceDataRequest` → row, 201. Tier 1: `name` (required, unique), Tier 2: `type` (enum) + `value` (unique within type); both: `description`, `sort_order` (default 0), `is_active` (default true). |
| `PATCH /api/reference-data/{table}/{id}` | `update` | `UpdateReferenceDataRequest`: `description`, `sort_order` and `is_active` required; Tier 1 also `name`. Tier 2 `type`/`value` are never accepted. |
| `DELETE /api/reference-data/{table}/{id}` | `delete` | 204, or **409** `"This value is still in use and cannot be deleted — deactivate it instead."` |

**`ReferenceDataRowResource`:** Tier 1 `{id, name, description, sort_order, is_active}`; Tier 2 `{id, type, value, description, sort_order, is_active}`.

## Frontend

| Route | Page |
|---|---|
| `/reference-data` | `pages/management/reference-data/ReferenceDataIndex.tsx` — Tier 1 / Tier 2 grid plus the "Institutional data → Offices" card (`office.view`) |
| `/reference-data/:table` | `pages/management/reference-data/ReferenceDataTable.tsx` — list with create/edit dialog, Tier 2 `type` filter, 409 message on in-use delete |

API: `services/referenceDataService.ts`; types: `types/reference-data.ts`.

## Key files

- `backend/config/reference-data.php`
- `backend/app/Http/Controllers/Api/Management/ReferenceDataController.php`, `Concerns/HasLookupOptions.php`
- `backend/app/Http/Requests/Management/StoreReferenceDataRequest.php`, `UpdateReferenceDataRequest.php`
- `backend/app/Http/Resources/Management/ReferenceDataRowResource.php`
- `backend/app/Services/ReferenceDataResolver.php` — registry lookup, row resolution, `guardDeletable()`
- `backend/app/Policies/ReferenceDataPolicy.php`, `backend/app/Concerns/LogsReferenceDataActivity.php`
- Tier 1 models (`ItemType`, `Brand`, `EquipmentCategory`, `EquipmentClassification`, `EquipmentCondition`, `Position`, `RoOffice`, `SdoOffice`, `IspProvider`), Tier 2 models (`EquipmentLibrary`, `PersonnelLibrary`, `StakeholderLibrary`, `ConnectivityLibrary`) and their enums
- `backend/database/seeders/ReferenceDataSeeder.php` + `database/seeders/data/*.json`

## Non-obvious design decisions

- **Tiered, not uniform.** Neither "35 tables" nor "one table" — see the ADR for why the row-count distribution decided it.
- **Tier 1 `name` is editable; Tier 2 `type`/`value` are not.** Tier 1 consumers hold an FK and read the name at render time, so a rename propagates everywhere. Tier 2 consumers hold their own **copy** of `value`, so a rename would silently orphan every historical record; to replace a Tier 2 value, add a new one and deactivate the old.
- **Two-layer delete guard.** Tier 1 FKs are `restrictOnDelete`, so the delete is attempted and the FK violation becomes a 409 (no racy pre-check). Tier 2 strings/JSON ids and `isp_providers` ids inside the survey's JSON columns have no FK, so `ReferenceDataResolver::guardDeletable()` checks a fixed table.column map first. Both paths give the same 409 message. Deactivation (`is_active = false`) is the normal way to retire a value without touching history.
- **Cross-domain reads of a Tier 2 table are fine.** `equipment_libraries` is also read by ISP Accounts and Stakeholder Profile; the table name denotes the steward, not an access boundary.
- **`school_level_coverage` lives in `connectivity_libraries`**, by actual usage (its only consumer is `isp_accounts.school_area_coverage`).
- **`Office` is not a 14th table.** It doesn't fit either tier and is an FK backbone; it has its own CRUD ([`offices.md`](offices.md)).
- **No pivot tables for the JSON multi-selects** — no evidenced "find all X with value Y" reporting need yet (YAGNI; promote to a pivot on evidence).

## Future considerations

- `ReferenceDataPolicy` is one shared class. Split it into per-model Policies only if a real per-table authorization difference appears.
