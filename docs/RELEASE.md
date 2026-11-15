# Building a release and upgrading

This page is for **building a release and upgrading a site that is already installed**. For a **first install**
(requirements, document root, `.env`, `migrate --force`, the `/install` wizard, SMTP, cron, MySQL, backups,
troubleshooting) follow [INSTALL.md](INSTALL.md).

The server never needs Node. Front-end assets are built locally or in CI and uploaded with the code.

## 1. Build the release

On a machine or CI runner with PHP 8.4 (`composer.lock` requires it; the host needs 8.4 too), Composer, Node 22
and git, starting from a **clean checkout** of the release:

```bash
git clone https://github.com/amirsubhi/clubcollection.git clubportal && cd clubportal
git checkout <release tag or commit>
composer install --no-dev -o
npm ci && npm run build
```

`npm run build` writes `public/build/` (including `manifest.json`), which Laravel's `@vite` reads.
`public/build`, `public/hot`, `vendor` and `node_modules` are git-ignored, so every release must run this step.

If the release changed any dependency, regenerate the licence files and commit them with the release:

```bash
npm run licenses          # needs vendor/ and node_modules/ installed (as above)
```

It reads `composer.lock` and `package-lock.json` plus each installed package's licence file, and writes
`docs/THIRD-PARTY-LICENSES.md` (list and texts) and `public/licenses.txt` (the texts). `public/licenses.txt` is
committed and uploaded with the release, so every site serves the licences of the code it runs at `/licenses.txt`.

## 2. Upload it

Upload the app, including `vendor/` and `public/build/`, over the existing folder (e.g. `~/clubportal`). **Do not
upload or overwrite:**

- `node_modules/`;
- `public/hot` (a leftover one makes Laravel load assets from a Vite dev server);
- a local `.env` (the server keeps its own);
- `database/*.sqlite` (it would replace the live database);
- `storage/` contents from your machine, in particular `storage/app/.installed` and `storage/app/private`.

Keep the previous release's `public/build/assets` for one release, so tabs that are already open don't ask for chunks
that no longer exist. The web root stays the app's `public/` folder (see [INSTALL.md](INSTALL.md#step-3-point-the-domain-at-public)).

## 3. Upgrade steps on the server

**Back up first** (database, `storage/app/private`, `.env`; see [INSTALL.md](INSTALL.md#6-backups)). Then, in the
app folder:

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

- Re-run `php artisan config:cache` whenever `.env` changes: a cached config ignores `.env`.
- Bump `CACHE_VERSION` in `public/sw.js` for every release (see [PWA service worker](#pwa-service-worker-publicswjs)).
- Read [CHANGELOG.md](../CHANGELOG.md) for new `.env` settings.
- Use the PHP 8.4 binary (e.g. `/usr/local/bin/ea-php84` on cPanel EasyApache) if `php` on the command line is older.
- Never run `php artisan db:seed` on the server: the demo seeder refuses to run outside `local`/`testing`.

> **Until the first real production install (the next release tag):** some columns were added by editing existing
> migrations (there was no live data yet), so a database created by an earlier build must be rebuilt with
> `php artisan migrate:fresh --force` (this deletes all data) instead of `migrate`. After that release, migrations
> are additive only.

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
