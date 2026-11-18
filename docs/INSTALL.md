# Installation guide

This is the complete guide to a first install of Club Portal. It covers:

- [what you need](#1-what-you-need);
- [Path A: a local copy for trying it out or development](#2-path-a-local-development);
- [Path B: a live site on cPanel hosting](#3-path-b-cpanel-hosting) (the usual setup);
- [using MySQL instead of SQLite](#4-mysql-instead-of-sqlite);
- [what to do after the install](#5-after-the-install) and [backups](#6-backups);
- [troubleshooting](#8-troubleshooting).

Upgrading an existing site is in [RELEASE.md](RELEASE.md). The [user guide](USER-GUIDE.md) explains the day-to-day
tasks once the site is running.

You don't need to be a developer to follow Path B, but you do need a cPanel account with **Terminal** (or SSH) access,
because a few `php artisan` commands have to be run on the server.

---

## 1. What you need

### On the server

- **PHP 8.4 or newer.** `composer.json` still says `^8.3`, but the locked dependencies (`composer.lock`, Laravel 13's
  Symfony 8 components) need 8.4, and the installer refuses anything older. In cPanel choose it under
  **MultiPHP Manager** (or **Select PHP Version**).
- **PHP extensions.** Most hosts enable these by default; check with `php -m` in the Terminal, or in cPanel under
  **Select PHP Version → Extensions**:

  | Extension | Why |
  |---|---|
  | `pdo_sqlite` | The installer checks for it, **even if you use MySQL**. Also the default database. |
  | `pdo_mysql` | Only if you use MySQL / MariaDB. |
  | `mbstring`, `openssl`, `ctype`, `filter`, `hash`, `session`, `tokenizer`, `pcre`, `json` | Laravel. |
  | `fileinfo` | Detecting the type of uploaded slips and documents. |
  | `dom`, `libxml`, `iconv` | The ledger PDF (dompdf) and the 2FA QR code. |
  | `gd` (recommended) | dompdf uses it to draw the club logo in the ledger PDF. |

- A database: **SQLite** (a single file, nothing to set up; the default) or **MySQL / MariaDB** (see [section 4](#4-mysql-instead-of-sqlite)).
- **HTTPS** for the domain (cPanel → **SSL/TLS Status** → AutoSSL). Payments, logins and the installable app all need it.
- A mailbox for sending email over SMTP (e.g. a cPanel email account such as `noreply@your-domain`).
- Cron jobs (every cPanel plan has them).

The server **never needs Node.js** and no queue worker or other background process: you upload ready-built files.

### On the machine that builds the release (your computer or CI)

- **PHP 8.4** and **Composer**, to install the PHP packages into `vendor/`.
- **Node.js 22** and npm, to build the front end into `public/build/`.
- **Git**, to get a clean copy of the code.

---

## 2. Path A: local development

For trying the app on your own computer. Uses SQLite and writes emails to the log instead of sending them.

```bash
git clone https://github.com/amirsubhi/clubcollection.git club-portal && cd club-portal
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan db:seed            # demo data, local/testing only
npm ci && npm run build        # or `npm run dev` while working on the React pages
touch storage/app/.installed   # skip the installer locally
php artisan serve
```

Open http://127.0.0.1:8000 and log in as `superadmin@example.com`. The password is `SEED_SUPER_ADMIN_PASSWORD`
from `.env`, or a random one the seeder prints once (there is no well-known default). The seeder also creates a
sample club with members, fees and Fund Usage records, and refuses to run unless `APP_ENV` is `local` or `testing`.

`composer dev` runs the server, a queue listener and the log tail together. Emails appear in `storage/logs/laravel.log`
(`MAIL_MAILER=log`). To try the real installer instead, skip `db:seed` and the `touch storage/app/.installed` line,
set `INSTALL_TOKEN` in `.env` and open `/install`.

---

## 3. Path B: cPanel hosting

The steps below use `~/clubportal` as the app folder and `club.example.com` as the address; use your own.
Do them in this order.

### Step 1. Build the release (on your computer or in CI)

Build from a **clean checkout**, so nothing from your own testing (a local `.env`, the demo database, uploaded test
files) ends up on the server:

```bash
git clone https://github.com/amirsubhi/clubcollection.git clubportal && cd clubportal
composer install --no-dev -o
npm ci && npm run build
```

`npm run build` writes `public/build/` (with `manifest.json`), which the pages need. `public/build`, `vendor` and
`node_modules` are not in git, so every release must be built this way.

### Step 2. Upload the files

Upload the whole folder **including `vendor/` and `public/build/`**, for example as a zip through cPanel
**File Manager** (then **Extract**). **Do not upload:**

- `node_modules/`;
- `public/hot` (a leftover one makes the pages load assets from a development server, so they show blank);
- a local `.env`;
- `database/*.sqlite` (your local database);
- `storage/app/.installed` (it would switch the installer off).

### Step 3. Point the domain at `public/`

The web root must be the app's **`public/`** folder, never the app folder itself: `.env`, `storage/` and `vendor/`
must not be reachable from the web. Either:

- **(recommended)** cPanel → **Domains** → **Manage** for the (sub)domain → set **Document Root** to
  `clubportal/public`; or
- upload the app **outside** `public_html` (e.g. `~/clubportal`) and make `public_html` a link to its `public/`
  folder. Only do this when `public_html` is empty:
  `rmdir ~/public_html && ln -s ~/clubportal/public ~/public_html` (`rmdir` refuses if the folder is not empty, so move or delete its contents first).

Turn on **Force HTTPS Redirect** for the domain (cPanel → **Domains**).

### Step 4. Set permissions

In the Terminal:

```bash
cd ~/clubportal
chmod -R 775 storage bootstrap/cache
```

The web server writes logs, sessions, uploaded documents and caches there.

### Step 5. Create `.env`

```bash
cp .env.example .env
php artisan key:generate
```

Then edit `.env` (File Manager → Edit) and set at least:

```dotenv
APP_NAME="Club Portal"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://club.example.com        # the exact address members will type, with https://
APP_TIMEZONE=Asia/Kuala_Lumpur          # dates, "today" and the 08:00 reminder run
LOG_LEVEL=warning
SESSION_SECURE_COOKIE=true

INSTALL_TOKEN=<random, 16+ characters>  # the installer asks for it, then blanks it
```

Make random values with `php -r "echo bin2hex(random_bytes(32));"`.

> **Get `APP_URL` right.** In production the app answers only to `APP_URL`'s host (and its subdomains) and builds
> every link, email link and payment callback from it. A wrong host makes it refuse every request with "Bad Request"
> (400), `/install` included.

The full production `.env` (email, gateways, proxies) is in [RELEASE.md](RELEASE.md#production-env); you fill in
the rest in steps 8 and 9.

### Step 6. Link storage and create the tables

```bash
php artisan storage:link        # club logos are served from public/storage
php artisan migrate --force
```

Run `migrate` **before** opening `/install`: sessions and cache live in the database (`SESSION_DRIVER=database`,
`CACHE_STORE=database`), so `/install` answers with a server error until the tables exist. With SQLite this also
creates `database/database.sqlite`.

If `php` in the Terminal is older than 8.4, use the 8.4 binary by its full path, e.g. `/usr/local/bin/ea-php84`
on cPanel EasyApache (check with `php -v`).

### Step 7. Run the installer

Straight away, open `https://club.example.com/install`. Without `INSTALL_TOKEN` the installer is disabled; with it,
whoever reaches the page first still needs the token.

1. **Club Portal Setup** checks the PHP version, the `pdo_sqlite` extension, and that `storage/` and `.env` are
   writable. Fix anything marked red, then reload.
2. Enter the **install token**, the application name and URL (the same `https://` address as `APP_URL`), and the
   super admin's name, email and password (12+ characters with upper and lower case, numbers and symbols).
3. **Install Application** runs the migrations, creates the super admin, writes `APP_ENV=production`,
   `APP_DEBUG=false`, `APP_TIMEZONE=Asia/Kuala_Lumpur`, `SESSION_SECURE_COOKIE=true` (for an https URL) and `LOG_LEVEL=warning`, creates
   `storage/app/.installed` and blanks the token. It can't run again afterwards.

Never run `php artisan db:seed` on the server: the demo seeder refuses to run outside `local`/`testing` anyway.

### Step 8. Email (SMTP)

**Without real SMTP settings no email goes out**: not the welcome email with each new member's temporary password,
not receipts, slip rejections or admin notices. cPanel → **Email Accounts** → **Connect Devices** shows the values.

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtps                       # port 465; or MAIL_SCHEME=smtp with port 587 (STARTTLS)
MAIL_HOST=mail.example.com
MAIL_PORT=465
MAIL_USERNAME=noreply@example.com
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="Club Portal"
```

Emails are sent during the request, so no queue worker is needed.

### Step 9. Payment gateway secrets

- **ToyyibPay:** set `TOYYIBPAY_WEBHOOK_SECRET` to a random value of **at least 32 characters**. Shorter, empty or
  placeholder values make the app refuse every payment callback (and no bill can be opened). Set
  `TOYYIBPAY_SANDBOX=false` for real payments. The secret key and category code can be global (`.env`) or per club
  (set by the super admin on the club's page).
- **Billplz:** set `BILLPLZ_X_SIGNATURE_KEY` (from Billplz) in `.env` or per club, and `BILLPLZ_SANDBOX=false`.
- Keep `TOYYIBPAY_VERIFY_CALLBACK=true` and `TOYYIBPAY_CHARGE_VERIFIED=false` until a ToyyibPay sandbox run has been
  done (see [payment-options.md](payment-options.md#nota-teknikal--technical-note)).
- **Before real payments:** keep `TOYYIBPAY_SANDBOX=true` until the ToyyibPay sandbox test in
  [SANDBOX-TEST.md](SANDBOX-TEST.md) has been run on a staging copy and every item has passed.
  Until then, don't choose ToyyibPay as any live club's payment method: in sandbox mode a simulated payment would
  mark a fee paid with no real money taken. Use bank transfer (slip upload) meanwhile.
- If your host puts a proxy or load balancer in front of PHP, set `TRUSTED_PROXIES` to its IP addresses (comma
  separated), so rate limits and the audit log see the real visitor's IP.

### Step 10. Upload limits

Members upload up to 3 slips of 10 MB each, and Fund Usage records take several documents. In cPanel
**MultiPHP INI Editor** (or a `.user.ini` in `public/`) set:

```ini
upload_max_filesize = 12M
post_max_size = 32M
```

Why these numbers, and how to allow more documents per save: [RELEASE.md](RELEASE.md#php-upload-limits).

### Step 11. Cache the configuration

```bash
php artisan config:cache
```

Run it again after **any** later change to `.env`: a cached configuration ignores `.env`.

### Step 12. Cron job

cPanel → **Cron Jobs** → add one that runs **every minute**:

```
* * * * * cd /home/<user>/clubportal && php artisan schedule:run >> /dev/null 2>&1
```

Use the PHP 8.4 binary (e.g. `/usr/local/bin/ea-php84`) if `php` is older. The scheduler runs the overdue-fee
reminders (`payments:send-overdue-reminders`) daily at 08:00 in the app's time zone (`APP_TIMEZONE`). With the
recommended `APP_TIMEZONE=Asia/Kuala_Lumpur` that is 8 am Malaysia time; without it the app uses UTC (4 pm in Malaysia).

---

## 4. MySQL instead of SQLite

SQLite is fine for a club of a few hundred members. To use MySQL / MariaDB:

1. cPanel → **MySQL Databases**: create a database and a user, and add the user to the database with
   **All Privileges**. cPanel prefixes both names with your account name (e.g. `myacct_clubportal`).
2. In `.env`, before step 6:
   ```dotenv
   DB_CONNECTION=mysql
   DB_HOST=localhost
   DB_PORT=3306
   DB_DATABASE=myacct_clubportal
   DB_USERNAME=myacct_clubuser
   DB_PASSWORD=...
   ```
3. Make sure `pdo_mysql` is enabled. Keep `pdo_sqlite` enabled too: the installer checks for it.
4. Carry on with step 6 (`migrate --force`). There is no `database/database.sqlite` to back up; back up the MySQL
   database instead (cPanel → **Backup** or phpMyAdmin → Export).

PostgreSQL also works through `DB_CONNECTION=pgsql` with the `pdo_pgsql` extension.

---

## 5. After the install

Log in as the super admin, then:

1. **Turn on 2FA** for your own account (**Security & 2FA** in the sidebar; see the
   [user guide](USER-GUIDE.md#turn-on-two-factor-authentication)).
2. **Create the club:** **Clubs → New Club** with its name, email, logo and payment gateway (ToyyibPay or Billplz)
   with that club's credentials, stored encrypted (leave them empty to use the global ones in `.env`).
3. **Grant club admins:** only the super admin can. **Members → Add member** for the club with **Club Role** set to
   **Admin** (or edit an existing member). Give each club **at least two admins** so there is always a second person
   to approve spending and bank changes.
4. **Set the fees:** **Fee Rates** (monthly fee per job level), **Discounts** if any, and **Expense categories**.
5. **Billing settings:** which plans members may choose (monthly, quarterly, yearly), the default plan, and who pays
   the gateway fee.
6. **Bank / DuitNow details** (Billing settings), for the free transfer option. Every change, the first one included,
   waits until a **second admin** (or the super admin) approves it; members see the new details only after that.
7. **Add members** one by one or by CSV import. Each gets a welcome email with a temporary password.
8. **Send a test email:** log out and use **Forgot password?** on the login page with your own email. If it doesn't
   arrive, see [emails not sent](#emails-are-not-sent).
9. **Try a payment** with the gateway's sandbox before going live (for ToyyibPay follow
   [SANDBOX-TEST.md](SANDBOX-TEST.md) on a staging copy), and check the pre-launch list in
   [option-a-report.md](option-a-report.md#before-go-live-checklist).

The [user guide](USER-GUIDE.md) covers each of these screens in detail.

## 6. Backups

Back up these together, regularly, and keep a copy **off the server**:

| What | Where | Why |
|---|---|---|
| The whole `.env` | app folder | Holds `APP_KEY`, which encrypts documents, slips, welfare details and club gateway secrets (**without the same key a restored backup can't be read**), plus the webhook secret, SMTP password and global gateway keys. Keep this copy somewhere private. |
| Uploaded documents and slips | `storage/app/private/` | Encrypted files; useless without `APP_KEY`, and the database is useless without them. |
| Club logos | `storage/app/public/` | |
| The database | `database/database.sqlite`, or a MySQL dump | All records. |

cPanel's **Backup** page (or your host's backup tool) can include the home folder and the MySQL databases. Test a
restore at least once.

## 7. Upgrading

Every later release (build, upload, `migrate --force`, caches, the PWA cache version) is described in
[RELEASE.md](RELEASE.md).

---

## 8. Troubleshooting

Look in `storage/logs/laravel.log` first: it usually names the problem.

### `/install` shows a server error (500)

The database tables don't exist yet. Run `php artisan migrate --force` (step 6), then reload. Also check that
`storage/` and `bootstrap/cache` are writable (step 4) and that `APP_KEY` is set.

### Every page shows "Bad Request" (400)

The **`APP_URL` host is wrong** (or the site is opened at another address). In production the app answers only to
`APP_URL`'s host and its subdomains and refuses every other `Host` with a 400 error, `/install` included; this is
not written to the log. Fix `APP_URL` to the exact address in the browser bar (with `https://`), then run
`php artisan config:cache`.

### Every page shows a server error (500)

- A cached configuration from before an `.env` change: run `php artisan config:cache` again.
- PHP older than 8.4, or a missing extension: see [section 1](#1-what-you-need).

### "Page expired" (419) after submitting a form

The session cookie isn't coming back. Usually the site is opened over **http** while `SESSION_SECURE_COOKIE=true`
(the cookie is then never sent): use the `https://` address and turn on Force HTTPS Redirect. Also check that
`APP_URL` matches the address you use and that `SESSION_DOMAIN` is `null` unless you know you need it.

### "Upload too large" (413) when sending slips or documents

The upload is bigger than PHP's `post_max_size`. Raise the limits as in [step 10](#step-10-upload-limits).

### Blank page, or the page has no styling

- `public/build/` is missing or out of date: build it (`npm ci && npm run build`) and upload `public/build/`. Until then the sign-in page still works with a plain fallback style, but the pages after it do not.
- A leftover `public/hot` file on the server: delete it.
- After an upgrade, hard-refresh the page; if it persists, check that `CACHE_VERSION` in `public/sw.js` was bumped
  (see [RELEASE.md](RELEASE.md#pwa-service-worker-publicswjs)).

### Emails are not sent

- `MAIL_MAILER` is still `log` (emails go to `storage/logs/laravel.log`): set the SMTP values from [step 8](#step-8-email-smtp).
- Wrong port and scheme pair: use 465 with `MAIL_SCHEME=smtps`, or 587 with `MAIL_SCHEME=smtp`.
- `MAIL_FROM_ADDRESS` must be the mailbox you log in with (many hosts refuse other senders).
- Run `php artisan config:cache` after changing `.env`.

### Payment callbacks are refused (403) and fees stay unpaid

- `TOYYIBPAY_WEBHOOK_SECRET` is empty, a placeholder, or **shorter than 32 characters**. Set a random 32+ character
  value and run `php artisan config:cache`. Bills opened before the change carry a token made from the old secret.
- Billplz: `BILLPLZ_X_SIGNATURE_KEY` is missing or wrong (globally or for the club).
- The host's firewall (ModSecurity) or an http→https / www redirect blocks or redirects the gateway's POST to
  `/webhook/toyyibpay` or `/webhook/billplz`. Ask the host to allow those paths.

### Overdue reminders are never sent

The cron job isn't running. Check the path in the cron command (`cd /home/<user>/clubportal`), use the PHP 8.4
binary, and temporarily replace `>> /dev/null 2>&1` with `>> ~/cron.log 2>&1` to see errors. Running
`php artisan schedule:list` shows the reminder job and its next run.
