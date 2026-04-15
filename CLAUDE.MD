# CLAUDE.md

Guidance for AI assistants working in this repository. Read this first — it reflects what the code actually does, not what the README says.

## Project Overview

**Club Portal** is a self-hosted Laravel web app for organisations that run multiple clubs and need to track membership fees, payments, expenses, and discounts with a proper audit trail. It ships an installation wizard, TOTP two-factor auth, role-based access, per-club ToyyibPay (Malaysian payment gateway) integration, CSV member import, and a financial ledger that exports to CSV and PDF.

## Stack (verified against composer.json / package.json)

- **PHP `^8.3`** (README says 8.2 — trust `composer.json:9`)
- **Laravel `^13.0`** (README says Laravel 11 — trust `composer.json:12`)
- **Database:** SQLite by default (`database/database.sqlite`), switchable via `.env`
- **Session / queue / cache:** `database` driver (per `.env.example`)
- **Mail:** `log` driver by default
- **Frontend:** Bootstrap 5.3.3 **via CDN** in every layout. See the warning in "Pitfalls" below.
- **2FA:** `pragmarx/google2fa` + `bacon/bacon-qr-code`
- **PDF:** `barryvdh/laravel-dompdf`
- **Payments:** ToyyibPay (sandbox + production)

## Repo Layout

```
app/
  Http/
    Controllers/
      Admin/          13 controllers (Club, Member, Payment, Expense, Ledger, ...)
      Auth/           Login, 2FA challenge, password reset
      Member/         Member-facing portal (Dashboard, Payment)
      Concerns/       AuthorizesClubResource trait — shallow-route auth
      Controller.php HomeController.php InstallController.php WebhookController.php
    Middleware/       6 custom + SecurityHeaders
  Models/             User, Club, Payment, Expense, ExpenseCategory, FeeRate, Discount, AuditLog
  Services/           AuditService, ToyyibPayService
  Mail/               MemberWelcome, PaymentConfirmation, OverdueReminder
  Console/Commands/   SendOverdueReminders (scheduled daily)
bootstrap/app.php     Middleware alias registry (Laravel 11/13-style bootstrap)
config/toyyibpay.php  ToyyibPay credentials + webhook secret
routes/
  web.php             All HTTP routes (single file)
  console.php         Artisan schedule: overdue reminders at 08:00
database/
  migrations/         15 migrations
  seeders/            DatabaseSeeder (super admin + sample club + fee rates + expense categories)
  factories/          7 factories for all domain models
resources/views/
  layouts/            app.blade.php (admin), auth.blade.php, member.blade.php
  admin/ member/ auth/ emails/ install/
tests/
  TestCase.php        RefreshDatabase + `.installed` marker + acting-as helpers
  Feature/Admin/      7 feature tests
  Unit/               PaymentModelTest
```

## Routes (`routes/web.php`)

Four concentric middleware groups:

1. **`/install`** — gated by `redirect_if_installed` (GET) and `redirect_if_installed + throttle:5,1` (POST).
2. **`POST /webhook/toyyibpay`** — CSRF-exempt, no auth, **outside** `check_installed`. Verifies shared secret via query token.
3. Everything else is inside `check_installed` → redirects to `/install` until `storage/app/.installed` exists.
4. Inside that, most routes use `['auth', 'two_factor']` plus one of:
   - `member` (prefix `/my`) — member portal
   - `super_admin` (prefix `/admin`) — clubs, admins, statistics, platform audit-logs
   - `club_admin` (prefix `/admin`) — shallow resources under `/admin/clubs/{club}/...`

**Shallow-route ordering gotcha** (`routes/web.php:87-91`): the member import / template routes are declared **before** `Route::resource('clubs.members', ...)->shallow()` so `import` isn't captured as a `{member}` wildcard. When you add child routes, place literal segments before the resource.

## Middleware (aliases in `bootstrap/app.php:19-26`)

| Alias | Class | Behaviour |
|---|---|---|
| `super_admin` | `SuperAdmin` | 403 unless `role === 'super_admin'` |
| `club_admin` | `ClubAdmin` | Requires `isAdmin()`. If the route has `{club}`, verifies the user is in `club_user` with `role=admin` (super admin bypasses) |
| `member` | `MemberOnly` | **Only checks auth.** Admins/super admins can enter the member portal — do not rely on this for role isolation |
| `check_installed` | `CheckInstalled` | Redirects to `/install` unless `storage/app/.installed` exists |
| `redirect_if_installed` | `RedirectIfInstalled` | Opposite |
| `two_factor` | `RequiresTwoFactor` | If user has 2FA enabled and `session('two_factor_verified')` is falsy, logs them out, stores `two_factor_user_id`, redirects to `/two-factor-challenge` |

