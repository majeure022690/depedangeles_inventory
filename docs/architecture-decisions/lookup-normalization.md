# ADR: Lookup Data Normalization — From One `lookups` Table to Tiered Reference Tables

**Status:** Accepted
**Date:** 2026-07-11
**Owner:** architect
**Supersedes:** the "one generic table, not 35 dropdown tables" decision recorded in `docs/features/lookups.md` and `docs/architecture.md#lookups-one-generic-table-not-35-dropdown-tables`.

## Context

The app currently has one polymorphic reference-data table, `lookups` (`id`, `type`, `value`, `description`, `sort_order`, `is_active`), discriminated by a 35-case backed enum `App\Enums\LookupType`. Every dropdown/reference list in the app — 35 lists, 677 seeded rows sourced from the original Excel workbook's "Referential Data" sheet — lives in this one table. Every consuming column across five already-built, tested, reviewed resources (`Equipment`, `EquipmentTransaction`, `Personnel`, `IspAccount`, `StakeholderProfile`, `InternetConnectivitySurvey`) stores the lookup's plain `VARCHAR` value directly, validated at the application layer via `Rule::in(Lookup::activeValues($type))`, not enforced by a foreign key.

The user has rejected this as improper relational design — a single melting-pot table discriminated by a type column isn't "a real table" for any one concept, and asked for it to be replaced with grouped, dedicated tables ("libraries," grouped into different tables). This ADR makes that call.

Actual row-count distribution per lookup type (from `database/seeders/data/lookups.json`, 677 rows / 35 types) matters to the decision:

| Rows | Type(s) |
|---|---|
| 316 | `position` |
| 76 | `brand` |
| 65 | `item_type` |
| 8–13 | `ro_offices`, `by_transportation`, `nearby_institution`, `name_suffix`, `source_of_acquisition`, `cause_of_separation`, `isp_provider`, `dcp_package`, `supporting_document_type`, `isp_connection_type`, `coverage_area`, `disposition_status`, `school_level_coverage`, `sdo_offices`, `source_of_electricity` |
| 2–7 | the remaining 20 types (`source_of_funds`, `transaction_type`, `teachers_funding_source`, `classification`, `signal_quality`, `type_of_access_road`, `mobile_network_signal`, `allotment_class`, `condition`, `governance_level`, `purpose_of_subscription`, `unit_of_measure`, `mode_of_acquisition`, `community_context`, `community_engagement`, `subscription_type`, `category`) |

Three of thirty-five types account for nearly 70% of all rows; twenty types have five rows or fewer. This spread is the strongest single input into the design below: it rules out treating every type identically (either as 35 equal "real" tables or as N equal "grouped" tables), because the types themselves aren't equal in size, reuse, or how central they are to reporting.

## Decision

**A tiered design, not a uniform one.** Two kinds of tables replace the single `lookups` table:

- **Tier 1 — 9 dedicated tables with real foreign keys.** For lookup concepts that are genuine, reusable, first-class entities in this domain — large lists, lists referenced from more than one resource, or lists that are actively filtered/reported on (i.e. already have a query scope). Consumer columns become real `_id` foreign keys with `belongsTo` relationships.
- **Tier 2 — 4 domain-grouped "library" tables**, each still discriminated by a `type` column but scoped to one bounded context instead of the whole app. For the remaining 26 lookup concepts — small, closed vocabularies (2–13 rows) with no independent identity of their own. Consumer columns stay validated strings, same pattern as today, just no longer sharing one global table with unrelated domains.

`App\Enums\LookupType` is deleted outright. It is not replaced by one successor enum — Tier 1 needs no discriminator enum at all (each table stands alone), and Tier 2 gets **four small, per-table enums** instead of one 35-case enum spanning unrelated domains.

Both tiers are real, physical, purpose-named tables. Neither is "the lookups table under a new name" — that was the thing being rejected.

### Why not the two more extreme options

