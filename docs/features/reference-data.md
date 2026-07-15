# Reference Data

## Purpose

Every dropdown/reference list used across the app — item types, brands, equipment categories/classifications/conditions, positions, RO/SDO offices, ISP providers, and 26 smaller closed-vocabulary lists (units of measure, disposition statuses, name suffixes, signal-quality ratings, and more) — sourced from the "Referential Data" sheet(s) of the original Excel workbook this system replaces.

This supersedes the original single generic `lookups` table (one table, 35 types, discriminated by `App\Enums\LookupType`). That design is gone from the codebase entirely — `App\Models\Lookup`, `App\Enums\LookupType`, `LookupPolicy`, `LookupController`, `LookupSeeder`, and `resources/js/pages/lookups/` have all been deleted, and the `lookups` table itself was dropped by migration. See [`docs/architecture-decisions/lookup-normalization.md`](../architecture-decisions/lookup-normalization.md) for the full ADR — this doc covers the resulting system as it exists today.

## Architecture

A tiered design — two kinds of tables, not one uniform shape:

- **Tier 1 — 9 dedicated entity tables with real foreign keys**, for lookup concepts that are large, reused across resources, or actively filtered/reported on: `item_types`, `brands`, `equipment_categories`, `equipment_classifications`, `equipment_conditions`, `positions`, `ro_offices`, `sdo_offices`, `isp_providers`. Each has the shape `id`, `name` (unique), `description` (nullable), `sort_order`, `is_active`, timestamps. Consuming columns are genuine `_id` foreign keys with `belongsTo` relationships — e.g. `equipment.item_type_id → item_types.id`.
- **Tier 2 — 4 domain-grouped "library" tables**, for the remaining 26 small closed-vocabulary lookup types: `equipment_libraries`, `personnel_libraries`, `stakeholder_libraries`, `connectivity_libraries`. Each is still discriminated by a `type` column, but scoped to one bounded context instead of spanning the whole app. Consuming columns stay validated strings (`Rule::in()`), the same pattern the old `lookups` table used — Tier 2 concepts are 2–13-row closed vocabularies with no independent identity worth a dedicated table/FK.

No discriminator enum exists for Tier 1 (each table stands alone). Tier 2 is discriminated by four small, per-table backed enums: `App\Enums\EquipmentLibraryType`, `PersonnelLibraryType`, `StakeholderLibraryType`, `ConnectivityLibraryType`.

**`config/reference-data.php`** is the single source-of-truth registry for "what reference-data tables exist" — one entry per physical table, keyed by the kebab-case URL segment (`item-types`, `equipment-libraries`, ...), each carrying `label`, `model` (FQCN), `tier` (1 or 2), and — for Tier 2 entries only — `type_enum` (the backing enum FQCN). `App\Http\Controllers\ReferenceDataController` and `App\Http\Requests\ReferenceDataUpdateRequest` both read this registry rather than hardcoding 13 routes/branches.

**JSON-array multi-select columns** (e.g. `StakeholderProfile.nearby_institutions`, `InternetConnectivitySurvey.available_isps`) stayed JSON arrays through the redesign — no pivot tables were introduced. Their contents switched from arrays of value-strings to arrays of the referenced table's integer row ids, validated per-element (`Rule::exists($table, 'id')`).

## Admin screen: `/reference-data`

`App\Http\Controllers\ReferenceDataController` is **one generic controller** driving all 13 tables (not 13 near-identical controllers), gated on the `reference-data.manage` permission:

- `index()` — overview of all 13 tables (label, tier, row count), the landing page an admin picks a table from.
- `show($table)` — paginated, searchable (and, for Tier 2, type-filterable) row listing for one table.
- `update($table, $id)` — edits `description`/`sort_order`/`is_active` (+ `name` for Tier 1 — see below).

There is no `create`/`destroy` action, mirroring the old `LookupController`'s scope: the reference data is already fully seeded from the old `lookups` table's data, so there's no known need to add brand-new rows or hard-delete existing ones through this UI — deactivating a bad row (`is_active = false`) already covers "stop offering this without touching historical records that reference it."

