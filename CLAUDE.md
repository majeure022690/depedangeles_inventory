# Inventory — DepEd Division ICT Inventory System (DICTIS)

## Stack

**Decision (owner, 2026-10-19), full detail in [`docs/architecture-decisions/decoupled-spa-stack.md`](docs/architecture-decisions/decoupled-spa-stack.md):** this app mirrors the sibling project **rwatch** (`C:\xampp\htdocs\rwatch`, read-only reference) — same stack, folder layout, UI/UX, sidebar, components, and security model. It supersedes the earlier Inertia/Vue monolith. Conversion steps: [`decoupled-spa-stack-plan.md`](docs/architecture-decisions/decoupled-spa-stack-plan.md).

- **`backend/`** — Laravel 13, PHP 8.4, **JSON API only**. Sanctum SPA cookie auth (`statefulApi()`, `/sanctum/csrf-cookie`, CORS limited to `FRONTEND_URL`). Spatie laravel-permission (authorization) and spatie laravel-activitylog (audit). Mandatory TOTP 2FA (pragmarx/google2fa) with trusted devices and recovery codes, reCAPTCHA v3 on login, named rate limiters, `security` log channel, `SecurityHeaders` middleware, JSON-only exceptions.
- **`frontend/`** — React 19 + TypeScript + Vite + React Router 7 + axios + Tailwind 4 + Radix + TanStack Table + lucide-react. Staff portal under `/portal/*`; no public portal.
- Dev: `cd backend && php artisan serve` (:8000) and `cd frontend && npm run dev` (:5173). Browse via `localhost`, not `127.0.0.1`.

Not used (removed): Inertia, Fortify, Wayfinder, laravel/passkeys, Vue/shadcn-vue, self-registration, the custom `roles`/`permissions` pivot layer, the custom `audit_logs` table.

## Agent roster

This project uses a fixed set of specialist agents (`.claude/agents/*.md`, project-level so they travel with the repo), each owning a distinct slice of the stack. Use the `Agent` tool with the matching `subagent_type` rather than doing cross-domain work yourself in the main thread.

| Agent | Owns |
|---|---|
| `architect` | Structure, module boundaries, cross-cutting technical decisions. Final say when agents disagree. |
| `backend` | `backend/app/Http/Controllers/Api/Management`, Form Requests, API Resources, Eloquent models, Services, `backend/routes/api/management.php`. |
| `frontend` | `frontend/src/**` — pages, components, layouts, hooks, services, types; Tailwind/Radix UI, accessibility. |
| `database` | Migrations, seeders, factories, indexes, schema, query performance. |
| `auth` | Sanctum, login/2FA/trusted devices/password flows (`Api/Auth`, `routes/api/auth.php`), Spatie roles/permissions, Policies, rate limiters, security middleware. |
| `integration` | External/government APIs, file import/export, webhooks, queue-driven background processing, any third-party-facing API surface if one is ever genuinely needed. |
| `qa` | Functional/edge-case/accessibility/regression validation before sign-off. |
| `devops` | Env config, CORS/Sanctum/session config, Vite/proxy config, CI/CD, caching, queues, storage, logging, Husky hooks, production readiness. |
| `security` | OWASP review of anything touching auth, input, uploads, or external data. Gate before merge. |
| `documentation` | Feature docs, architecture docs, setup/deploy guides, changelog. |
| `reviewer` | Final quality gate — reviews the combined output of all other agents before anything is "done". |

Each agent's own file is the source of truth for its scope, decision rules, and completion checklist — don't duplicate that detail here.

## How to route work

1. **Identify the dominant domain** of the request (schema change → `database`, UI work → `frontend`, external system → `integration`, access control → `auth`, etc.) and delegate to that agent first.
2. **Multi-domain features** get broken up and delegated in dependency order, typically: `architect` (if structure is non-obvious) → `database` → `backend` → `auth` (if new permissions are needed) → `frontend` → `qa` → `security` → `reviewer`. Skip agents whose domain isn't touched.
3. **Small, single-domain, low-risk changes** can be done directly without spinning up an agent — don't over-delegate trivial work.
4. **Never let an agent touch another's owned files.** If `backend` needs a migration, it asks `database`. If `frontend` needs new data, it asks `backend` for an endpoint/field rather than deriving it client-side.
5. **Security and QA sign-off are required, not optional**, for anything touching auth, validation, file uploads, or external data before `reviewer` gives final approval.
6. **Granular permissions, not role-name checks** — hard rule: `$user->can('equipment.update')` / `$user->hasPermissionTo(Permission::X)`, never `if ($user->role === 'admin')`, never Spatie's `role:` middleware or `hasRole()` in authorization logic. This overrides rwatch where rwatch differs.
7. **The API exists for the first-party SPA only.** Reject token-issuing, public/unauthenticated, or third-party-oriented endpoints unless `integration` has identified a genuine external consumer.

