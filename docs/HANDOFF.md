# Handoff — work in progress

Last updated: 2026-10-28, branch `iris-staging` at `9df13bf`.

## Done and pushed

| Commit | What |
|---|---|
| `65a274b` | `PRIME_ENABLED` switch (`false` = skip PRIME, only `LOCAL_LOGIN_EMAILS` can log in); CSP allows Cloudflare Web Analytics |
| `22e2496` | Dashboard rebuilt (PPE / semi-expendable, ICT equipment overview + distribution, internet connectivity), one `dashboard.*` permission per section; equipment list filters `dashboard_group` and `item_type` |
| `d76e133` | Desktop bundles: monitor/keyboard/mouse as linked child equipment (`<desktop property no>-NNN`), accountability moves with the desktop, refused for peripherals outside the user's access |
| `9df13bf` | Equipment record page in tabs (Overview, Item, Acquisition & accounting, Condition & warranty, Peripherals, History), all fields kept; category / warranty / UoM / allotment / GL-SL / UACS / useful life default only when blank; classification defaults + item-type dashboard group in Reference Data |

New migrations: `2026_10_28_000001` … `000005` — run `php artisan migrate`.

## Not done — do these next, in order

1. **Run the full test suite** — it was not run after the last changes:
   `cd backend && php artisan test` and `cd frontend && npm run build`. Fix anything red.
2. **Finish security finding L-2** (`auth` agent was stopped mid-way). Goal: when `PRIME_ENABLED=false`, log the reason as `prime_disabled` (not `prime_unreachable`) in `AuthController::verifyCredentials` and `Rules/CurrentPassword`; throttled `security`-channel warning from `PrimeUserSync` failure paths; throttled `security` ERROR at boot in production. `tests/Feature/Auth/PrimeDisabledTest.php` may be incomplete — check it.
3. **QA pass** (`qa` agent), then **security re-check** of M-1/L-1 fixes, then **reviewer**.
4. **Docs:** dashboard and peripheral endpoint contracts; update `README.md:131` (still says not to loosen the CSP for Cloudflare — owner chose to allow the analytics beacon).

Open questions for the owner:
- Desktop tile shows totals for all monitors/keyboards/mice (bundled or not). Alternatives: count only linked peripherals, or show both.
- Hidden conditional values (donor, DCP fields) are kept when not applicable — should the server clear them?
- Changing a record's classification doesn't refresh its saved GL/SL / UACS / useful life.

## Local setup notes

- `php artisan migrate` on an older DB also runs the 2026-10-27 PRIME migrations, which **clear local passwords** for accounts not in `LOCAL_LOGIN_EMAILS`. To log in without PRIME: set `LOCAL_LOGIN_EMAILS=<your email>` and `PRIME_ENABLED=false` in `backend/.env`, then `php artisan users:set-password <your email>`.
- If another Laravel app already uses port 8000, run this one on 8001:
  `APP_URL=http://localhost:8001 php artisan serve --port=8001` and
  `VITE_API_URL=http://localhost:8001 npm run dev` (keep the frontend on 5173 — it must match `FRONTEND_URL` / `SANCTUM_STATEFUL_DOMAINS`).
- If a page shows "does not provide an export named …" after files changed, hard-reload (Ctrl+Shift+R) — stale Vite module.
