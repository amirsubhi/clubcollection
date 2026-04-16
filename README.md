# Club Portal

Club Portal is a self-hosted membership and collection management system built on Laravel 13. It's designed for organisations that oversee multiple clubs, track member payments, and manage club expenses all through a clean admin interface with an integrated payment gateway. I built this to replace the hassle of tracking club membership payments manually.

## Features

- **Multi-club support** — manage any number of clubs from a single portal
- **Role-based access** — super admin, club admin, and member roles
- **Fee management** — per-club fee rates by job level (GM, AGM, Manager, etc.)
- **Payment tracking** — generate monthly payment records, mark as paid, view invoices
- **ToyyibPay integration** — online payment with per-club credentials and webhook handling
- **Expense management** — track club expenses by category
- **Discount management** — apply discounts to member payments
- **Financial ledger** — chronological ledger with running balance, monthly breakdown, outstanding payments panel; exportable as CSV (Excel-ready) or PDF (A4 landscape, suitable for AGM / treasurer reports)
- **Member management** — add members individually or bulk-import via CSV
- **Installation wizard** — guided setup on first run, no manual artisan commands needed
- **Welcome emails** — temporary password sent to new members automatically

## Requirements

- PHP >= 8.3
- PDO SQLite extension
- Composer

## Installation

1. Clone the repository and install dependencies:

```bash
git clone <repo-url> club-portal
cd club-portal
composer install
```

2. Copy the environment file:

```bash
cp .env.example .env
php artisan key:generate
```

3. Open the app in your browser and follow the installation wizard at `/install`.

The wizard will:
- Check system requirements
- Set your app name and URL
- Create the super admin account
- Run database migrations automatically

## Default Setup (Manual)

If you prefer the command line over the wizard:

```bash
php artisan migrate
php artisan db:seed
```

The seeder creates a super admin at `superadmin@clubportal.com` with password `Admin@123`. **Change this immediately after login.**

## Financial Ledger & Exports

Each club has a **Ledger** page (`/admin/clubs/{club}/ledger`) accessible from the sidebar and the dashboard.

**Filters:**
- Date range (defaults to current year)
- Opening balance — enter the club's balance prior to this system to get an accurate running balance

**On-screen sections:**
- Summary cards (opening, total in, total out, closing balance)
- Monthly breakdown table with running balance
- Outstanding payments panel (overdue + pending, all-time)
- Full chronological transaction table

**Exports** (both formats respect the applied filters and opening balance):

| Format | Best for |
|--------|----------|
| CSV (UTF-8 BOM) | Spreadsheets — opens natively in Excel and Google Sheets |
| PDF (A4 landscape) | Formal reports — AGM, committee meetings, treasurer's archive |

Export buttons appear on both the Ledger page and the club Dashboard header.

## CSV Member Import

Admins can bulk-import members via CSV upload from the Members page.

Required columns (in order): `name`, `email`, `job_level`, `role`, `joined_date`

Valid `job_level` values: `gm`, `agm`, `manager`, `executive`, `non_exec`

A downloadable template is available on the import page.

## Payment Gateway

This project uses [ToyyibPay](https://toyyibpay.com) for online payments. Configure credentials per club under club settings. Set `TOYYIBPAY_SANDBOX=true` in `.env` for testing.

## Tech Stack

- **Framework:** Laravel 13 (PHP 8.3+)
- **Database:** SQLite (default) — switchable to MySQL/PostgreSQL via `.env`
- **Frontend:** Bootstrap 5.3 + Bootstrap Icons (loaded via CDN — no Node/Vite build step)
- **Payment:** ToyyibPay
- **PDF generation:** [barryvdh/laravel-dompdf](https://github.com/barryvdh/laravel-dompdf) (ledger reports)
- **Mail:** Configurable (SMTP, Mailgun, log, etc.)

## Security

This project follows standard Laravel security practices including CSRF protection, input validation, role-based access control, and security headers (CSP, HSTS, X-Frame-Options, etc.).

**Authentication layers** on every admin page:
1. `auth` — session-based authentication
2. `two_factor` — enforces 2FA completion before access
3. `club_admin` — verifies the authenticated user holds an `admin` role for the requested club (super admins bypass this)

All user-supplied inputs (dates, amounts, IDs) are validated before use. Queries use parameterised bindings only — no raw SQL with user input. Financial exports include `Cache-Control: no-store` headers and safe `Content-Disposition: attachment` to prevent browser caching and content-sniffing.

**You are responsible for securing your own deployment.** Before going live:

- Set `APP_ENV=production` and `APP_DEBUG=false` in `.env`
- Generate a strong `APP_KEY` with `php artisan key:generate`
- Serve over HTTPS only
- Set a strong `TOYYIBPAY_WEBHOOK_SECRET` — when empty, all webhook calls are rejected (fail-closed)
- Set `TRUSTED_PROXIES` if the app sits behind a reverse proxy / load balancer (without it, rate limiting and audit-log IPs see the proxy address, not the client)
- Keep PHP, Composer packages, and the OS up to date
- Restrict file system permissions (`storage/` and `bootstrap/cache/` writable by web server only)
- Regularly back up your database

To report a security vulnerability, open a private issue or contact the maintainer directly.

## Disclaimer

**This software is provided "as is", without warranty of any kind, express or implied.**

The author(s) of this project accept **no liability** for:

- Any data breaches, unauthorized access, or security incidents arising from your deployment or configuration of this software
- Loss of data, financial loss, or any other damages resulting from the use or misuse of this software
- Security vulnerabilities in third-party dependencies, hosting environments, or integrations (including ToyyibPay)
- Any consequences of failing to follow the security guidelines above

By deploying this software, you agree that you are solely responsible for securing your environment, protecting user data, and complying with applicable laws and regulations (including data protection and privacy laws such as PDPA, GDPR, etc.).

This disclaimer does not override any rights you may have under the MIT License.

## License

Licensed under the [MIT License](LICENSE).

## Third-Party Credits

This project builds on open-source libraries. See [CREDITS.md](CREDITS.md) for the full list of packages, their licenses, and copyright notices.

Notable licenses in use:

| Package(s) | License |
|---|---|
| Laravel, laravel/ui, laravel/tinker, barryvdh/laravel-dompdf, pragmarx/google2fa, guzzlehttp/guzzle | MIT |
| bacon/bacon-qr-code | BSD-2-Clause |
| dompdf/dompdf, dompdf/php-font-lib | LGPL-2.1 |
| dompdf/php-svg-lib | LGPL-3.0 |
| Bootstrap, Bootstrap Icons, Chart.js (CDN) | MIT |

The LGPL-licensed dompdf packages are used unmodified as separate Composer packages. Their complete source code is publicly available and linked in CREDITS.md.

---

Built by [@amirsubhi](https://github.com/amirsubhi), assisted by [Claude Code](https://claude.ai/code).