**Rejected: 35 dedicated tables, one per `LookupType` case.** This is the maximal-normalization option and was seriously considered, since it gives every concept real referential integrity uniformly. Rejected because it fails this project's own established cost/benefit standard (see the flat-vs-`Domain/` decision elsewhere in `CLAUDE.md`): twenty of the thirty-five types have five or fewer rows (`category` has 2, `subscription_type` has 2, `unit_of_measure` has 3). A dedicated table, model, migration, seeder entry, and FK column for a 2-row closed list (e.g. `subscription_type`: "Postpaid"/"Prepaid") is pure ceremony — it behaves exactly like an enum in practice, and the only reason it isn't a native PHP enum already is that it must stay admin-editable through the `lookups.manage` screen (a deliberate, already-reviewed decision — see `docs/features/lookups.md`). 35 tables would roughly quadruple the migration/model/seeder count of the entire app's existing schema for no proportional gain on the small lists.

**Rejected: 4–6 domain-grouped tables only, no FK anywhere.** This is closest to literally "group libraries into different tables" and was the leading alternative. Rejected as the *whole* answer because it doesn't actually fix the user's core complaint for the handful of concepts that matter most: `brand` (76 rows, constantly maintained as new equipment models arrive), `item_type` (65 rows), `position` (316 rows — the single largest list in the system) and `isp_provider` (referenced from two different tables' JSON arrays as well as `isp_accounts.isp`) are genuine reusable entities, not adjectives. A foreign key into a table that's still internally discriminated by a shared `type` column is not meaningfully more "proper" than a validated string — nothing at the schema level stops `item_type_id` from pointing at a `brand` row. Real referential integrity requires a table dedicated to one concept; grouping-only can't deliver that where it's actually worth having.

The tiered design is the option that spends the FK/dedicated-table cost only where it buys something real, and uses the grouped-table shape — which does directly satisfy "group libraries into different tables" — everywhere else.

## Question 1 — Table count and contents

### Tier 1 — dedicated entity tables (real FK target)

| Table | Model | Columns | Replaces `LookupType` case | Selection reason |
|---|---|---|---|---|
| `item_types` | `ItemType` | `id`, `name`, `description`, `sort_order`, `is_active`, timestamps | `item_type` | Core Equipment identity field, 65 rows |
| `brands` | `Brand` | same shape | `brand` | Largest equipment-adjacent list (76 rows), grows continuously as new equipment arrives |
| `equipment_categories` | `EquipmentCategory` | same shape | `category` | Has a dedicated query scope (`Equipment::scopeCategory`) — an active filter/report facet, not just descriptive text |
| `equipment_classifications` | `EquipmentClassification` | same shape | `classification` | Paired concept with category; same tier for consistency (both are core Equipment taxonomy, always presented/filtered together) |
| `equipment_conditions` | `EquipmentCondition` | same shape | `condition` | Has a dedicated query scope (`Equipment::scopeCondition`); serviceable/unserviceable counts are a standard government inventory reporting metric — worth the FK even at only 4 rows |
| `positions` | `Position` | same shape | `position` | Largest list in the entire system (316 rows) |
| `ro_offices` | `RoOffice` | same shape | `ro_offices` | Organizational entity (an actual office), not a descriptive adjective |
| `sdo_offices` | `SdoOffice` | same shape | `sdo_offices` | Same reasoning as `ro_offices` |
| `isp_providers` | `IspProvider` | same shape | `isp_provider` | Referenced from three places (`isp_accounts.isp`, `internet_connectivity_surveys.available_isps`, `.subscribed_isps`) — genuine cross-table reuse; has a dedicated query scope (`IspAccount::scopeProvider`) |

Shared column shape for all nine: `id`, `name` (string, unique — deliberately `name`, not `value`, signaling these are real entities, not generic K/V rows), `description` (nullable text), `sort_order` (unsigned int, default 0), `is_active` (boolean, default true), `created_at`/`updated_at`.

### Tier 2 — domain-grouped library tables (grouped, still string-validated)

| Table | Model | Backing enum | `LookupType` cases moved in |
|---|---|---|---|
| `equipment_libraries` | `EquipmentLibrary` | `EquipmentLibraryType` | `unit_of_measure`, `disposition_status`, `dcp_package`, `mode_of_acquisition`, `source_of_acquisition`, `source_of_funds`, `allotment_class`, `supporting_document_type`, `transaction_type` (9) |
| `personnel_libraries` | `PersonnelLibrary` | `PersonnelLibraryType` | `name_suffix`, `teachers_funding_source`, `cause_of_separation` (3) |
| `stakeholder_libraries` | `StakeholderLibrary` | `StakeholderLibraryType` | `governance_level`, `community_context`, `type_of_access_road`, `by_transportation`, `nearby_institution`, `community_engagement`, `source_of_electricity` (7) |
| `connectivity_libraries` | `ConnectivityLibrary` | `ConnectivityLibraryType` | `isp_connection_type`, `subscription_type`, `purpose_of_subscription`, `signal_quality`, `mobile_network_signal`, `coverage_area`, `school_level_coverage` (7) |

Column shape (identical to today's `lookups` table, just domain-scoped): `id`, `type` (string(64)), `value`, `description` (nullable text), `sort_order`, `is_active`, timestamps, unique(`type`,`value`), index(`type`,`is_active`,`sort_order`).

Total: **13 physical tables** (9 + 4), replacing 1 table + a 35-case enum. 9+26=35 — every existing lookup type is accounted for.

**`school_level_coverage` moves domains.** The old enum's comment grouped it under "Organizational / stakeholder context," but its only real consumer is `isp_accounts.school_area_coverage` — it belongs in `connectivity_libraries` on actual-usage grounds, not the old enum's comment banding. This is a deliberate correction, not an oversight.

**Cross-domain reads of a Tier 2 table are expected and fine.** `equipment_libraries` is read by `IspAccount` (`mode_of_acquisition`/`source_of_acquisition`/`source_of_funds`, which Equipment and IspAccount already share today) and by `StakeholderProfile` (`transaction_type`, shared with `EquipmentTransaction`). The table name denotes primary steward/origin, not an access boundary — that's exactly what reference/lookup data is for. Don't let "domain-grouped" be misread as "domain-exclusive."

## Question 2 — Foreign keys vs. validated strings

**Both, split by tier, deliberately — not one uniform answer.**

- **Tier 1 consumer columns become real foreign keys** with `belongsTo` relationships. This is what actually delivers the "proper" relational structure the user asked for: a `NOT NULL` FK constraint that the database enforces, a real join for display, and one place to rename a value that propagates everywhere via the relationship rather than a mass string `UPDATE`.
- **Tier 2 consumer columns stay validated strings** (`Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::UnitOfMeasure))`, same shape as today's `Rule::in(Lookup::activeValues(...))`, just repointed at the new table). For a 2–13-row closed vocabulary with a single primary consumer, an FK column buys referential integrity the app doesn't meaningfully need (these lists essentially never get renamed mid-flight, and Tier 2 tables are still admin-editable/deactivatable through the successor of `lookups.manage`, so the "propagate a rename" argument for FK doesn't apply the same way it does to `brands` or `positions`).

Concrete column changes:

| Table | Old column (string) | New column | Target |
|---|---|---|---|
| `equipment` | `item` | `item_type_id` | FK `item_types` |
| `equipment` | `brand_manufacturer` | `brand_id` | FK `brands` |
| `equipment` | `category` | `equipment_category_id` | FK `equipment_categories` |
| `equipment` | `classification` | `equipment_classification_id` | FK `equipment_classifications` |
| `equipment` | `equipment_condition` | `equipment_condition_id` | FK `equipment_conditions` |
| `equipment` | `uom`, `disposition_status`, `dcp_package`, `mode_acquisition`, `source_acquisition`, `source_fund`, `allotment_class` | *(unchanged names)* | string, `Rule::in()` against `equipment_libraries` |
| `equipment_transactions` | `transaction_type`, `supporting_documents1`, `supporting_documents2` | *(unchanged)* | string, `Rule::in()` against `equipment_libraries` |
| `personnel` | `position` | `position_id` | FK `positions` |
| `personnel` | `ro_division` | `ro_office_id` | FK `ro_offices` |
| `personnel` | `division_unit` | `sdo_office_id` | FK `sdo_offices` |
| `personnel` | `suffix`, `separation_cause` | *(unchanged)* | string, `Rule::in()` against `personnel_libraries` |
| `personnel` | `fund_source` (JSON array) | *(unchanged column, contents change)* | JSON array of `personnel_libraries` row ids |
| `isp_accounts` | `isp` | `isp_provider_id` | FK `isp_providers` |
| `isp_accounts` | `school_area_coverage`, `subscription_type`, `isp_connection_type`, `purpose_of_subscription`, `mode_of_acquisition`, `source_of_acquisition`, `source_of_funds`, `overall_signal_quality` | *(unchanged)* | string, `Rule::in()` against the matching Tier 2 table |
| `stakeholder_profiles` | `governance_level`, `transaction_type` | *(unchanged)* | string, `Rule::in()` |
| `stakeholder_profiles` | `nearby_institutions`, `access_paths`, `transportation_options`, `community_engagement` (JSON arrays) | *(unchanged columns, contents change)* | JSON array of `stakeholder_libraries` row ids |
| `internet_connectivity_surveys` | `mobile_data_quality` | *(unchanged)* | string, `Rule::in()` against `connectivity_libraries` |
| `internet_connectivity_surveys` | `available_isps`, `subscribed_isps` (JSON arrays) | *(unchanged columns, contents change)* | JSON array of `isp_providers` row ids |
| `internet_connectivity_surveys` | `mobile_signal_types`, `coverage_areas` (JSON arrays) | *(unchanged columns, contents change)* | JSON array of `connectivity_libraries` row ids |
| `internet_connectivity_surveys` | `electricity_sources` (JSON array) | *(unchanged column, contents change)* | JSON array of `stakeholder_libraries` row ids |

**Naming note:** new FK columns go straight to their final name (`item_type_id`, not a temporary name renamed later) so Backend/Frontend touch each column exactly once, not twice.

## Question 3 — Fate of `App\Enums\LookupType`

**Deleted outright.** Not repurposed, not kept as a legacy compatibility shim.

- Tier 1 needs no discriminator enum — each concept is its own table; "which lookup type is this" is answered by which model/table you're looking at, the same way `Equipment` vs `Personnel` already are.
- Tier 2 gets **four small, per-table backed enums** (`EquipmentLibraryType`, `PersonnelLibraryType`, `StakeholderLibraryType`, `ConnectivityLibraryType`, 9/3/7/7 cases respectively) instead of one 35-case enum spanning unrelated domains. Each mirrors `LookupType`'s existing shape (backs the `type` column of exactly one table, has a `label()` method) — just scoped, matching the tables it discriminates.
- **Generic "list every reference table" need** (an admin index, or any future code that must enumerate all 13 reference tables generically) is served by a small static registry — a `config/reference-data.php` array (label, model class, and, for Tier 2 entries, which enum backs it) — not a revived global enum. This is Backend's implementation detail to build; the requirement is just that exactly one such registry exists, so the admin index isn't 13 independently-hardcoded page routes with no single source of truth for "what reference tables exist."

## Question 4 — JSON-array multi-select columns

**Stay JSON arrays. Contents switch from value-strings to the referenced table's integer row ids, validated per-element (`'field.*' => Rule::exists($table, 'id')->where(...)` for Tier 2, plain `Rule::exists('isp_providers', 'id')` for the Tier-1-backed ones). No pivot tables are introduced anywhere.**

Two independent reasons, covering all nine JSON-array columns in play (`Personnel.fund_source`; `StakeholderProfile.nearby_institutions/access_paths/transportation_options/community_engagement`; `InternetConnectivitySurvey.available_isps/subscribed_isps/mobile_signal_types/coverage_areas/electricity_sources`):

1. **`StakeholderProfile` and `InternetConnectivitySurvey` were singletons at the time of this decision** (exactly one row each, ever — see `docs/architecture.md`'s data-access-patterns section). A many-to-many pivot table's entire value proposition — efficient joins and integrity across *many* parent rows — is moot when the "many" side is permanently fixed at one row. A pivot here would be pure ceremony: a join table that will only ever contain rows for a single `stakeholder_profile_id` / `internet_connectivity_survey_id`. (Both tables were later converted to one row per `Office` — 2026-07-14 and 2026-07-15 respectively — but their office-scoping conversions kept the JSON-array columns as-is, no pivot table introduced; see `docs/features/stakeholder-profile.md` / `docs/features/internet-connectivity-survey.md`. The "many" side is no longer permanently fixed at exactly one row post-conversion, but the same absence of an evidenced many-to-many reporting need — the driver behind reason 2 below — still applied, so this conclusion wasn't revisited.)
2. **`Personnel.fund_source` is not on a singleton** (hundreds of real rows), but `teachers_funding_source` is a Tier 2 closed vocabulary (6 rows) with no evidence of a real "find all personnel funded by X" query need today. Per this codebase's own YAGNI standard, that's not built speculatively. JSON-array-of-ids gets the same integrity improvement (ids instead of free-text strings, validated against real rows) at a fraction of the cost of `attach()`/`sync()` plumbing and an extra table.

If a genuine many-to-many reporting need against `fund_source` (or any of these) surfaces later, that's a real trigger to revisit — promote that one column to a pivot table then, on evidence, not now, speculatively, for all nine.

## Question 5 — Migration / rollout plan

Sequenced so the app is never in a broken state mid-migration. Old and new columns coexist until each resource's read/write paths have fully cut over; nothing is dropped until QA has signed off on that resource.

**Step 1 — Database (additive only, non-breaking):**
1. Create the 9 Tier 1 tables and 4 Tier 2 tables (schemas above).
2. Rewrite the seeder: split `database/seeders/data/lookups.json` (already has every row tagged by its old `type`) by destination table, feeding 13 new seeders (or one seeder with 13 target-aware branches) instead of `LookupSeeder`. No re-extraction from Excel — the 677 rows already exist and just get redistributed.
3. Add new FK columns to `equipment`, `personnel`, `isp_accounts` — **nullable** at first, alongside the still-live old string columns.
4. Backfill: match each row's existing string value against the new Tier 1 table's `name` and populate the new `_id` column. Report any unmatched values (typos/case drift) for manual review before anything depends on the FK — do not silently null them.
5. Data-only migration for the nine JSON-array columns: rewrite their contents from value-strings to the corresponding table's row ids.
6. Leave the old `lookups` table and old string columns in place through this entire step — nothing consumer-facing has changed yet.

**Step 2 — Backend (per resource, after step 1's data exists for that resource):**
1. New models for all 13 tables (`activeOptions()`/`activeValues()` helpers matching `Lookup`'s existing shape), the 4 new Tier 2 enums, and `belongsTo` relationships on `Equipment`/`Personnel`/`IspAccount` for the 9 new FKs.
2. Update each affected Form Request: Tier 1 fields move from `Rule::in(Lookup::activeValues(...))` to `Rule::exists($table, 'id')`-style validation of the id; Tier 2 fields move from `Lookup::activeValues(LookupType::X)` to `{Domain}Library::activeValues({Domain}LibraryType::X)`.
3. Update controller prop-building (the `HasLookupOptions`-equivalent) so Tier 1 options ship as `{value: id, label: name}` and Tier 2 keeps today's `{value, label}` shape.
4. Update every index/show/edit prop mapping to surface the FK'd relation's display name where the old code read the raw string column (e.g. `$equipment->itemType?->name` in place of `$equipment->item`) — this is the seam most likely to silently break an existing screen if missed.
5. Check `Equipment::scopeCategory`/`scopeCondition` (now filtering by id, not string) against `EquipmentController`'s index filters; `scopeDispositionStatus` stays string-based (Tier 2) — don't convert it by mistake.
6. Check any dashboard aggregation that currently groups/counts by the raw string column (category/condition/disposition breakdowns are typical government inventory metrics) — these must group by the new id and join for display, or counts will silently miscategorize.
7. Delete `App\Models\Lookup` and `App\Enums\LookupType` only after grepping the codebase clean of both.
8. Decide the successor permission for `lookups.manage` (recommend one umbrella `reference-data.manage` covering all 13 tables, not 13 fragmented permissions — these remain "one kind of low-stakes reference-data admin capability," and CLAUDE.md's granular-permission rule is about role-name checks vs. capability checks, not about fragmenting one coherent capability into many). This crosses into `auth`'s territory — architect sets the default, `auth` implements/confirms.

**Step 3 — Frontend (per resource, after Backend ships that resource's new prop shape):**
1. Update `resources/js/types/*.ts` for the 5 affected resources to reflect FK id + related-object shape on Tier 1 fields.
2. Rewire Create/Edit `<Select>`/combobox bindings for the 9 Tier 1 fields to the `_id` column, using the new `{value: id, label: name}` options — mechanically identical to today's wiring, just id-typed.
3. Update Index/Show displays reading the old raw string to read the related object's `name`.
4. Coordinate with Backend on whether Index filter dropdowns (category/condition/etc.) filter by id or string post-migration; recommend id, matching the new scope shape.
5. Replace `resources/js/pages/lookups/Index.vue` with the reference-data admin successor — one generic, table-driven component (parametrized by the new registry from Step 2's Backend work) rather than 13 bespoke pages, to keep this proportionate.

**Step 4 — cleanup (only after every consuming resource has been cut over and QA has signed off on each):**
1. Drop the old string lookup columns on `equipment`, `personnel`, `isp_accounts`.
2. Make the new FK columns `NOT NULL` where the original string column was required.
3. Drop the `lookups` table and its migration's seed data file (or archive `lookups.json` — it's still useful provenance for "what did this used to look like," Database's call).

**Sequencing across resources:** do not attempt all five consuming resources in one pass. Recommended order — `Equipment` first (most Tier 1 fields, highest-traffic screen, best regression coverage to catch mistakes early), then `Personnel`, then `IspAccount`, then the two singleton survey pages last (lowest traffic, JSON-array-only changes, least risky to leave for last). Each resource goes through Database → Backend → Frontend → QA → Security (if touching validation) before the next resource starts, matching CLAUDE.md's existing multi-domain routing rule.

**What's genuinely at risk if this is rushed:**
- Any screen reading the old raw string column after Backend has renamed it server-side but before Frontend has updated (breaks silently — Inertia just serializes `null`/`undefined` where a string used to be, not a hard error).
- Dashboard aggregations keyed on the raw string column (see Step 2.6).
- The `lookups.manage`/`LookupPolicy`-gated admin screen, which has real (if minimal) working functionality today — its successor must exist before `lookups.manage`/`LookupPolicy`/`LookupController`/`lookups/Index.vue` are removed, not after.
- `docs/architecture.md` and `docs/features/lookups.md` describe the old design in detail (including a "no admin UI exists yet" note that's already stale relative to the current `LookupController`/`lookups/Index.vue` — a pre-existing documentation drift this migration should fix in passing, not just add to). `documentation` must rewrite both once implementation lands — flagged here per CLAUDE.md's "Documentation agent notified of any structural changes" rule.
