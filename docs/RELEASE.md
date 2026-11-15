# Release build (cPanel)

The server never needs Node. Front-end assets are built locally or in CI and uploaded with the code.

1. On a machine or CI runner with PHP 8.4 (`composer.lock` requires it; the host needs 8.4 too) and Node 22:
   ```bash
   composer install --no-dev -o
   npm ci && npm run build
   ```
   `npm run build` writes `public/build/` (including `manifest.json`), which Laravel's `@vite` reads.
2. Upload the app, including `vendor/` and `public/build/`. Build from a clean checkout and do not upload
   `node_modules/`, `public/hot` (a leftover one makes Laravel load assets from a Vite dev server), a local `.env`,
   `database/*.sqlite` or `storage/app/.installed` (that marker would disable the installer).
   - **Web root must be `public/`.** Either point the (sub)domain's document root at the app's `public/` folder
     (cPanel → Domains → Manage → Document Root), or upload the app **outside** `public_html` (e.g. `~/clubportal`)
     and make `public_html` a symlink to its `public/` folder
     (`rm -rf ~/public_html && ln -s ~/clubportal/public ~/public_html`, on an empty `public_html` only).
     Never expose the app root: `.env`, `storage/` and `vendor/` must not be reachable from the web.
   - Permissions: `chmod -R 775 storage bootstrap/cache` (the web server writes there).
3. **First install only:**
   1. Before the upload (or straight after, before anyone can reach the site), create the server's `.env`:
      copy `.env.example` to `.env`, run `php artisan key:generate`, and set `APP_ENV=production`, `APP_DEBUG=false`
      and `APP_URL` to the exact `https://` address members will use. Fill in the rest from the block below, including a random `INSTALL_TOKEN`
      (16+ characters) and the `MAIL_*` settings.
   2. Run `php artisan storage:link` once (club logos are served from `public/storage`).
   3. Run **`php artisan migrate --force`**. Sessions and cache are stored in the database (`SESSION_DRIVER=database`,
      `CACHE_STORE=database`), so `/install` answers with a 500 error until the tables exist. With SQLite this also
      creates `database/database.sqlite`. The installer checks for the `pdo_sqlite` extension even when you use MySQL.
   4. **Run the installer at `https://<domain>/install` straight after the upload.** Without a token the
      installer is disabled; with one, whoever reaches `/install` first still needs the token. The installer runs the
      migrations, writes `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true` (for an https URL),
      `LOG_LEVEL=warning`, creates the super admin and `storage/app/.installed`, and blanks the token. Never run
      `php artisan db:seed` on the server: the demo seeder refuses to run outside `local`/`testing`.
   5. Run `php artisan config:cache` (and again after **any** later `.env` change: a cached config ignores `.env`).
   6. Add the scheduler cron job (cPanel → Cron Jobs, every minute):
      ```
      * * * * * cd /home/<user>/clubportal && php artisan schedule:run >> /dev/null 2>&1
      ```
      It runs the overdue-fee reminders daily at 08:00 (`payments:send-overdue-reminders`). Use the PHP 8.4 binary
      (e.g. `/usr/local/bin/ea-php84` on cPanel EasyApache) if `php` on the command line is older. No queue worker is
      needed: emails are sent during the request.
4. Every release, on the server: `php artisan migrate --force`, then `php artisan config:cache`, `php artisan route:cache`
   and `php artisan view:cache`. Re-run `php artisan config:cache` whenever `.env` changes.

`public/build`, `public/hot` and `node_modules` are git-ignored, so every release must run step 1.

> **Before v1.0:** some columns were added by editing existing migrations (there was no live data yet), so a database
> created by an earlier build must be rebuilt with `php artisan migrate:fresh --force` (this deletes all data) instead
> of `migrate`. From v1.0, migrations are additive only.

## Production `.env`

The values that matter for security. Generate secrets with `php -r "echo bin2hex(random_bytes(32));"`.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://club.example.com       # every link and gateway callback URL is built from this
APP_KEY=base64:...                     # php artisan key:generate; back it up off-server
LOG_LEVEL=warning

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true             # https only
CACHE_STORE=database                   # pay lock and rate limits; never "array"

INSTALL_TOKEN=<random, 16+ characters> # first install only; the installer blanks it

TRUSTED_PROXIES=                       # proxy IPs if the host puts one in front of PHP