`App\Policies\ReferenceDataPolicy` is **one shared Policy** (not 13 per-model Policies) bound explicitly to all 13 model classes in `AppServiceProvider::registerReferenceDataPolicies()` (Laravel's naming-convention auto-discovery only resolves a Policy named `{Model}Policy`, so a shared class needs explicit `Gate::policy()` registration per model). Every method is the same single `hasPermissionTo(ReferenceDataManage)` check — there is no per-table authorization nuance today.

### Permission: `reference-data.manage`

One umbrella permission (`App\Enums\Permission::ReferenceDataManage`) covers all 13 tables — deliberately not 13 fragmented permissions. Per the ADR and this codebase's granular-permission rule (which targets role-name checks vs. capability checks, not fragmenting one coherent capability into many), all 13 tables remain "one kind of low-stakes reference-data admin capability." This is the direct successor to the old `lookups.manage` permission, removed in the same cutover.

### Tier-1-name-is-editable-but-Tier-2-type/value-is-not

This is the one place Tier 1 and Tier 2 diverge in the admin UI, and it's deliberate — from `ReferenceDataUpdateRequest`'s doc-comment:

- **Tier 1 `name` IS editable.** Consumers (`Equipment`, `Personnel`, `IspAccount`) hold a real `_id` foreign key and always read the display name through the `belongsTo` relationship at render time (e.g. `$equipment->itemType->name`), never a copied string. A rename therefore propagates correctly and immediately everywhere, by construction — there is no stale-copy problem for a real FK to create. A per-table uniqueness rule applies (`Rule::unique($table, 'name')`) since two rows in the same reference table sharing a name would make the dropdown genuinely ambiguous.
- **Tier 2 `type`/`value` are NEVER accepted, by omission** — the same rule the old `LookupUpdateRequest` enforced for the `lookups` table. Tier 2 consumer columns deliberately stayed validated strings, not FKs, so every historical record holds its own **copy** of `value`. Renaming it here would not retroactively update those rows, silently orphaning them.

## Flow

Consuming controllers build their Create/Edit/Index dropdown options from the relevant Tier 1/Tier 2 models directly (the successor to the old `HasLookupOptions::lookupOptions()` trait) — Tier 1 options ship as `{value: id, label: name}`, Tier 2 keeps the old `{value, label}` shape.

```php
// EquipmentController@create — Tier 1 example
'options' => [
    'item_types' => ItemType::query()->active()->orderBy('sort_order')->get(['id', 'name'])
        ->map(fn ($t) => ['value' => $t->id, 'label' => $t->name]),
    // ...
],
```

## Inertia routes / prop contracts

| Route | Controller action | Page | Key props |
|---|---|---|---|
| `GET /reference-data` | `ReferenceDataController@index` | `reference-data/Index` | `tables: {key, label, tier, row_count}[]`, `officeCount` (row count for the "Institutional data" card — `App\Models\Office` is not one of the 13 tables, see below) |
| `GET /reference-data/{table}` | `ReferenceDataController@show` | `reference-data/Show` | `table`, `label`, `tier`, `rows` (paginated), `types` (Tier 2 only — `{value, label}[]` from the type enum, `null` for Tier 1), `filters: {search, type}` |
| `PATCH /reference-data/{table}/{id}` | `ReferenceDataController@update` | redirects to `reference-data.show` | — |

## Key files

- `config/reference-data.php` — the 13-table registry.
- `app/Http/Controllers/ReferenceDataController.php`, `app/Http/Requests/ReferenceDataUpdateRequest.php`, `app/Services/ReferenceDataResolver.php` (resolves a `{table}` route segment to its registry entry / model row).
- `app/Policies/ReferenceDataPolicy.php`, registered per-model in `app/Providers/AppServiceProvider.php::registerReferenceDataPolicies()`.
- Tier 1 models: `app/Models/ItemType.php`, `Brand.php`, `EquipmentCategory.php`, `EquipmentClassification.php`, `EquipmentCondition.php`, `Position.php`, `RoOffice.php`, `SdoOffice.php`, `IspProvider.php`.
- Tier 2 models: `app/Models/EquipmentLibrary.php`, `PersonnelLibrary.php`, `StakeholderLibrary.php`, `ConnectivityLibrary.php`, and their backing enums in `app/Enums/`.
- `database/seeders/ReferenceDataSeeder.php` + `database/seeders/data/*.json` (one JSON file per table, split from the original `lookups.json`).
- `resources/js/pages/reference-data/Index.vue`, `Show.vue`.
- `docs/architecture-decisions/lookup-normalization.md` — the full ADR (table-by-table column mapping, rollout sequencing, rationale for every decision summarized above).

## Non-obvious design decisions

- **Tiered, not uniform.** Neither "35 dedicated tables" nor "one grouped table" was chosen — see the ADR's "Why not the two more extreme options" section for the full reasoning (row-count distribution across the old 35 lookup types was the deciding input).
- **`school_level_coverage` moved domains** from its old "Organizational/stakeholder context" comment-grouping into `connectivity_libraries`, on actual-usage grounds (its only real consumer is `isp_accounts.school_area_coverage`).
- **Cross-domain reads of a Tier 2 table are expected and fine.** `equipment_libraries` is read by `IspAccount` and `StakeholderProfile` as well as `Equipment`/`EquipmentTransaction` — the table name denotes primary steward/origin, not an access boundary.
- **`Office` (2026-07-15) is deliberately NOT a 14th reference-data table.** Its column shape doesn't fit Tier 1 or Tier 2, and it's an FK backbone other tables depend on, not a closed lookup vocabulary — it has its own dedicated CRUD instead (`OfficeController`, admin-only permissions). `Index.vue` links to it from a separate "Institutional data" section, visually distinct from the Tier 1/Tier 2 grid; `officeCount` exists purely so that card shows a row count. See [`docs/features/offices.md`](offices.md).
- **No pivot tables for the nine JSON-array multi-select columns.** `StakeholderProfile`/`InternetConnectivitySurvey` were singletons when this decision was made (a pivot's whole value proposition is moot when the "many" side is permanently one row) — both have since converted to one row per `Office` (see their feature docs), but neither conversion introduced a pivot, since there's still no evidenced many-to-many reporting need against these columns. `Personnel.fund_source`'s Tier 2 vocabulary is in the same position on its own merits — no evidence of a real "find all personnel funded by X" query need today (YAGNI — promote to a pivot if that need surfaces on evidence, not speculatively).

## Future considerations

- **No create/delete through the admin UI.** Adding a genuinely new reference-data value or hard-deleting one still requires direct database access — same intentional scope the old `lookups.manage` screen had. `is_active = false` (deactivation) covers the common case.
- **`ReferenceDataPolicy` is deliberately one shared class.** If a genuine per-table authorization difference ever surfaces (e.g. a future requirement where positions can be managed by HR staff but brands cannot), that's a real trigger to split it into per-model Policies then, on evidence — not preemptively now.