## Project structure

**Backend mirrors rwatch's audience sub-namespacing; everything else stays flat** (not `app/Domain/<Feature>/`):

- `routes/api.php` requires `routes/api/auth.php` and `routes/api/management.php` (no `public.php` — no public portal).
- `app/Http/Controllers/Api/{Auth,Management}/`, `app/Http/Requests/{Auth,Management}/`, `app/Http/Resources/Management/`.
- Flat: `app/Models/`, `app/Policies/`, `app/Services/`, `app/Enums/`, `app/Rules/`, `app/Http/Middleware/`.
- Naming keeps a flat directory navigable: one Controller + Policy + `Store<X>Request`/`Update<X>Request` + `<X>Resource` (+ optional Service) per resource, named identically to that resource. Add a Service only when business logic genuinely doesn't fit a controller method (`EquipmentAccountabilityService`, `RoleService`, `UserRoleService`, rwatch's auth services).
- `Auth`/`Management` is grouping by API audience (as in rwatch), not by business feature — the original flat-vs-`Domain/` reasoning (2026-07-10: ~8 models, one bounded context, a single division office) still holds. **Revisit trigger** for `app/Domain/`: (a) genuine multi-team/multi-tenant expansion, or (b) more than ~6-8 files backing one feature in a single flat directory.

**Frontend mirrors `rwatch/frontend/src`:** `components/ui`, `components/<feature>`, `components/management`, `context/AuthContext.tsx`, `hooks/`, `layouts/ManagementLayout.tsx`, `lib/api.ts`, `router/Guards.tsx`, `services/<feature>Service.ts`, `types/`, `pages/management/<feature>/`.

## Reference/lookup data: tiered tables, not one global `lookups` table

**Decision (architect, 2026-07-11), full detail in [`docs/architecture-decisions/lookup-normalization.md`](docs/architecture-decisions/lookup-normalization.md)** — unchanged by the SPA conversion. 13 real tables in two tiers:

- **Tier 1 — 9 dedicated tables with real foreign keys** (`item_types`, `brands`, `equipment_categories`, `equipment_classifications`, `equipment_conditions`, `positions`, `ro_offices`, `sdo_offices`, `isp_providers`). Consuming columns are `_id` FKs with `belongsTo` relationships.
- **Tier 2 — 4 domain-grouped "library" tables** (`equipment_libraries`, `personnel_libraries`, `stakeholder_libraries`, `connectivity_libraries`), discriminated by a `type` column (enums `EquipmentLibraryType`, `PersonnelLibraryType`, `StakeholderLibraryType`, `ConnectivityLibraryType`). Consuming columns stay validated strings (`Rule::in()`).

JSON-array multi-select columns stay JSON arrays of referenced row ids, validated per-element; no pivot tables. Forms get their options from per-resource `GET /api/<resource>/options` endpoints. Never resurrect a single generic `lookups` table. Read the ADR before touching any lookup-backed column, model, migration, or form select.

## Global standards (apply regardless of which agent is active)

- SOLID, DRY, KISS, YAGNI. No speculative abstractions, no unnecessary Service/Action layers for simple CRUD.
- Controllers thin; Form Requests for all validation; Policies for all authorization (route `permission:` middleware is only a fast-fail); API Resources (explicit field allowlists) for every response.
- Every mutation is audited via spatie/activitylog (`LogsActivity` on models, `activity()` for non-CRUD events); the audit trail is never pruned.
- Strict TypeScript, React function components + hooks, semantic HTML, WCAG 2.2 AA.
- White/Blue, Poppins, generous-spacing government-enterprise visual language (rwatch's theme) — professional, minimal, fast, no clutter.
- Conventional-commit prefixes (`feat:`, `fix:`, `perf:`, etc.) for commit messages.
- Soft deletes, indexes, and foreign keys used deliberately — not by default on every table.

## Definition of done

A feature isn't done until: `qa` has signed off, `security` has signed off (when applicable), and `reviewer` has given final approval. `documentation` should be updated whenever the feature changes architecture, an API endpoint contract, or the setup/deploy process.
