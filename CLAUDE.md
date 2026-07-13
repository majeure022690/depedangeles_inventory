# Inventory — DepEd Division ICT Inventory System

## Stack

Built on the official **Laravel Vue Starter Kit**. Do not replace or fork any of these — extend them:

- Laravel 13, PHP 8.3
- Inertia.js v3 (`inertiajs/inertia-laravel` + `@inertiajs/vue3`) — this is a **server-driven monolith, not a REST API**. Pages are returned via `Inertia::render()`; there is no separate `api.php` surface unless a genuine external consumer needs one.
- Vue 3 + Composition API + `<script setup>`, TypeScript
- Laravel Fortify for authentication (session-based, driving Inertia auth pages) — extend it, never fork it
- Tailwind CSS + shadcn-vue
- Laravel Wayfinder (typed route/controller helpers for the frontend)
- Vite

Structure is stock Laravel throughout — flat `app/Models/`, `app/Http/Controllers/`, `app/Http/Requests/`, `app/Policies/`, `app/Services/`, `app/Enums/` — not `app/Domain/<Feature>/`. See "Project structure" below for why and what "feature-first" means here instead.

## Agent roster

This project uses a fixed set of specialist agents (`~/.claude/agents/*.md`), each owning a distinct slice of the stack. Use the `Agent` tool with the matching `subagent_type` rather than doing cross-domain work yourself in the main thread.

| Agent | Owns |
|---|---|
| `architect` | Structure, module boundaries, cross-cutting technical decisions. Final say when agents disagree. |
| `backend` | Inertia controllers, Eloquent models, Services/Actions, Jobs/Events/Notifications, `routes/web.php`. |
| `frontend` | Vue pages/components/layouts under `resources/js/`, Tailwind/shadcn-vue UI, accessibility. |
| `database` | Migrations, seeders, factories, indexes, schema, query performance. |
| `auth` | Roles, permissions, Policies, Gates, authorization middleware, session security — extends Fortify, never forks it. |
| `integration` | External/government APIs, file import/export, webhooks, queue-driven background processing, the narrow `routes/api.php` if one is ever genuinely needed. |
| `qa` | Functional/edge-case/accessibility/regression validation before sign-off. |
| `devops` | Env config, CI/CD, caching, queue infrastructure, storage, logging, production readiness. |
| `security` | OWASP review of anything touching auth, input, uploads, or external data. Gate before merge. |
| `documentation` | Feature docs, architecture docs, setup/deploy guides, changelog. |
| `reviewer` | Final quality gate — reviews the combined output of all other agents before anything is "done". |

Each agent's own file is the source of truth for its scope, decision rules, and completion checklist — don't duplicate that detail here.

## How to route work

1. **Identify the dominant domain** of the request (schema change → `database`, UI work → `frontend`, external system → `integration`, access control → `auth`, etc.) and delegate to that agent first.
2. **Multi-domain features** (the common case — e.g. "add an equipment transfer workflow") get broken up and delegated in dependency order, typically: `architect` (if structure is non-obvious) → `database` → `backend` → `auth` (if new permissions are needed) → `frontend` → `qa` → `security` → `reviewer`. Not every step is needed for every feature — skip agents whose domain isn't touched.
3. **Small, single-domain, low-risk changes** (a typo, a one-line style fix, a config tweak already in scope) can be done directly without spinning up an agent — don't over-delegate trivial work.
4. **Never let an agent touch another's owned files.** If `backend` needs a migration, it asks `database` for one rather than writing it. If `frontend` needs a new prop, it asks `backend` rather than fetching data client-side.
5. **Security and QA sign-off are required, not optional**, for anything touching auth, validation, file uploads, or external data before `reviewer` gives final approval.
6. **Granular permissions, not role-name checks** — this is a hard rule from `auth`'s spec: `$user->can('equipment.update')`, never `if ($user->role === 'admin')`. Enforce it everywhere, including in code any other agent writes.
7. **This app does not need a REST API.** Reject any design that reintroduces one unless `integration` has identified a genuine external consumer.

## Project structure — flat stock-Laravel, not `app/Domain/<Feature>/`

**Decision (architect, 2026-07-10):** this app uses flat stock-Laravel directories on the backend — `app/Models/`, `app/Http/Controllers/`, `app/Http/Requests/`, `app/Policies/`, `app/Services/`, `app/Enums/` — not per-domain folders like `app/Domain/Personnel/`. This was previously undocumented drift (every feature built so far — Personnel, Equipment, roles/permissions/Users-admin — independently converged on flat structure without anyone deciding it); it's now the explicit, intended standard, not an oversight.

