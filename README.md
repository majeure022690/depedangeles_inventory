# DepEd Division ICT Inventory System

A server-driven Laravel + Inertia + Vue application for a DepEd (Department of Education) **Division Office** to track ICT equipment, personnel, ISP/connectivity accounts, and internet-connectivity survey data in one place — replacing the spreadsheet workbook the office previously maintained by hand.

Built for the ICT unit of a single division office: encoders enter and maintain records day-to-day, viewers get read-only access for reporting/auditing, and an Administrator manages user roles and full CRUD across every module.

## What it does

- **Personnel** — the division's staff directory (names, positions, contact info, employment status), used as the source of "who" for equipment accountability.
- **Equipment** — the ICT asset register (property number, specs, acquisition/funding info, condition/disposition) with a full accountability-transfer history: every time a device changes hands, that event is logged, not overwritten.
- **ISP Accounts** — the office's internet service subscriptions (provider, plan, cost, contract dates), each with its own append-only speed-test log and subscription-cost history.
- **Stakeholder Profile** — a single record describing the division office itself (location, contact persons, community context) — one form, no list.
- **Internet Connectivity Survey** — a single record capturing the office's connectivity situation (available ISPs, signal quality, electricity source, coverage), plus a live-computed summary panel (total ISPs, total monthly cost, total spend, etc.) sourced from ISP Accounts — nothing in that panel is stored, it's always computed fresh.
- **Roles & permissions** — granular, permission-string-based access control (never role-name checks), with admin-configurable roles (`/roles`) and multi-role user assignment (`/users`), separated into two permission tiers (`roles.manage` vs. `users.manage`) so assigning an existing role is a lower-stakes act than redefining what a role grants.
- **Reference data** — admin screen (`/reference-data`) for the 13 tiered reference-data tables (item types, brands, positions, ISP providers, and more) every other module's dropdowns draw from.
- **Audit log** — an append-only record of every create/update/delete, role/role-assignment change, and denied-permission attempt, with actor identity preserved even if the acting account is later deleted.

## Tech stack

