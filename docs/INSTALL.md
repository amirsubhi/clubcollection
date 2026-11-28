# Installation guide

This is the complete guide to a first install of Club Portal. It covers:

- [what you need](#1-what-you-need);
- [Path A: a local copy for trying it out or development](#2-path-a-local-development);
- [Path B: a live site on cPanel hosting](#3-path-b-cpanel-hosting) (the usual setup);
- [the database: MariaDB / MySQL on a live site](#4-mariadb--mysql);
- [what to do after the install](#5-after-the-install) and [backups](#6-backups);
- [troubleshooting](#8-troubleshooting).

Upgrading an existing site is in [RELEASE.md](RELEASE.md). The [user guide](USER-GUIDE.md) explains the day-to-day
tasks once the site is running.

You don't need to be a developer to follow Path B, but you do need a cPanel account with **Terminal** (or SSH) access,
because a few `php artisan` commands have to be run on the server.

---

## 1. What you need

### On the server

- **PHP 8.4 or newer** (8.4.1 at least). `composer.json` and the locked dependencies (`composer.lock`, Laravel 13's
  Symfony 8 components) need it, and the installer refuses anything older. In cPanel choose it under
  **MultiPHP Manager** (or **Select PHP Version**). On an older PHP every page shows a short "Server setup needed"
  page (HTTP 503) that says which PHP to choose, instead of the app. The minimum version and the extension list
  below are kept in one file, `bootstrap/requirements.php`, which the installer, `app:doctor` and that page read.
- **PHP extensions.** Most hosts enable these by default; check with `php -m` in the Terminal, or in cPanel under
  **Select PHP Version → Extensions**:

  | Extension | Why |
  |---|---|
  | `pdo_mysql` | MariaDB / MySQL (the usual choice on cPanel). |
  | `pdo_sqlite` | Only if you use SQLite (local copies, very small clubs). |
  | `mbstring`, `openssl`, `ctype`, `filter`, `hash`, `session`, `tokenizer`, `pcre`, `json` | Laravel. |
  | `fileinfo` | Detecting the type of uploaded slips and documents. |
  | `curl` | Talking to the payment gateways (ToyyibPay, Billplz) and the breached-password check. |
  | `xml` | XML handling used by Laravel and its libraries (`app:doctor` checks for it). |
  | `zip` | The daily backup (`backup:run` writes a zip file). |
  | `dom`, `libxml`, `iconv` | The ledger PDF (dompdf) and the 2FA QR code. |
  | `gd` (with PNG, JPEG and WebP support) | Club and portal logos: every upload is decoded and saved again as a PNG (no SVG, metadata stripped). Without GD, logos can't be uploaded (the pages say so) and dompdf can't draw the logo in the ledger PDF. |

- A database: **MariaDB / MySQL** for a live site (see [section 4](#4-mariadb--mysql)), or **SQLite** (a single file, nothing to set up; the default in `.env.example`, fine for a local copy).
  Supported: **MariaDB 10.11** (tested; 10.6+ should work) and **MySQL 8.0+** (8.0 tested), including MySQL builds such as Percona Server
  and hosted MySQL (Amazon RDS / Aurora, Google Cloud SQL). Databases that only speak the MySQL protocol (TiDB,
  PlanetScale / Vitess, SingleStore) handle foreign keys and row locks differently and are not supported.
- **HTTPS** for the domain (cPanel → **SSL/TLS Status** → AutoSSL). Payments, logins and the installable app all need it.
- A mailbox for sending email over SMTP (e.g. a cPanel email account such as `noreply@your-domain`).
- Cron jobs (every cPanel plan has them).

The server **never needs Composer, Node.js or git**, and no queue worker or other background process: you upload
the ready-built release zip.

### Only if you build the release yourself (your computer or CI)

Not needed when you download `clubportal-<version>.zip` from the GitHub Release (CI builds it).

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

### Step 1. Get the release zip

Download `clubportal-<version>.zip` and `clubportal-<version>.zip.sha256` from the project's GitHub Releases page
(the latest `v*` release) and check the zip with `sha256sum -c clubportal-<version>.zip.sha256` (Windows:
`certutil -hashfile clubportal-<version>.zip SHA256`). It already holds `vendor/` and the built front end
(`public/build/`), so the host needs no Composer or Node.

To build it yourself instead, run `scripts/build-release.sh <version> [git ref]` from a clone (PHP 8.4 with Composer,
Node 22, git, zip). It builds from a clean `git archive` export, so nothing from your own testing (a local `.env`, the
demo database, uploaded test files) ends up in it; see [RELEASE.md](RELEASE.md#1-get-the-release-zip).

### Step 2. Upload the files

cPanel → **File Manager** → your home folder → **Upload** the zip → select it → **Extract**. This creates
`clubportal-<version>/`; rename it to `clubportal` (or the folder name you chose) and delete the zip. If you already
chose PHP 8.4 for the domain in **MultiPHP Manager**, check `public/.htaccess` afterwards: cPanel may have written its
PHP-version lines into the old one. Choose PHP 8.4 there again after the upload so the new `public/.htaccess` gets
them (check with `php -v` in the Terminal and `app:doctor` in step 13). The zip contains
**no** `.env`, `node_modules/`, `public/hot`, `*.sqlite` database or `storage/` contents (only the empty folders), so
there is nothing to remove: the installer starts from a clean state.

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

1. **Club Portal Setup** checks the same server requirements as `app:doctor` (step 13): the PHP version, every
   extension of [section 1](#1-what-you-need) that `app:doctor` checks (`ctype`, `curl`, `dom`, `fileinfo`, `filter`,
   `gd`, `mbstring`, `openssl`, `pdo`, `tokenizer`, `xml`, `zip`) plus the PDO extension of your database
   (`pdo_mysql` or `pdo_sqlite`), that `storage/` and `bootstrap/cache` are writable, and that `.env` exists and is
   writable. Each row marked **Fail** says how to fix it (for example "Enable the zip extension in cPanel → Select
   PHP Version → Extensions (for PHP 8.4)"). **Install Application** stays disabled until every row passes; fix
   them, then reload.

   A few problems stop the app before this page can show: PHP older than 8.4.1, and before the install a missing
   `pdo` / `openssl` / database PDO extension or a `storage/framework/views` or `bootstrap/cache` folder PHP can't
   write to. Then every page shows a short **Server setup needed** page (HTTP 503) naming what to change; fix it and
   reload.
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

It also runs `payments:reconcile-open-bills` every 15 minutes: for each open ToyyibPay bill opened between 10 minutes
and 3 days ago it asks ToyyibPay whether the bill was paid, and settles it exactly as the callback would have. So a
lost callback no longer leaves a paid fee "Dalam proses" until the overdue reminder goes out. (Billplz bills are
skipped and still rely on the Billplz callback.) Without the cron job this safety net does not run.

It also makes the daily backup (`backup:run` at 02:15 Malaysia time, see [Backups](#6-backups)) and writes a heartbeat
every minute, which `app:doctor` (step 13) checks.

### Step 13. Check the server

The last step, and again after any change to the server or `.env`:

```bash
php artisan app:doctor
```

It changes nothing. It prints one row per check (PHP 8.4.1+ and its extensions, `APP_KEY`, `APP_ENV` / `APP_DEBUG` /
`APP_URL` / `SESSION_SECURE_COOKIE` / `APP_TIMEZONE`, the cache store, upload and memory limits, the private files
folder, `storage:link`, the front-end build, no Vite dev-server file (`public/hot`), the database connection, webhook
secrets, ToyyibPay sandbox mode, email, the cron job and the latest
backup) marked **PASS**, **WARN** or **FAIL**, with a one-line fix for each problem. It never prints a key or secret.

- Fix every **FAIL** row, run `php artisan config:cache` if you changed `.env`, and run it again. It exits with code 1
  while anything fails (`--json` gives the same result for scripts).
- Read every **WARN** row. "ToyyibPay mode" warns while `TOYYIBPAY_SANDBOX=true` and a club uses ToyyibPay: expected
  on a staging site, not on the live one.
- The cron row passes only once the cron job of step 12 has run (wait a minute or two after adding it).
- PHP version, extensions and limits are those of the command-line PHP. The website's PHP can differ: check cPanel
  **MultiPHP Manager** and **MultiPHP INI Editor** show the same values.

---

## 4. MariaDB / MySQL

Use MariaDB (or MySQL) for a live site: cPanel hosts run it and back it up. SQLite is for a local copy or a very
small club. The test suite passes on MariaDB 10.11 and MySQL 8.0.

1. cPanel → **MySQL Databases** (MariaDB shows under the same name): create a database and a user, and add the user
   to the database with **All Privileges**. cPanel prefixes both names with your account name
   (e.g. `myacct_clubportal`). Any database collation is fine: the tables are created as `utf8mb4`.
2. In `.env`, before step 6 (`DB_CONNECTION=mariadb` for MariaDB, `mysql` for MySQL):
   ```dotenv
   DB_CONNECTION=mariadb
   DB_HOST=localhost
   DB_PORT=3306
   DB_DATABASE=myacct_clubportal
   DB_USERNAME=myacct_clubuser
   DB_PASSWORD=...
   ```
   `DB_HOST` is `localhost` unless the host keeps databases on a separate server: then use the server name cPanel
   shows on the **MySQL Databases** or **Manage My Databases** page.
3. Make sure `pdo_mysql` is enabled (`pdo_sqlite` is not needed).
4. Carry on with step 6 (`migrate --force`). `php artisan backup:run` dumps the MySQL database with `mysqldump` when
   the server has it; if not, it says so and backs up the files only, so back up the database with cPanel → **Backup**
   (or phpMyAdmin → Export).

PostgreSQL (`DB_CONNECTION=pgsql` with the `pdo_pgsql` extension) is untested: the test suite runs on SQLite,
MariaDB and MySQL only, so use it at your own risk.

---

## 5. After the install

Log in as the super admin, then:

1. **Turn on 2FA** for your own account: you are taken to **Security & 2FA** at your first sign-in, since admins
   must have it (`REQUIRE_ADMIN_2FA`; see the [user guide](USER-GUIDE.md#turn-on-two-factor-authentication)).
2. **Create the club:** **Clubs → New Club** with its name, email, logo and payment gateway (ToyyibPay or Billplz)
   with that club's credentials, stored encrypted (leave them empty to use the global ones in `.env`).
3. **Grant club admins:** only the super admin can. **Members → Add member** for the club with **Club Role** set to
   **Admin** (or edit an existing member). Give each club **at least two admins** so there is always a second person
   to approve spending and bank changes.
4. **Set the fees:** **Job levels** (the club starts with one, "Ahli"; add the club's own grades if fees differ by
   grade), then **Fee Rates** (monthly fee per job level), **Discounts** if any, and **Expense categories**.
5. **Billing settings:** which plans members may choose (monthly, quarterly, yearly), the default plan, and who pays
   the gateway fee.
6. **Bank / DuitNow details** (Billing settings), for the free transfer option. Every change, the first one included,
   waits until a **second admin** (or the super admin) approves it; members see the new details only after that.
7. **Add members** one by one or by CSV import. Each gets a welcome email with a temporary password, and chooses
   their own password at their first sign-in.
8. **Send a test email:** log out and use **Forgot password?** on the login page with your own email. If it doesn't
   arrive, see [emails not sent](#emails-are-not-sent).
9. **Try a payment** with the gateway's sandbox before going live (for ToyyibPay follow
   [SANDBOX-TEST.md](SANDBOX-TEST.md) on a staging copy), and check the pre-launch list in
   [option-a-report.md](option-a-report.md#before-go-live-checklist).

The [user guide](USER-GUIDE.md) covers each of these screens in detail.

### Post-install security checks

Run these from your own computer (replace `club.example.com`). Each of the first four must answer **403 or 404**,
never 200:

```bash
curl -I https://club.example.com/.env
curl -I https://club.example.com/vendor/
curl -I https://club.example.com/storage/logs/laravel.log
curl -I https://club.example.com/.git/HEAD
```

A 200 means the domain points at the app folder instead of `public/` ([step 3](#step-3-point-the-domain-at-public)):
fix that before anyone signs in.

Then check:

- `.env` has `APP_DEBUG=false` and `APP_ENV=production` (a wrong URL such as `/nothing-here` shows the plain "page
  not found" page, not a stack trace);
- PHP's `expose_php` is off (cPanel → **MultiPHP INI Editor** → `expose_php = Off`): `curl -I https://club.example.com/`
  shows no `X-Powered-By: PHP/…` line;
- the security headers are there for both a page and a static file:
  `curl -I https://club.example.com/login` and `curl -I https://club.example.com/favicon.ico` both show
  `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN` and `Referrer-Policy`, each **once** (the page
  also shows `Content-Security-Policy` and `Strict-Transport-Security: max-age=31536000`);
- `TRUSTED_PROXIES` is empty unless your host or Cloudflare really puts a proxy in front of PHP;
- every admin account has 2FA on (with `REQUIRE_ADMIN_2FA=true`, the default, they are asked at their next sign-in).

## 6. Backups

### What the app backs up by itself

`php artisan backup:run` runs **every day at 02:15** (Malaysia time) through the cron job of step 12. It writes one zip
to `storage/app/backups/` (outside `public/`, never reachable from the web), named
`<app-name>-YYYYmmdd-HHMMSS.zip`, holding:

- the **database**: for SQLite a consistent copy (`database/database.sqlite`, made with `VACUUM INTO`, safe while the
  site runs); for MySQL a `mysqldump` (`database/database.sql`) when the server has `mysqldump`, otherwise only a
  message telling you to use cPanel → **Backup** for the database;
- the uploaded documents and slips (`storage/app/private/`, encrypted) and the club logos (`storage/app/public/`).

It keeps the newest 14 zips (`--keep=N` to change; only its own `<app-name>-…` zips are ever deleted, and none when a run's
zip has no database, because the copy failed or `mysqldump` isn't available) and records each run in the Audit Log ("System Backup"). Run it by
hand any time: `php artisan backup:run`.

**The zip never contains `.env` or `APP_KEY`.** The documents, slips, welfare details and club gateway keys are
encrypted with `APP_KEY`: **without the same key a restored backup can't be read.** So:

1. **Keep `APP_KEY` separately, off the server**, e.g. in the committee's password manager (copy the whole
   `APP_KEY=base64:...` line from `.env` once, after the install). Keep a private copy of the whole `.env` too.
2. **Download the backups off the server** regularly (File Manager → `storage/app/backups` → Download, at least
   weekly). A backup that only lives on the same server is lost with it.
3. cPanel's **Backup** page (or your host's tool) is a useful second copy of the home folder and MySQL databases.

### Restore

1. Install the same (or a newer) release as in [Path B](#3-path-b-cpanel-hosting), with `.env` holding the **original
   `APP_KEY`** (do not run `key:generate`).
2. Put the site in maintenance mode: `php artisan down`.
3. Unzip the backup somewhere private (not under `public/`), then:
   - SQLite: delete `database/database.sqlite-wal` and `database/database.sqlite-shm` if they exist (left over from the
     old database, they would corrupt the restored one), then copy `database/database.sqlite` over the app's
     `database/database.sqlite`;
   - MySQL: import `database/database.sql` with phpMyAdmin → Import (or restore the cPanel database backup);
   - copy `storage/app/private/` and `storage/app/public/` into the app's `storage/app/`.
4. `php artisan migrate --force` (a newer release may add tables), `php artisan config:cache`, then `php artisan up`.
5. Run `php artisan app:doctor`, log in, and open one document and one slip to confirm they can be read.

Test a restore at least once, on a staging copy.

## 7. Upgrading

**From v2.2.0 on, the super admin can update the portal from the admin** (**Updates** in the sidebar, about 2 minutes;
`php artisan app:update` does the same from the Terminal). A site on an older version, or one the Updates page says
cannot update itself, is upgraded with the zip: upload and unzip it next to the app, `php artisan down`,
back up, `cp -a` it over the app folder (keeping `.env` and `storage/`), restore cPanel's PHP-version lines in
`public/.htaccess`, delete the files the CHANGELOG lists as removed, `migrate --force`, `optimize:clear` + `optimize`,
`php artisan up`, then `app:doctor`. The step-by-step version (including the cPanel File Manager steps) is in
[RELEASE.md](RELEASE.md#2-upgrade-a-site).

### What the in-app updater needs

The Updates page checks all of this and, if something is missing, shows a message you can send to your host. Nothing here
is needed for a site that is only ever upgraded by hand.

- **PHP extensions `zip` and `curl`** (cPanel → **Select PHP Version → Extensions**), and PHP allowed to rename, copy and
  delete files (hosts that list such functions in `disable_functions` cannot update from the admin).
- **A writable app folder, and a writable folder above it.** The new version is unpacked next to the app (for
  `~/clubportal`, in `~/`), and the old version is kept there until the next update. If the parent folder cannot be
  written, the updater falls back to `storage/app/updater/` inside the app, which works only when that is on the same
  disk.
- **Free disk space** of several times the size of the release zip (the Updates page says how much it needs). The check
  reads the whole disk, not your hosting quota, so a full quota can still make an update fail; it is then put back by itself.
- **The portal at the domain root** (`https://club.example.com/`, or a subdomain), not in a subfolder such as
  `https://example.com/club/`. The updater lives at `/update/` and checks the new version through the home page, so
  a subfolder install cannot use it (update those by hand).
- **nginx:** Apache and cPanel need nothing. On nginx the usual `location / { try_files ... /index.php }` rule is
  enough for the portal but `/update/` is its own PHP file (`public/update/index.php`), so add a location that passes it to PHP (the same `fastcgi_pass` as your main PHP location):

  ```nginx
  location ^~ /update/ {
      index index.php;
      location ~ \.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass <your PHP-FPM socket>; }
  }
  ```

  Without it, **Update** ends on a 404 page after the password check. Do not serve any other file under `/update/`.
- Outgoing HTTPS to `github.com` (the release zip comes from this project's GitHub releases; its SHA-256 is checked).
  A host that blocks outgoing connections cannot update from the admin.

### An update that could not be undone

If an update stops part-way, the portal puts the old version back by itself (when the page is closed, the first visit
after 10 minutes does it). In the rare case that this fails too, `/update/` shows **"The update could not be undone by
itself"** and members see the "back in a few minutes" page. Your club's records and uploaded files are untouched. Someone with
access to the site's files (cPanel File Manager with **Show Hidden Files**, or the Terminal) does this by hand; it is
the same list as on that screen:

1. Open the folder the screen names: `.clubportal-old-<version>` in the folder that holds the portal's folder (for
   `~/clubportal`, that is `~/`), or `old-<version>` inside `storage/app/updater/` of the portal. Move everything in it
   **except the file `.clubportal-owner`** (leave that behind) back into the portal's folder, to the same place,
   replacing what is there. What is in its `public` folder goes into the portal's `public` folder.
2. In the portal's `storage/framework` folder, delete the files `updating`, `down` and `maintenance.php`.
3. Open the portal's home page and check that it works.

Do not delete the old-version folder until the portal works. Send the report on that screen (**Copy report**) to
whoever supports the portal if something is unclear. If a database change had started, restore the database copy
the update saved (`storage/app/backups`, see [section 6](#6-backups)) before step 3.

---

## 8. Troubleshooting

Look in `storage/logs/laravel.log` first: it usually names the problem.

### `/install` shows a server error (500)

The database tables don't exist yet. Run `php artisan migrate --force` (step 6), then reload. Also check that
`storage/` and `bootstrap/cache` are writable (step 4) and that `APP_KEY` is set.

### Every page shows "Server setup needed" (503)

The server can't run the app at all, and the page says why: PHP older than 8.4.1 (choose PHP 8.4 in cPanel →
**MultiPHP Manager** for the domain; after an upload cPanel may need it chosen again, see step 2), or, before the
install, a missing PHP extension (cPanel → **Select PHP Version** → **Extensions**) or a folder PHP can't write to
(`chmod -R 775 storage bootstrap/cache`). Reload once it's fixed.

### Every page shows "Bad Request" (400)

The **`APP_URL` host is wrong** (or the site is opened at another address). In production the app answers only to
`APP_URL`'s host and its subdomains and refuses every other `Host` with a 400 error, `/install` included; this is
not written to the log. Fix `APP_URL` to the exact address in the browser bar (with `https://`), then run
`php artisan config:cache`.

### Every page shows a server error (500)

- A cached configuration from before an `.env` change: run `php artisan config:cache` again.
- A missing extension: see [section 1](#1-what-you-need) (PHP older than 8.4.1 shows "Server setup needed"
  instead).

### "Page expired" (419) after submitting a form

The session cookie isn't coming back. Usually the site is opened over **http** while `SESSION_SECURE_COOKIE=true`
(the cookie is then never sent): use the `https://` address and turn on Force HTTPS Redirect. Also check that
`APP_URL` matches the address you use and that `SESSION_DOMAIN` is `null` unless you know you need it.

### "Upload too large" (413) when sending slips or documents

The upload is bigger than PHP's `post_max_size`. Raise the limits as in [step 10](#step-10-upload-limits).

### Blank page, or the page has no styling

- `public/build/` is missing or out of date: extract the release zip again (it carries the matching `public/build/`). Until then the sign-in page still works with a plain fallback style, but the pages after it do not.
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
`php artisan schedule:list` shows the reminder job, the 15-minute `payments:reconcile-open-bills` check and their
next runs. The same missing cron job also leaves FPX payments whose callback was lost "Dalam proses"; running
`php artisan payments:reconcile-open-bills` by hand settles them.
