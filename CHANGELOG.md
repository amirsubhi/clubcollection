# Changelog

All notable changes to this project will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [v0.0.2] — Unreleased

Security and accessibility hardening pass. No public API changes.

### Security
- Webhook handler is now atomic (lockForUpdate + transaction) and idempotent;
  fails closed if `TOYYIBPAY_WEBHOOK_SECRET` is unset.
- 2FA challenge POST is throttled (5 req/min/IP); audit-log distinguishes
  password verification from full login; recovery-code use is audited.
- Install wizard requires a strong password (12+ chars, mixed case, numbers,
  symbols), is atomic (transaction + atomic marker write), and refuses
  re-install once installed.
- Seeder refuses to run in production (default super-admin password is
  documented and would otherwise be silently provisioned).
- `MemberController` shallow routes now use `AuthorizesClubResource` and
  refuse cross-club access; pivot role no longer escalates `users.role`.
- CSV import capped at 1000 rows; formula characters (`=+@-`) stripped to
  defang downstream spreadsheet exports.
- CSP nonce now applied to dashboard / statistics charts (previously blocked
  silently); inline `onsubmit/onclick/onchange` handlers replaced with
  CSP-safe data attributes wired in the layouts.

### DB / Infra
- Composite index on `audit_logs(auditable_type, auditable_id)`.
- `sessions.user_id` foreign key with cascade-on-delete (MySQL/Postgres).
- Trusted-proxies wired via `TRUSTED_PROXIES` env var.
- Ledger date range capped at 24 months to prevent OOM.
- Portable `whereBetween` month filter in PaymentController (was
  SQLite-only `strftime`).

### Accessibility
- `aria-label` on all icon-only action buttons.
- `scope="col"` on every `<th>` across admin and member tables.
- `alt` text on every logo image.
- Status badges normalised to `-subtle` variants for WCAG AA contrast.

### Tooling
- Removed unused Vite / Tailwind / SCSS scaffolding (Bootstrap is loaded
  via CDN). `package.json` deleted; `composer setup` no longer runs `npm`.
- Added `pint.json` so the Laravel preset is consistent across machines.
- `phpunit.xml` pins `APP_KEY` so encryption-dependent tests don't depend
  on the developer's local `.env`.

### Tests
- 129 tests, 290 assertions (was 90 / 167). New suites: WebhookTest (8),
  TwoFactorTest (9), InstallationTest (6), ShallowResourceAuthorizationTest (10),
  SendOverdueRemindersTest (2). Existing suites extended with date-range
  cap, portable month filter, CSV row cap, and CSV formula-stripping tests.

---

## [v0.0.1] — 2026-03-23

First public release of Club Portal — a self-hosted club membership management system built with Laravel 13.

### Features included

#### Multi-club administration
- Super admin can create, update, and delete clubs
- Per-club admins manage their own club in isolation
- Role-based access: `super_admin`, `admin`, `member`
- Optional TOTP two-factor authentication (via Laravel Fortify)

#### Member management
- Add members individually with automatic welcome email (temporary password)
- Bulk import members via CSV upload (with per-row error reporting)
- CSV template download
- Edit member pivot data (role, job level, active status)

#### Fee & payment management
- Per-club fee rates by job level (GM, AGM, Manager, Executive, Non-Exec)
- Generate monthly, quarterly, or yearly payment records per member
- Mark payments as paid with optional reference number
- Discount management (fixed or percentage) scoped per club
- ToyyibPay integration with per-club credentials and webhook handling
- Member portal: view own payment history, pay online

#### Expense tracking
- Record club expenses by category
- Receipt file upload (JPG, PNG, PDF)
- Per-club expense categories

#### Financial ledger
- Chronological ledger with running balance and opening balance support
- Date-range filtering with month-based validation
- Monthly summary breakdown (income vs expenses per month)
- Outstanding payments panel (all-time, not date-filtered)
- Export to CSV (UTF-8 BOM, Excel-ready)
- Export to PDF (A4 landscape, suitable for AGM / treasurer reports)

#### Audit trail
- Every create / update / delete action logged with old and new values
- Filterable per-club audit log and platform-wide log for super admin

#### Other
- Self-contained installation wizard (no manual `artisan` commands needed)
- Security headers middleware (CSP, X-Frame-Options, HSTS, etc.)
- Overdue payment reminder emails (schedulable)
- Platform-wide statistics page for super admin

### Automated test suite
- 90 tests, 167 assertions (all passing)
- Covers: auth/middleware, ledger business logic, CSV/PDF export, CRUD for all
  major controllers, CSV member import edge cases, cross-club authorization, and
  payment model behaviour

### Bug fixes included at release
- `ClubController`: undefined array key crash on optional ToyyibPay fields
- `MemberController`: shallow `update`/`destroy` routes could not resolve `$club`
  from URL; club is now derived from the authenticated admin's pivot relationship
- `ExpenseController` / `PaymentController` / `MemberController`: redirects used
  non-existent route names (`admin.expenses.index`, etc.)
- 20+ Blade views: nested resource routes were missing the `clubs.` prefix
- `Discount` model: missing `HasFactory` trait
- `DiscountFactory`: `valid_from` defaulted to `null` despite `NOT NULL` constraint

---

[v0.0.1]: https://github.com/amirsubhi/scriptOLT/releases/tag/v0.0.1
