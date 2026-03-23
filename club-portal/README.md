# Club Portal

A self-hosted club membership management system built with Laravel 11. Designed for organisations that manage multiple clubs, track member payments, and handle club expenses — with a clean admin interface and an integrated payment gateway.

## Features

- **Multi-club support** — manage any number of clubs from a single portal
- **Role-based access** — super admin, club admin, and member roles
- **Fee management** — per-club fee rates by job level (GM, AGM, Manager, etc.)
- **Payment tracking** — generate monthly payment records, mark as paid, view invoices
- **ToyyibPay integration** — online payment with per-club credentials and webhook handling
- **Expense management** — track club expenses by category
- **Discount management** — apply discounts to member payments
- **Member management** — add members individually or bulk-import via CSV
- **Installation wizard** — guided setup on first run, no manual artisan commands needed
- **Welcome emails** — temporary password sent to new members automatically

## Requirements

- PHP >= 8.2
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

## CSV Member Import

Admins can bulk-import members via CSV upload from the Members page.

Required columns (in order): `name`, `email`, `job_level`, `role`, `joined_date`

Valid `job_level` values: `gm`, `agm`, `manager`, `executive`, `non_exec`

A downloadable template is available on the import page.

## Payment Gateway

This project uses [ToyyibPay](https://toyyibpay.com) for online payments. Configure credentials per club under club settings. Set `TOYYIBPAY_SANDBOX=true` in `.env` for testing.

## Tech Stack

- **Framework:** Laravel 11
- **Database:** SQLite (default) — switchable to MySQL/PostgreSQL via `.env`
- **Frontend:** Bootstrap 5.3 + Bootstrap Icons
- **Payment:** ToyyibPay
- **Mail:** Configurable (SMTP, Mailgun, log, etc.)

## Security

This project follows standard Laravel security practices including CSRF protection, input validation, role-based access control, and security headers (CSP, HSTS, X-Frame-Options, etc.).

**You are responsible for securing your own deployment.** Before going live:

- Set `APP_ENV=production` and `APP_DEBUG=false` in `.env`
- Generate a strong `APP_KEY` with `php artisan key:generate`
- Serve over HTTPS only
- Set a strong `TOYYIBPAY_WEBHOOK_SECRET`
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

---

Built by [@amirsubhi](https://github.com/amirsubhi), assisted by [Claude Code](https://claude.ai/code).
