# CLAUDE.md

Guidance for AI assistants working in this repository. It describes what the code does; when it disagrees with
the code, trust the code and fix this file.

## Project overview

**Club Portal**: a self-hosted Laravel app for small Malaysian staff clubs. Members pay fees by FPX (ToyyibPay or
Billplz, chosen per club) or by free bank / DuitNow transfer with a slip that an admin verifies. Admins record
spending ("Fund Usage": expenses and welfare cases) with documents and two-person approval. Also: installer, TOTP
2FA, roles, CSV member import, ledger with CSV/PDF export, audit log, installable PWA. Deployed on cPanel (PHP only,
prebuilt assets). User-facing docs: `README.md`, `docs/INSTALL.md` (first install), `docs/RELEASE.md` (build and
upgrade), `docs/USER-GUIDE.md`, `SECURITY.md`, `CREDITS.md` + generated `docs/THIRD-PARTY-LICENSES.md`.

## Stack

- **PHP 8.4** (`composer.json` and `composer.lock` need it), **Laravel 13**.
- SQLite by default (dev and tests); production target is MariaDB 10.11 on cPanel (`DB_CONNECTION=mariadb`). SQLite ignores VARCHAR lengths: run the suite on MariaDB too. `database` driver for session, cache (locks, rate limits) and queue; mail `log` locally.
- **Two front ends side by side:**
  - **Inertia 2 + React 18 + TypeScript + Tailwind 3** (Vite) for member pages and the admin Dashboard, Fund Usage and
    Verifications. Root view `resources/views/app.blade.php`, pages in `resources/js/Pages/{Member,Admin}`, shared
    components in `resources/js/components/{app,ui}`, BM/EN strings in `resources/js/lib/i18n.ts` (members default
    to `ms`), theme in `resources/js/lib/theme.tsx`. Props are shared by `HandleInertiaRequests`.
  - **Blade + Tailwind** (same tokens, `resources/css/app.css` `.auth-*`, layout `layouts/auth.blade.php`) for the
    sign-in pages: login (also served at `/`, the front page, with a "how to join: contact your admin" section),
    2FA challenge and password pages.
  - **Blade + Bootstrap 5 from jsDelivr** for the installer and the remaining admin pages
    (members, payments, fee rates, job levels, discounts, categories, billing settings, ledger, audit logs, clubs, admins,
    statistics, classic dashboard). Use Bootstrap classes there, Tailwind only in the React pages.
- `pragmarx/google2fa`, `barryvdh/laravel-dompdf`, Recharts (React), Chart.js (Blade, CDN).

## Layout

```
app/Contracts/                 PaymentGateway, ConfirmsWebhookPayments
app/Http/Controllers/Admin/    Club, ClubBilling, Member, FeeRate, JobLevel, Payment, FundUsage, Attachment,
                               TransferVerification, Dashboard, Ledger, Statistics, AuditLog, AdminUser, Profile, ...
app/Http/Controllers/Member/   Dashboard, Payment (review, pay, status, transfer, slip, invoice, generateFuture)
app/Http/Controllers/          InstallController, WebhookController, HomeController, Auth/*
app/Models/                    User, Club, Payment, PaymentBill, Expense, WelfareCase, Attachment, FeeRate,
                               Discount, ExpenseCategory, AuditLog, ClubJobLevel
app/Services/                  AuditService, AttachmentService, FundUsageService, TransferVerificationService,
                               BankDetailsChangeService, PaymentGateways/{PaymentGatewayManager,ToyyibPayGateway,BillplzGateway}
app/Support/                   presenters/stats (Admin, Member), Payments helpers (OwnFeeRule, GatewayFee,
                               WebhookSecret, BillplzSignature, ...), Export/CsvSafe
app/Providers/                 AppServiceProvider (rate limiters), E2eFakeGatewayServiceProvider (local + flag only)
routes/web.php                 all HTTP routes; routes/console.php schedules overdue reminders daily 08:00 in the app timezone (APP_TIMEZONE; Asia/Kuala_Lumpur recommended = 8 am Malaysia, UTC when unset; phpunit runs UTC)
database/seeders/              DatabaseSeeder (demo, local/testing only), E2eSeeder (e2e fixtures)
public/sw.js, manifest.webmanifest   PWA (bump CACHE_VERSION every release)
scripts/licenses.mjs           writes docs/THIRD-PARTY-LICENSES.md (npm run licenses)
mockup/                        standalone design mockup (sample data only; see mockup/README.md)
tests/Feature/{Admin,Member}, tests/Feature/*.php, tests/Unit, tests/e2e/smoke.mjs (Playwright)
```