Built on the official [Laravel Vue Starter Kit](https://github.com/laravel/vue-starter-kit) — extended, not forked or replaced.

- **Backend:** Laravel 13, PHP 8.4
- **Frontend rendering:** Inertia.js v3 (`inertiajs/inertia-laravel` + `@inertiajs/vue3`) — this is a server-driven monolith. Pages are returned via `Inertia::render()` from `routes/web.php`; there is no separate REST API.
- **Frontend:** Vue 3 + Composition API + `<script setup>`, strict TypeScript
- **Auth:** Laravel Fortify (session-based), extended with a hand-rolled roles/permissions layer (not spatie/laravel-permission)
- **Styling:** Tailwind CSS + shadcn-vue (reka-ui), White/Blue Poppins government-enterprise visual language, dark/light/system theme toggle
- **Routing helpers:** Laravel Wayfinder (typed route/controller helpers for the frontend)
- **Build:** Vite
- **Database:** MySQL (via XAMPP in local dev — see below; Laravel's default sqlite is not what this project actually runs on)

## Local setup

### Prerequisites

- [XAMPP](https://www.apachefriends.org/) (or a standalone MySQL/MariaDB server) — **this project uses MySQL, not the Laravel default SQLite.** Start Apache and MySQL from the XAMPP control panel before proceeding.
- PHP 8.4+ with the extensions Laravel 13 requires (bundled with XAMPP)
- [Composer](https://getcomposer.org/)
- Node.js 18+ and npm

### 1. Install dependencies

```bash
composer install
npm install
```

### 2. Configure the environment

Copy the example env file and generate an app key:

```bash
cp .env.example .env
php artisan key:generate
```

**Important:** `.env.example` ships with Laravel's stock `DB_CONNECTION=sqlite` default. This project actually runs on **MySQL via XAMPP**. After copying, edit `.env` and set:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=inventory
DB_USERNAME=root
DB_PASSWORD=
```

Then create the `inventory` database in MySQL (e.g. via phpMyAdmin at `http://localhost/phpmyadmin`, or `mysql -u root -e "CREATE DATABASE inventory"`).

### 3. Migrate and seed

Seeding must run in this order — `DatabaseSeeder` already does this for you, so a plain `db:seed` is sufficient:

```bash
php artisan migrate
php artisan db:seed
```

This runs, in order (see `database/seeders/DatabaseSeeder.php`):

1. **`RolePermissionSeeder`** — seeds the `permissions` table from `App\Enums\Permission` (20 cases) and composes the four starting roles (`pending`, `division-ict-admin`, `encoder`, `viewer`). Must run before any user is assigned a role. Roles are admin-configurable from here on (see `/roles`, below) — this seeder just establishes the starting set.
2. Creates the seeded test user and assigns it the `division-ict-admin` role.
3. **`ReferenceDataSeeder`** — seeds the 13 Tier 1/Tier 2 reference-data tables (item types, brands, equipment categories/classifications/conditions, positions, RO/SDO offices, ISP providers, plus 4 domain-grouped "library" tables) from `database/seeders/data/*.json` — the 677 real reference-data values originally sourced from the Excel workbook, split one JSON file per table. This replaced an earlier single `lookups` table/`LookupSeeder`, since removed — see [`docs/architecture-decisions/lookup-normalization.md`](docs/architecture-decisions/lookup-normalization.md).

Both `RolePermissionSeeder` and `ReferenceDataSeeder` are idempotent — re-running `php artisan db:seed` is always safe (`RolePermissionSeeder` upserts; `ReferenceDataSeeder` uses `insertOrIgnore`, so it never reverts an admin edit made through `/reference-data` back to seed data).

**Seeded login:**

```
Email:    test@example.com
Password: password
```

This account holds the `division-ict-admin` role (full permissions, including user/role management at `/users`).

If you need a completely fresh database instead of migrating onto an existing one:

```bash
php artisan migrate:fresh --seed
```

### 4. Run the dev server

```bash
composer run dev
```

This runs Laravel 13's built-in `php artisan dev` command, which starts the PHP dev server, the queue listener, `pail` (log tailing), and the Vite dev server concurrently. Visit `http://localhost:8000` (or whatever `APP_URL`/port `artisan serve` reports).

Alternatively, run pieces separately:

```bash
php artisan serve   # PHP server
npm run dev          # Vite dev server only
```

**Gotcha — `public/hot`:** when Vite's dev server (`npm run dev` / `artisan dev`) is running, Laravel writes a `public/hot` file so the backend knows to load assets from the Vite dev server instead of the compiled `public/build` assets. If that dev server is ever killed uncleanly (e.g. terminal closed, process killed rather than `Ctrl+C`'d), `public/hot` can be left behind, and the app will try to fetch assets from a Vite server that's no longer running — pages fail to load JS/CSS. If you see this, delete the stale file and rebuild:

```bash
rm public/hot
npm run build
```

As of this writing `public/hot` is not present in this checkout (a production `public/build` exists instead), so this is not a live problem — just a known failure mode to check for if pages suddenly stop rendering after a Vite dev session.

### 5. Build for production

```bash
npm run build
```

## Running tests

Tests run against an in-memory SQLite database (configured in `phpunit.xml`), independent of the MySQL connection used for local development — no MySQL setup is required to run the test suite.

```bash
php artisan test
```

Or the full CI check (lint, format check, TypeScript type check, PHPStan via `types:check`, then tests):

```bash
composer run ci:check
```

Other useful scripts:

```bash
composer run lint        # Pint, auto-fix
composer run lint:check  # Pint, check only
npm run lint             # ESLint, auto-fix
npm run format            # Prettier, auto-fix
npm run types:check       # vue-tsc --noEmit
```

## Documentation

- [`docs/architecture.md`](docs/architecture.md) — agent collaboration model, project structure decision, authorization model, audit logging, and the three data-access patterns used across features (accountability-transfer, append-only child log, singleton).
- [`docs/features/`](docs/features) — one doc per module: purpose, Inertia routes/prop contracts, key files, design decisions, and open follow-ups.
- [`CHANGELOG.md`](CHANGELOG.md) — build history reconstructed from migration sequence.
- [`CLAUDE.md`](CLAUDE.md) — the project's own agent-routing and standards reference (source of truth for the 11-agent workflow and the flat-structure decision).