`SecurityHeaders` is appended globally to the `web` stack and shares a per-request nonce to all views as `$cspNonce`.

## Models & Relationships

- **User** (`app/Models/User.php`) — `role` is one of `super_admin`, `admin`, `member`. `isSuperAdmin()` and `isAdmin()` (the latter returns true for both admin roles). `hasEnabledTwoFactor()` checks `two_factor_confirmed_at`. `getTwoFactorSecret()` / `getTwoFactorRecoveryCodes()` manually `decrypt()` — these fields are **not** declared as `'encrypted'` casts.
- **Club** — `toyyibpay_secret_key` uses the `'encrypted'` cast (automatic). `members()` is a belongsToMany via `club_user` with pivot `role`, `job_level`, `joined_date`, `is_active`. `currentFeeRates()` filters by `effective_to`.
- **Payment** — `status` ∈ `{pending, paid, overdue}`. `amount` is `decimal:2`; dates (`period_start`, `period_end`, `due_date`, `paid_date`) cast `date`. `bill_code` + `transaction_id` are ToyyibPay fields.
- **AuditLog** — polymorphic (`auditable_type/auditable_id`), immutable, captures `old_values` / `new_values` as JSON plus IP and user agent.

**Enums:**
- `users.role`: `super_admin | admin | member`
- `club_user.role`: `admin | member`
- `club_user.job_level`: `gm | agm | manager | executive | non_exec`
- `payments.status`: `pending | paid | overdue`
- `payments.frequency`: `monthly | quarterly | yearly`

**Encrypted at rest:**
- `clubs.toyyibpay_secret_key` (auto cast)
- `users.two_factor_secret`, `users.two_factor_recovery_codes` (manual encrypt/decrypt)

## Key Patterns — Follow These

### 1. Shallow-route authorization

Routes like `PUT /admin/payments/{payment}` lose the `{club}` segment, so the `club_admin` middleware can't verify ownership. Controllers use the `AuthorizesClubResource` trait (`app/Http/Controllers/Concerns/AuthorizesClubResource.php`) and call `$this->authorizeClubAdmin($payment->club)` at the top of `show/edit/update/destroy/markPaid`. See `app/Http/Controllers/Admin/PaymentController.php` as the canonical example. **Any new controller for a shallow-nested resource must use this trait.**

### 2. Audit every write

Call `AuditService::log($action, $description, $model, $clubId, $old = [], $new = [])` (positional args) from controllers in `store / update / destroy / markPaid / import / 2FA toggle` actions. The service auto-captures user, role, IP, and user agent. Don't write to `audit_logs` directly.

```php
AuditService::log(
    'payment.updated',
    "Payment #{$payment->id} updated (status: {$old['status']} → {$data['status']}).",
    $payment,
    $payment->club_id,
    $old,
    $payment->fresh()->only([...])
);
```

### 3. ToyyibPay integration

`app/Services/ToyyibPayService.php`:
- `isConfiguredForClub(Club)` — check before offering online payment (per-club creds preferred, global `.env` fallback)
- `createBill([...])` — returns a `bill_code` string or `null`. Amount goes to ToyyibPay in cents: `(int) round($amount * 100)`
- `paymentUrl($billCode)` — build the redirect URL
- `signedCallbackUrl($url)` — appends `?webhook_token=<secret>`
- `verifyWebhookSecret($token)` — uses `hash_equals()`
- `verifyCallback($payload)` — true when ToyyibPay returns `status_id === 1`

Webhook handler (`app/Http/Controllers/WebhookController.php`):
- Validates the token first (403 on mismatch)
- Looks up `Payment` by `bill_code`, then by `reference`
- **Idempotent**: early-returns if `status === 'paid'`
- Updates to `paid`, sends `PaymentConfirmation` mail, returns `200 ok` (even on "not found" / "not successful" — ToyyibPay retries otherwise)

### 4. Two-factor auth flow

Login → `RequiresTwoFactor` sees 2FA enabled but session unverified → logs user out, stores `two_factor_user_id` → redirect `/two-factor-challenge` → `TwoFactorChallengeController` validates TOTP → sets `session('two_factor_verified') = true`. Don't design flows that assume a route behind the `two_factor` middleware is reachable while unverified.

### 5. Install wizard

Gated by the `storage/app/.installed` marker file. `CheckInstalled` redirects every non-install / non-webhook route to `/install` until the file exists. Tests create the marker in `Tests\TestCase::setUp()` so requests aren't redirected.

### 6. CSP nonce

`SecurityHeaders` generates a per-request `base64` nonce and shares it to views as `$cspNonce`. The CSP blocks `'unsafe-inline'`, so every inline `<script>` or `<style>` needs `nonce="{{ $cspNonce }}"`. Allowed external hosts: `cdn.jsdelivr.net` (scripts/styles/fonts). Form-action allows `toyyibpay.com` + `dev.toyyibpay.com`.