## Routes and middleware

- `/install` (`redirect_if_installed`, POST throttled) needs `INSTALL_TOKEN` (or `storage/app/.install-token`), 16+ chars.
- `POST /webhook/toyyibpay` and `/webhook/billplz`: outside `check_installed`, no auth, `throttle:webhooks`, and
  `withoutMiddleware(PreventRequestForgery::class)`. Laravel 13's web group runs `PreventRequestForgery`; excluding
  `VerifyCsrfToken` does **not** work (tests skip CSRF, so only `WebhookCsrfExemptionTest` / e2e catch it).
- Everything else is inside `check_installed` (needs `storage/app/.installed`), then `['auth', 'auth.session', 'two_factor']`:
  - `member` group, prefix `/my`: member pages. `MemberOnly` only checks auth (admins can use it too).
  - `super_admin` group, prefix `/admin`: clubs, admins, statistics, platform audit log.
  - `club_admin` group, prefix `/admin`: shallow resources under `/admin/clubs/{club}/...`, Fund Usage, verifications,
    attachments, billing, ledger.
- `/two-factor-challenge` deliberately has **no `auth`**: login logs the user out and stores `two_factor_user_id`;
  the controller gates on that session key. Don't add `auth` there.
- Put literal child routes (e.g. `members/import`) **before** `Route::resource(...)->shallow()`.
- In production URLs are built from `APP_URL` and other hosts are refused (`trustHosts`).

## Domain rules (keep them)

- **Payment.status**: `pending | paid | overdue | pending_verification`. `transfer_*` / `verified_*` are not fillable;
  only `TransferVerificationService` sets them. `paid_via` is the gateway name, `transfer`, or null (manual).
- **Bills**: every gateway bill is a `PaymentBill`; `pay` is POST-only and runs under a per-fee cache lock. ToyyibPay
  callbacks carry a per-bill HMAC token and are confirmed with `getBillTransactions` before settling; Billplz uses
  X-Signature. Webhooks fail closed (secret < 32 chars or placeholder = refuse) and are idempotent.
