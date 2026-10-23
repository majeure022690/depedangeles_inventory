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

`cd frontend && npm run build` produces `frontend/dist/`. Vite bakes `VITE_*` values in at build time, so fill in `frontend/.env.production` (from `.env.production.example`) first. Backend production values are templated in `backend/.env.production.example`.

**Production runs on Plesk.** Plesk's nginx sits in front of Apache, with no Docker. The site is single-origin: the SPA and the API are served from the same host.
- **Setup.** Set the domain's document root to `backend/public`. Create `backend/.env` (from `backend/.env.production.example`) and `frontend/.env.production` on the server. Then set the Git repository's additional deployment actions to `sh deploy/plesk.sh`.
- **What the deploy script does.** It installs Composer dependencies, migrates, builds the SPA and copies `dist/` into `backend/public`. It fails if `backend/public/index.html` is missing afterwards, because SPA paths would otherwise fall through to Laravel's QR redirect route and loop.
- **Routing.** In `backend/public`, `.htaccess` sends `/api`, `/sanctum` and `/up` to Laravel, and every other GET (including `/equipment/{id}/edit` from printed QR labels) to `index.html`, with the security headers/CSP.
- **Origin and cookies.** `APP_URL`, `FRONTEND_URL` and `VITE_API_URL` are all that one origin, and `SESSION_DOMAIN` stays empty (host-only cookies).
- **First deploy.** Run `php artisan key:generate` once if `APP_KEY` is empty.
- **Once, after the 2026-10-23 deploy: check stored QR URLs.** Run `php artisan equipment:regenerate-qr-codes` (a dry run listing rows whose `qr_code` doesn't point at `APP_URL`, old -> new). Review the list: a row pointing at a foreign host may be a phishing label and must be physically reprinted. Then run it again with `--force` to rewrite them (each rewrite is audited). Details: [`docs/features/equipment.md`](docs/features/equipment.md#non-obvious-design-decisions).

#### Web server rate limiting

Cloudflare's rate-limiting rules limit each client IP before requests reach the origin. They are a backstop for traffic Laravel doesn't throttle: unauthenticated 401s, `permission:` denials, and `/sanctum/csrf-cookie`.

Don't rate-limit per IP in Plesk's nginx. Behind Cloudflare, nginx's `$remote_addr` is a Cloudflare edge address, so per-IP zones would put many unrelated users in one bucket and throttle them together. The earlier `deploy/nginx/` config was removed for this reason.

**The rules.** Create them under Security > WAF > Rate limiting rules. Both count requests per IP, use the Block action and a 10-second mitigation timeout:

| Rule | Expression | Rate |
|---|---|---|
| `dictis-api` | `starts_with(http.request.uri.path, "/api/") or starts_with(http.request.uri.path, "/sanctum/") or http.request.uri.path eq "/up"` | 300 requests / 10 s (about 30 req/s) |
| `dictis-auth` | `http.request.uri.path in {"/api/login" "/api/forgot-password" "/api/reset-password" "/sanctum/csrf-cookie"} or starts_with(http.request.uri.path, "/api/2fa/")` | 180 requests / 1 min (30 / 10 s where only 10 s is offered) |

Both are looser than Laravel's own limiters, so the app's limits still decide normal lockouts.

**Response.** Where the plan allows a custom response, set status `429`, content type `application/json` and body `{"message":"Too Many Attempts."}`. Otherwise clients get Cloudflare's default 429 page. The SPA keys on the status code, not the body, so it still shows its "Too many attempts" message. Laravel's own 429s, with their exact `Retry-After`, pass through Cloudflare unchanged.

**Plan requirements.** Rate limiting rules are a Cloudflare WAF feature. The Free plan has limited rate limiting: a single rule, counting by IP only, a fixed 10-second period and mitigation timeout, and no custom response. Pro and Business allow more rules, longer periods and a custom response. Check the current table at <https://developers.cloudflare.com/waf/rate-limiting-rules/#availability>. On Free, create only `dictis-api`, because it covers the traffic Laravel doesn't throttle, and Laravel already throttles the auth endpoints.

**Origin bypass.** The rules only see traffic that goes through Cloudflare. A client that finds the origin's IP skips them; Laravel's limiters still apply to it, keyed on its real IP. To close this gap, allow ports 80/443 only from Cloudflare's ranges at the server firewall, or enable Authenticated Origin Pulls.

- **Required: check the client IP Laravel sees.** Do this after the first deploy, and whenever the proxy chain changes. If Laravel sees a Cloudflare or server address instead of the client's, every IP-keyed limiter (login, password reset, 2FA) shares one bucket. One person's failed logins then lock everyone out.
  - From a known external IP, sign in with a wrong password, or sign in and out.
  - Then check `ip` in the newest entry of `backend/storage/logs/security-YYYY-MM-DD.log` (today's date, because the channel rotates daily). It must be your external IP.
  - If it shows a Cloudflare address, `TRUSTED_PROXIES` is missing that range or the config cache is stale. Compare it with <https://www.cloudflare.com/ips/>, update `backend/.env` (see `backend/.env.production.example`), then run `php artisan optimize`.
  - If it shows the server's own address, Plesk's nginx-to-Apache hop isn't restoring the client address. Add that exact address to `TRUSTED_PROXIES` only on a single-tenant server, because every local process connecting from it (including other tenants on shared hosting) can then set `X-Forwarded-For`.
- **Tune.** Watch Security > Events for rule hits. If a legitimate shared office IP is being limited, raise that rule's rate, or exclude that single public address with `and not ip.src eq <address>`.
- **Verify.** Run this from an IP that isn't your office's shared NAT, because it briefly throttles everyone behind that IP. The first command shows `200`s followed by `429`s. The last two must return `404`, because Apache would otherwise accept `/index.php/...` path info (including percent-encoded spellings of it), which the path-based rules don't match:

  ```bash
  seq 1 400 | xargs -P 10 -I{} curl -s -o /dev/null -w "%{http_code}\n" https://dictis.example.gov.ph/sanctum/csrf-cookie | sort | uniq -c
  curl -si https://dictis.example.gov.ph/sanctum/csrf-cookie | grep -iE '^HTTP|^retry-after|message'
  curl -s -o /dev/null -w "%{http_code}\n" -X POST https://dictis.example.gov.ph/index.php/api/login
  curl -s -o /dev/null -w "%{http_code}\n" --path-as-is https://dictis.example.gov.ph/index.ph%70/api/login
  ```

#### Cloudflare and the CSP

The browser console may show the CSP blocking an inline script and `static.cloudflareinsights.com`. Both are injected by Cloudflare: Web Analytics (automatic setup) adds the beacon, and Bot Fight Mode / JavaScript detections add the inline script. Turn those features off in the Cloudflare dashboard rather than loosening the CSP. The inline script changes on every request, so allowing it would need `'unsafe-inline'`.

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
