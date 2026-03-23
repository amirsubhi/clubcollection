# Changelog

All notable changes to this project will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [v0.0.1] — 2026-03-23

First public release of Club Portal — a self-hosted club membership management system built with Laravel 11.

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