TOYYIBPAY_SANDBOX=false
TOYYIBPAY_SECRET_KEY=...               # or per club in the admin panel
TOYYIBPAY_CATEGORY_CODE=...
TOYYIBPAY_WEBHOOK_SECRET=<random, 32+ characters>   # each bill's callback carries its own HMAC of it
TOYYIBPAY_VERIFY_CALLBACK=true         # confirm paid callbacks with getBillTransactions
TOYYIBPAY_CHARGE_VERIFIED=false        # true only after the sandbox run

BILLPLZ_SANDBOX=false
BILLPLZ_X_SIGNATURE_KEY=...            # from Billplz (or per club in the admin panel); placeholders are refused

MAIL_MAILER=smtp                       # without real SMTP no email goes out (welcome emails with the
MAIL_SCHEME=smtps                      # temporary password, receipts, slip rejections, admin notices)
MAIL_HOST=mail.example.com             # cPanel → Email Accounts → Connect Devices shows these
MAIL_PORT=465                          # or 587 with MAIL_SCHEME=smtp (STARTTLS)
MAIL_USERNAME=noreply@example.com
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="Club Portal"
```

In production every generated URL is built from `APP_URL` (host and scheme), and the app answers only to
`APP_URL`'s host and its subdomains (other `Host` headers are refused), so a forged `Host` or `X-Forwarded-Host`
can't redirect gateway callbacks or email links.

> **Get `APP_URL` right.** With `APP_ENV=production`, a wrong `APP_URL` host makes the app refuse every request,
> `/install` included. Use the exact address members type (with `https://`).

## PHP upload limits

Members upload up to 3 transfer slips of 10 MB each, and Fund Usage records take several documents. Set these in
cPanel (MultiPHP INI Editor) or `.user.ini`:

```ini
upload_max_filesize = 12M
post_max_size = 32M
```

`post_max_size` caps one save, not one file. 32M fits a member's 3 slips of 10 MB each, and about 3 full-size
documents per Fund Usage save. A record can hold up to 10 documents of 10 MB, so either add them in batches (save
the draft with a few, then edit and add more) or raise `post_max_size` to about `110M` to allow all 10 at once.

A request over `post_max_size` reaches Laravel with no fields at all; the app answers with a bilingual
"Fail terlalu besar / Upload too large" page (`resources/views/errors/413.blade.php`), and the member upload form
already refuses files over 10 MB on the device.

## PWA service worker (`public/sw.js`)

The site is installable ("Add to Home Screen") through `public/manifest.webmanifest` and `public/sw.js`.
The service worker caches only `/build/assets/*` and `/icons/*` (cache-first); pages, Inertia JSON,
attachments, payments and webhooks always go to the network, and a failed page load shows `public/offline.html`.

- **Every release:** bump `CACHE_VERSION` in `public/sw.js` (e.g. `"v1"` → `"v2"`). The changed file makes
  browsers install the new worker, and its `activate` step deletes the old `cp-*` caches. Vite file names are
  content-hashed, so a missed bump never serves wrong code, but old assets then stay cached until the next bump.
- **Caching headers:** `public/.htaccess` sends `Cache-Control: no-cache` for `sw.js` and `manifest.webmanifest`
  (needs Apache `mod_headers`, standard on cPanel). Check after deploy:
  `curl -sI https://<domain>/sw.js | grep -i cache-control`.
- **Registration:** `resources/js/lib/pwa.ts` registers `/sw.js` only in production builds (`npm run build`) on
  HTTPS (or `localhost`); `npm run dev` never registers it.
- **Removing it (kill switch):** replace `public/sw.js` with the snippet below and deploy. Each browser picks it
  up on its next visit, clears the caches and unregisters itself. Also remove the `registerServiceWorker()` call
  in `resources/js/app.tsx` and rebuild. Do not simply delete `sw.js`: an already-installed worker keeps running
  until it can fetch a replacement.
  ```js
  self.addEventListener("install", () => self.skipWaiting());
  self.addEventListener("activate", (event) => {
    event.waitUntil((async () => {
      for (const key of await caches.keys()) await caches.delete(key);
      await self.registration.unregister();
      for (const client of await self.clients.matchAll({ type: "window" })) client.navigate(client.url);
    })());
  });
  ```

## Welfare personal data

Welfare cases store the beneficiary's details encrypted and set a retention date (`welfare_cases.retention_until`,
7 years after the date the committee approved it), and the welfare form tells admins the data is deleted after about 7 years. **Nothing
purges it automatically yet**: the purge command is a deferred follow-up (see `docs/option-a-report.md`). Until it
exists, the super admin must remove expired welfare personal data by hand.