Why: at this project's actual scale (a single division office's asset/personnel/ISP inventory — currently ~8 models, ~6 controllers, ~3 policies, ~2 services, ~15 migrations, one real bounded context) a `Domain/` split would nest most resources one level deeper for a single Model/Controller/Policy each — added navigation depth with no deduplication or clarity payoff. `Domain/` folders earn their cost when domains have independent lifecycles and real internal complexity (several entities/aggregates, invariants that don't leak across the boundary) and often separate teams — none of which applies here. Personnel, Equipment, and Users are resources inside one bounded context, not separate domains. Flat structure also matches every Laravel generator, tutorial, and package convention, which minimizes onboarding cost for the small team actually maintaining this system.

What "feature-first" means here instead — this is not "no organization," it's organization by naming and by the frontend split, not by backend folder nesting:

- **One Controller + Policy + FormRequest(s) + (optional) Service per resource, named identically to that resource** — e.g. `EquipmentController`, `EquipmentPolicy`, `EquipmentStoreRequest`/`EquipmentUpdateRequest`, `EquipmentAccountabilityService`. Consistent naming is the mechanism that keeps things discoverable in a flat directory, not folder nesting.
- **Frontend keeps its already-correct feature split**: `resources/js/pages/<feature>/` (e.g. `resources/js/pages/equipment/`, with `Partials/` for page-specific subcomponents). This is free/natural for Inertia — pages are inherently one-file-per-route — and has no backend equivalent forcing function, so it isn't evidence the backend should mirror it via folders.
- **Shared/cross-cutting code lives in small, clearly-labeled shared areas**, not duplicated per feature: `app/Concerns`, `app/Http/Controllers/Concerns`, `resources/js/components/ui`, `resources/js/components/`.
- **Add a Service/Action only when a resource's business logic genuinely doesn't fit in a controller method** (as already done for `EquipmentAccountabilityService`, `UserRoleService`) — not as a mandatory layer per resource.

**Revisit trigger** — reconsider `app/Domain/` folders only if either becomes true, not preemptively: (a) this system expands beyond a single division office into a genuine multi-team/multi-tenant platform, or (b) a single resource's flat-directory file count grows past what's easily scannable (rule of thumb: more than ~6-8 files backing one feature in a single flat directory). Until then, flat stays.

## Reference/lookup data: tiered tables, not one global `lookups` table

**Decision (architect, 2026-07-11), full detail in [`docs/architecture-decisions/lookup-normalization.md`](docs/architecture-decisions/lookup-normalization.md):** the original single generic `lookups` table (35 types discriminated by `App\Enums\LookupType`, every consuming column storing a plain validated string) is superseded. It's replaced by 13 real tables in two tiers:

- **Tier 1 — 9 dedicated tables with real foreign keys** (`item_types`, `brands`, `equipment_categories`, `equipment_classifications`, `equipment_conditions`, `positions`, `ro_offices`, `sdo_offices`, `isp_providers`) for the lookup concepts that are large, cross-table-reused, or actively filtered/reported on. Consuming columns become genuine `_id` foreign keys with `belongsTo` relationships — e.g. `equipment.item_type_id → item_types.id`, not `equipment.item` as a raw string.
- **Tier 2 — 4 domain-grouped "library" tables** (`equipment_libraries`, `personnel_libraries`, `stakeholder_libraries`, `connectivity_libraries`), each still discriminated by a `type` column but scoped to one bounded context instead of the whole app, for the remaining 26 small closed-vocabulary lookup types. Consuming columns stay validated strings (`Rule::in()`), same pattern as before, just no longer sharing one global table across unrelated domains.

`LookupType` is deleted, replaced by four small per-table enums (`EquipmentLibraryType`, `PersonnelLibraryType`, `StakeholderLibraryType`, `ConnectivityLibraryType`) for Tier 2; Tier 1 needs no discriminator enum at all. JSON-array multi-select columns (e.g. `StakeholderProfile.nearby_institutions`, `InternetConnectivitySurvey.available_isps`) stay JSON arrays — they switch from arrays of value-strings to arrays of the referenced table's row ids, validated per-element; no pivot tables are introduced (see the ADR for why). Read the ADR before touching any lookup-backed column, model, migration, or Vue `<Select>` — it has the full table-by-table column mapping and the Database → Backend → Frontend → QA rollout sequence, including which resource goes first and what's at risk if the cutover is rushed.

## Global standards (apply regardless of which agent is active)

- SOLID, DRY, KISS, YAGNI. No speculative abstractions, no unnecessary Service/Action layers for simple CRUD.
- Controllers thin; business logic in Services/Actions only when genuinely complex.
- Form Requests for all validation; Policies/Gates for all authorization.
- Strict TypeScript, Composition API, semantic HTML, WCAG 2.2 AA.
- White/Blue, Poppins, generous-spacing government-enterprise visual language — professional, minimal, fast, no clutter.
- Conventional-commit prefixes (`feat:`, `fix:`, `perf:`, etc.) for commit messages.
- Soft deletes, indexes, and foreign keys used deliberately — not by default on every table.

## Definition of done

A feature isn't done until: `qa` has signed off, `security` has signed off (when applicable), and `reviewer` has given final approval. `documentation` should be updated whenever the feature changes architecture, an Inertia prop contract, or the setup/deploy process.
