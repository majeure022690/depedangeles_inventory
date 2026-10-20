# DepEd Division ICT Inventory System

A Laravel API + React SPA for a DepEd (Department of Education) **Division Office** to track ICT equipment, personnel, ISP/connectivity accounts, and internet-connectivity survey data in one place — replacing the spreadsheet workbook the office previously maintained by hand.

Built for the ICT unit of a single division office: encoders enter and maintain records day-to-day, viewers get read-only access for reporting/auditing, and an Administrator manages user roles and full CRUD across every module.

## What it does

- **Personnel** — the division's staff directory (names, positions, contact info, employment status), used as the source of "who" for equipment accountability.
- **Equipment** — the ICT asset register (property number, specs, acquisition/funding info, condition/disposition) with a full accountability-transfer history: every time a device changes hands, that event is logged, not overwritten.
- **ISP Accounts** — the office's internet service subscriptions (provider, plan, cost, contract dates), each with its own append-only speed-test log and subscription-cost history.
- **Offices** — every school and division unit; the backbone the office-scoped records below hang off.
- **Stakeholder Profile** — one record per office (location, contact persons, community context), filled in by that office's users; admins see every office.
- **Internet Connectivity Survey** — one record per office capturing its connectivity situation (available ISPs, signal quality, electricity source, coverage), scoped the same way.
- **Roles & permissions** — granular, permission-string-based access control (never role-name checks), with admin-configurable roles (`/roles`) and multi-role user accounts (`/users`), separated into two permission tiers (`roles.manage` vs. `users.manage`) so assigning an existing role is a lower-stakes act than redefining what a role grants.
- **Reference data** — admin screen (`/reference-data`) for the 13 tiered reference-data tables (item types, brands, positions, ISP providers, and more) every other module's dropdowns draw from.
- **Audit log** — an append-only record of every create/update/delete, role/role-assignment change, and denied-permission attempt, with actor identity preserved even if the acting account is later deleted.

## Tech stack

Two apps in one repo, mirroring the sibling project rwatch (see [`docs/architecture-decisions/decoupled-spa-stack.md`](docs/architecture-decisions/decoupled-spa-stack.md)):

- **`backend/`** — Laravel 13 / PHP 8.4 JSON API under `/api`, Sanctum SPA cookie auth, spatie/laravel-permission (granular permissions, never role-name checks), spatie/laravel-activitylog, Google2FA.
- **`frontend/`** — React 19 + TypeScript SPA (Vite, Tailwind CSS, React Router), served at the site root (`/login`, `/dashboard`, …).
- **Database:** MySQL via XAMPP in local dev. Tests use in-memory SQLite.

## Local setup

### Prerequisites

- [XAMPP](https://www.apachefriends.org/) (or any MySQL/MariaDB server). Start MySQL and create an empty `inventory` database (phpMyAdmin, or `mysql -u root -e "CREATE DATABASE inventory"`).
- PHP 8.4+ and [Composer](https://getcomposer.org/)
- Node.js 20.19+ and npm

### First-time setup

```bash
cd backend
composer install
cp .env.example .env          # already points at MySQL: inventory / root / no password
php artisan key:generate
php artisan migrate --seed
php artisan storage:link      # serves uploaded profile photos from public/storage

cd ../frontend
cp .env.example .env
npm install
```

`DatabaseSeeder` seeds roles/permissions, reference data, and an admin account. Seeding is idempotent, so re-running `php artisan db:seed` is safe; use `php artisan migrate:fresh --seed` for a clean database.

**Seeded admin login:**

```
Email:    keithvpolintan@gmail.com
Password: D3pEd-Angeles!2026
```

### Daily development

Run the two servers in separate terminals:

```bash
cd backend && php artisan serve    # API on http://localhost:8000
cd frontend && npm run dev         # SPA on http://localhost:5173
```

Then browse **http://localhost:5173/**.

Gotchas:

- **Use `localhost`, not `127.0.0.1`.** Sanctum's session and XSRF cookies are tied to `localhost`; on `127.0.0.1` they are not sent and every request is unauthenticated. `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS` and `SESSION_DOMAIN` in `backend/.env` must agree with the URL you browse.
- **First login forces 2FA enrollment.** Have an authenticator app ready.
- **After editing roles/permissions directly in the database**, run `php artisan permission:cache-reset`.
- `php artisan dev` / `pail` need the `pcntl` extension, which Windows PHP lacks; run the two servers separately as above.
- reCAPTCHA uses Google's test keys locally. Production needs real keys in both `backend/.env` (`RECAPTCHA_SECRET_KEY`) and `frontend/.env.production` (`VITE_RECAPTCHA_SITE_KEY`); the test secret is rejected when `APP_ENV=production`.

### Production build

`cd frontend && npm run build` produces `frontend/dist/`. Vite bakes `VITE_*` values in at build time, so fill in `frontend/.env.production` (from `.env.production.example`) first. Backend production values are templated in `backend/.env.production.example`. Production is single-origin: `frontend/Dockerfile` builds an nginx image (`frontend/docker/default.conf.template`) that serves `dist/` with the security headers/CSP and proxies `/api`, `/sanctum` and `/storage` to the backend; everything else, including `/equipment/{id}/edit` from printed QR labels, falls through to the SPA. `APP_URL`, `FRONTEND_URL` and `VITE_API_URL` are all that one origin, and `SESSION_DOMAIN` stays empty (host-only cookies). Set the backend's `TRUSTED_PROXIES` to the nginx container (plus any TLS load balancer in front), never `*` — otherwise clients can spoof `X-Forwarded-For` and bypass the IP-keyed rate limiters.

**Plesk (Apache, no Docker):** set the domain's document root to `backend/public`, create `backend/.env` (from `backend/.env.production.example`) and `frontend/.env.production` on the server, then set the Git repository's additional deployment actions to `sh deploy/plesk.sh`. The script installs Composer dependencies, migrates, builds the SPA and copies `dist/` into `backend/public`, where `.htaccess` sends `/api`, `/sanctum` and `/up` to Laravel and every other GET to `index.html`, with the same security headers as the nginx config. Run `php artisan key:generate` once if `APP_KEY` is empty.

On any network-reachable deploy, `APP_ENV` must be exactly `production` and `APP_DEBUG=false`: JSON error masking and reCAPTCHA's fail-closed rejection of the test/blank secret both key on `APP_ENV=production`, so any other value (`prod`, `staging`, ...) leaks exception detail and accepts the test secret.

## Running tests

```bash
cd backend
php artisan test --compact     # in-memory SQLite, no MySQL needed
composer test                  # Pint check + PHPStan + tests
composer lint                  # Pint, auto-fix

cd ../frontend
npm run build                  # TypeScript check + production build
```

## Documentation

- [`docs/architecture.md`](docs/architecture.md) — architecture, authorization model, audit logging, and data-access patterns.
- [`docs/architecture-decisions/`](docs/architecture-decisions) — ADRs, including the decoupled SPA stack and lookup normalization.
- [`docs/features/`](docs/features) — one doc per module.
- [`CHANGELOG.md`](CHANGELOG.md) — build history.
- [`CLAUDE.md`](CLAUDE.md) — agent-routing and project standards.