- **Maker-checker**: Fund Usage approve (different admin, not the welfare beneficiary, CoI declaration), void
  (request + confirm by another admin), bank/DuitNow detail changes (`BankDetailsChangeService`), and `OwnFeeRule`
  (a club admin can't settle, verify or edit their own fee; super admin exempt). Only the super admin grants the club-admin role.
- **Counted totals**: only `approved` expenses count (`Expense::scopeCounted`); money is summed in sen.
- **Job levels are per club** (`club_job_levels`, `ClubJobLevel`, `Club::jobLevels()`): `club_user.job_level` and
  `fee_rates.job_level` hold the level's stable `code` (slugged once from the first name, never changed); show the
  `name` with `$club->jobLevelName($code)` (falls back to the code). Validate input with
  `Rule::in(array_keys($club->activeJobLevelNames()))`; display lists use `jobLevelNames()` (inactive included). A level
  in use can only be deactivated. New clubs get one level (`createDefaultJobLevel()`: `member` / "Ahli"); factory
  clubs get `executive` and `manager` (`ClubFactory::withJobLevels()` to change). Never hard-code a level list.
- **Attachments**: encrypted with `Crypt` on the private `local` disk (`serve => false`), served only via
  `admin.attachments.show` (audited) / `thumb` (not audited) or the member's own slip route. Locked once submitted.
- **Encrypted at rest**: club gateway secrets (casts), welfare personal fields (casts), attachments, and
  `users.two_factor_secret` / recovery codes (manual `encrypt`/`decrypt`; use `User::getTwoFactorSecret()`).

## Patterns

- **Shallow-route auth**: controllers for shallow resources use `Concerns\AuthorizesClubResource` and call
  `$this->authorizeClubAdmin($model->club)`.
- **Audit every write**: `AuditService::log($action, $description, $model, $clubId, $old = [], $new = [])` (positional).
- **CSP nonce**: inline `<script>`/`<style>` in Blade needs `nonce="{{ $cspNonce }}"`. Allowed hosts: jsDelivr, Google Fonts;
  form-action allows the ToyyibPay and Billplz hosts.
- **Rate limits**: login, 2FA verify, installer, CSV import `throttle:5,1`; password reset `throttle:password-reset`;
  Fund Usage / verification / billing writes `throttle:20,1`; slip upload and pay-ahead `throttle:10,1`.
- **Exports**: `Cache-Control: no-store`, attachment disposition, `CsvSafe` for formula cells.
- **Gateway access** goes through `PaymentGatewayManager::for($club)`, never a concrete gateway.

## Dev workflow

| Command | What it does |
|---|---|
| `composer setup` | install, copy `.env`, `key:generate`, `migrate --force` (no npm) |
| `npm ci && npm run build` | build `public/build` (needed to serve any Inertia page; tests that render the root view call `withoutVite()`) |
| `npm run dev` / `composer dev` | Vite dev server / `artisan serve` + queue + log tail |
| `php artisan test` (`composer test`) | PHPUnit, SQLite in memory; `RefreshDatabase` + `.installed` marker in `TestCase` |
| `DB_CONNECTION=mariadb DB_HOST=… DB_PORT=… DB_DATABASE=<empty test db> DB_USERNAME=… DB_PASSWORD=… php artisan test` | same suite on MariaDB / MySQL (`phpunit.xml` env isn't forced); must pass on SQLite, MariaDB 10.11 and MySQL 8.0 (CI: `.github/workflows/tests.yml`, every push to master and PR) |
| `npm run typecheck` | `tsc --noEmit` |
| `php artisan db:seed` | demo data, local/testing only; super admin password from `SEED_SUPER_ADMIN_PASSWORD` or printed once |
| `node tests/e2e/smoke.mjs` | browser smoke test; setup in `tests/e2e/README.md` |
| `vendor/bin/pint` | code style (`pint.json`) |
| `npm run licenses` | regenerate `docs/THIRD-PARTY-LICENSES.md` from the lockfiles (`scripts/licenses.mjs`); run after any dependency change |

Known: on PHP 8.3 one `LedgerTest` test fails (the lock needs 8.4).

## Testing conventions

- Helpers in `Tests\TestCase`: `actingAsSuperAdmin()`, `actingAsClubAdmin($club)`, `actingAsMember($club)`.
- Cross-club access to admin resources: `assertForbidden()`. Member routes hide other people's data with 404:
  `assertNotFound()`.
- 2FA challenge tests pre-seed `withSession(['two_factor_user_id' => $id])`.
- Add tests next to the area you touch (`tests/Feature/Admin`, `tests/Feature/Member`, top-level for webhooks/security).

## Pitfalls

- Until the first real production install (the next release tag) migrations are edited in place (no live data); an old database needs `migrate:fresh`. After that, additive only.
- MariaDB / MySQL (strict mode) refuse what SQLite accepts: a value longer than its VARCHAR (1406), invalid UTF-8
  (1366; cut strings with `mb_strcut`, not `substr`), and a duplicate that differs only in case or accents under
  `utf8mb4_unicode_ci` (1062; compare emails lowercased). MySQL 8 stores JSON with its keys sorted: never compare or
  hash JSON columns by key order. In migrations, an index that starts with a foreign-key column replaces the FK's own
  index, so a `down()` that drops it must put a plain index back first (error 1553).
- `CACHE_STORE` must not be `array` in production (pay lock, rate limits).
- `bootstrap/app.php`'s `withMiddleware` callback runs before `.env` is loaded: never read `env()` there. Trusted proxies
  come from `config('app.trusted_proxies')` (`TRUSTED_PROXIES`) via `AppServiceProvider::configureTrustedProxies()`.
- Webhook routes: never add `auth` or CSRF; the token/signature is the auth.
- A missing `public/build/manifest.json` breaks every Inertia page; delete a stray `public/hot` before building/uploading.
- Don't relax security defaults (CSP nonces, throttles, encrypted columns, webhook verification, maker-checker) without a reason and a test.

## Working principles

- Surgical changes in the existing style; ask when a request has several sensible readings.
- Before calling a task done: add or update tests, run `php artisan test`, and for front-end changes `npm run typecheck`
  and `npm run build`.
- Keep the docs in step with the code: first-install facts live in `docs/INSTALL.md` (README and `docs/RELEASE.md`
  only summarise or link to it); a new direct dependency also goes into `CREDITS.md`.