### 7. Rate limiting

`throttle:5,1` is applied to:
- Login POST (`routes/web.php:46-48`)
- Install POST (`routes/web.php:27`)
- CSV import POST (`routes/web.php:88`)

Preserve this pattern for new sensitive endpoints.

### 8. Export responses

CSV and PDF export actions set `Cache-Control: no-store` and `Content-Disposition: attachment` to prevent browser caching and content-sniffing.

## Dev Workflows

From `composer.json:37-53`:

| Command | What it does |
|---|---|
| `composer setup` | `composer install` → copy `.env` → `key:generate` → `migrate --force` → `npm install` → `npm run build` |
| `composer dev` | Runs four concurrent processes: `artisan serve`, `queue:listen --tries=1 --timeout=0`, `pail` (log tail), `npm run dev` |
| `composer test` | `config:clear` then `php artisan test` (PHPUnit; SQLite in-memory per `phpunit.xml`) |
| `php artisan db:seed` | Creates `superadmin@clubportal.com` / `Admin@123` + sample club + fee rates + expense categories (no sample members/payments) |
| `php artisan test --filter=LedgerTest` | Run a single test file |
| `vendor/bin/pint` | Laravel Pint — standard preset, no custom config |
| `php artisan schedule:run` | To execute the daily 08:00 overdue-reminders job, run this per minute from cron |

## Testing Conventions

- `Tests\TestCase` uses `RefreshDatabase` and `touch`es `storage/app/.installed` in `setUp()`.
- Helpers on the base class: `actingAsSuperAdmin()`, `actingAsClubAdmin(Club $club)`, `actingAsMember(Club $club)`. Factory users have 2FA disabled, so `RequiresTwoFactor` passes through.
- Feature tests: `tests/Feature/Admin/` — `AuthorizationTest`, `ClubControllerTest`, `ExpenseControllerTest`, `LedgerTest`, `MemberControllerTest`, `MemberCsvImportTest`, `PaymentControllerTest`.
- Unit tests: `tests/Unit/PaymentModelTest.php`.
- Factories exist for all 7 domain models in `database/factories/`.
- Add new admin CRUD coverage in `tests/Feature/Admin/`.

## Common Pitfalls

- **README version drift** — composer says PHP 8.3 / Laravel 13. Trust composer.
- **Bootstrap is loaded via CDN** in every real layout (`resources/views/layouts/{app,auth,member}.blade.php`, `install/index.blade.php`). The Vite + SCSS pipeline (`resources/sass/app.scss`, `vite.config.js`) is vestigial Laravel scaffolding — the only file using `@vite(...)` is `welcome.blade.php`, which is unreachable after install. **`npm run build` produces unused assets.** Tailwind 4 is in `package.json` but not wired into `app.scss` — **do not add Tailwind classes, they won't render.** Use Bootstrap 5 utility classes.
- **`MemberOnly` does not restrict to members** — it only checks auth. If you need admin-vs-member isolation, check roles explicitly.
- **`two_factor_secret` / `two_factor_recovery_codes` are NOT `encrypted` casts** — they're encrypted on write by `ProfileController` and decrypted by `User::getTwoFactorSecret()` / `getTwoFactorRecoveryCodes()`. Don't read the raw attribute.
- **Money is `decimal:2`** — arithmetic on the cast value. For ToyyibPay: `(int) round($amount * 100)` for cents.
- **Webhook route is CSRF-exempt and unauthenticated** — do not add `auth` or CSRF middleware. The token query param is the auth.
- **Place specific child routes before `Route::resource(...)->shallow()`** to avoid wildcard collisions (member-import / template is the existing example).
- **Every domain write needs a matching `AuditService::log(...)` call.** Grep for existing calls when adding a new action.
- **Inline `<script>` / `<style>` without `nonce="{{ $cspNonce }}"` will be blocked** by CSP.
- **`Payment.status` has three values**, not two — `overdue` is set by the scheduled `payments:send-overdue-reminders` command (`routes/console.php:12-14`).

## Working Principles

- Think before coding. Surface assumptions, ask rather than guess, present alternatives when a request has multiple sensible interpretations.
- Surgical changes only. Match existing style — Bootstrap markup, positional `AuditService::log(...)` arguments, `AuthorizesClubResource` trait usage, `throttle:5,1` on sensitive POSTs.
- Don't touch the security defaults (CSRF, CSP nonces, throttle limits, encrypted columns, webhook secret verification) without an explicit reason.
- Before calling a task done: add or update a feature test in `tests/Feature/Admin/` for new admin behaviour, then run `composer test`.

---

Built by [@amirsubhi](https://github.com/amirsubhi), assisted by [Claude Code](https://claude.ai/code).
