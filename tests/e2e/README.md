# End-to-end smoke test

`tests/e2e/smoke.mjs` drives a real browser (Playwright, Chromium) through the Option A slice against a
running `php artisan serve`:

1. **Member pays:** log in → Home shows the amount due → Review & pay → POST pay → redirect to the
   (faked) ToyyibPay bill page → an authenticated ToyyibPay callback is POSTed to `/webhook/toyyibpay`
   (a wrong token must get 403) → PayResult shows **Berjaya** → Home shows "Semua yuran telah dibayar".
2. **Admin A (maker):** dashboard → Fund Usage → new expense (RM 12.34) with a generated PNG and PDF →
   Submit for approval.
3. **Admin B (checker):** opens the record → Approve with the conflict-of-interest declaration →
   the dashboard "Spent this year" goes up by exactly RM 12.34.

Every step is screenshotted in light and dark (member 390×844, admin 1366×860). The run fails on any
console error, uncaught page error, failed request, CSP violation or horizontal overflow, and exits
non-zero. Ignored on purpose: Google Fonts and the Bootstrap CDN used by the legacy Blade login/home
pages (unreachable in some sandboxes), and the 409 Chrome logs for Inertia's external redirect to the
gateway (that is how `Inertia::location` works).

## Requirements

- PHP 8.3+, Composer dependencies installed, `npm ci && npm run build` done (the test uses the
  production build in `public/build`, not the Vite dev server; delete `public/hot` if present).
- Node 18+ with the `playwright` package resolvable (`require('playwright')`) and its Chromium installed.
  It is **not** a project dependency, so `npm ci` never downloads browsers. Install once with
  `npm i -g playwright && npx playwright install chromium`, or point `NODE_PATH` at an existing install.
- `storage/app/.installed` must exist (the install wizard creates it; `touch` it on a fresh checkout).

## Run it

```bash
export DB_CONNECTION=sqlite DB_DATABASE=/tmp/clubportal-e2e.sqlite
rm -f "$DB_DATABASE" && touch "$DB_DATABASE"
php artisan migrate:fresh --seed --force
php artisan db:seed --class='Database\Seeders\E2eSeeder' --force

# Terminal 1 — APP_ENV must be local (it is in .env) for the fake gateway to switch on.
E2E_FAKE_GATEWAY=1 \
E2E_PRIVATE_ROOT=/tmp/clubportal-e2e-private \
TOYYIBPAY_WEBHOOK_SECRET=e2e-webhook-secret-local-only \
php artisan serve --host=127.0.0.1 --port=8000 --no-reload

# Terminal 2
E2E_OUT_DIR=/tmp/clubportal-e2e-shots node tests/e2e/smoke.mjs
```

The member's fee is paid by the run, so **re-seed (the four database lines above) before every run**.

| Variable | Where | Default | Meaning |
|---|---|---|---|
| `E2E_FAKE_GATEWAY=1` | server | off | Answers ToyyibPay `createBill` with a fake bill code via `Http::fake` (see below). |
| `E2E_PRIVATE_ROOT` | server | `storage/app/private` | Where uploaded test documents go, so the repo stays clean. |
| `TOYYIBPAY_WEBHOOK_SECRET` | server | — | Must be set (and match `E2E_WEBHOOK_SECRET`), or every webhook is refused. |
| `E2E_BASE_URL` | script | `http://127.0.0.1:8000` | The running server. |
| `E2E_WEBHOOK_SECRET` | script | `e2e-webhook-secret-local-only` | Sent as `?webhook_token=`, exactly as `ToyyibPayGateway::verifyWebhookRequest` expects. |
| `E2E_OUT_DIR` | script | `<os tmpdir>/clubportal-e2e-shots` | Screenshots and the generated PNG/PDF fixtures. |
| `E2E_PASSWORD` | script | `E2eSeeder::PASSWORD` | Password of the three seeded users. |
| `E2E_IGNORE_HOSTS` | script | Google Fonts + jsDelivr | Comma-separated hosts whose network errors are ignored. |

`--no-reload` makes `artisan serve` pass the variables above straight to the PHP server process.

## Safety

- `Database\Seeders\E2eSeeder` (one club with obviously fake ToyyibPay credentials, a member with a
  pending RM 50.00 fee, admins A and B with a published password) throws unless `APP_ENV` is
  `local` or `testing`.
- `App\Providers\E2eFakeGatewayServiceProvider` does nothing unless `APP_ENV` is exactly `local`
  **and** the process has `E2E_FAKE_GATEWAY=1`; it also bails out when `isProduction()`.
  `tests/Feature/E2eSupportGuardTest.php` covers both guards.
- The webhook is never faked: the script calls the real `/webhook/toyyibpay` route with the configured
  secret, so verification, the paid-amount check and the audit row all run.
