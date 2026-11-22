# Building a release and upgrading

This page is for **building a release and upgrading a site that is already installed**. For a **first install**
(requirements, document root, `.env`, `migrate --force`, the `/install` wizard, SMTP, cron, MySQL, backups,
troubleshooting) follow [INSTALL.md](INSTALL.md).

The server never needs Composer, Node or git. Every release is one zip, `clubportal-<version>.zip`, with `vendor/` and
the built front end (`public/build/`) already inside.

## 1. Get the release zip

**Normally: download it.** Pushing a tag `v*` (e.g. `v0.0.2`) runs `.github/workflows/release.yml`: it runs the tests
on PHP 8.4, builds the zip with `scripts/build-release.sh` and attaches `clubportal-<version>.zip` and
`clubportal-<version>.zip.sha256` to the GitHub Release for that tag. Download both and check the zip:

```bash
sha256sum -c clubportal-0.0.2.zip.sha256          # macOS: shasum -a 256 -c clubportal-0.0.2.zip.sha256
```

On Windows, `certutil -hashfile clubportal-0.0.2.zip SHA256` prints the hash to compare with the `.sha256` file.

**Or build it yourself** on a machine with git, PHP 8.4 (`composer.lock` requires it) with Composer, Node 22, `zip` and
`sha256sum`:

```bash
git clone https://github.com/amirsubhi/clubcollection.git clubportal && cd clubportal
scripts/build-release.sh 0.0.2 v0.0.2      # <version> [git ref, default HEAD]
```

The script exports the git ref with `git archive` into a temporary folder (never your working tree, so a local
`.env`, the demo SQLite database, `storage/` contents or `node_modules` can't get in), then runs
`composer install --no-dev --optimize-autoloader`, `npm ci && npm run build` and the licence generator
(`npm run licenses`, see below). It then removes what the server doesn't need:

- dropped: `node_modules/`, `tests/`, `mockup/`, `scripts/`, `.github/`, `phpunit.xml`, `public/hot`,
  every `.env*` except `.env.example`, every `*.sqlite`, everything under `storage/` and `bootstrap/cache/` except
  the empty folders and their `.gitignore` files;
- kept: `app/`, `bootstrap/`, `config/`, `database/` (migrations and seeders), `lang/`, `public/` (with `build/` and
  `licenses.txt`), `resources/`, `routes/`, `vendor/`, `artisan`, `composer.json`/`composer.lock` (Laravel reads
  `composer.json`), the docs and `CHANGELOG.md`. The front-end sources and their configs (`resources/js`,
  `vite.config.ts`, `package.json`...) stay too: they are small, not reachable from the web, and show what the bundle
  was built from.

It writes `release.json` (`version`, `commit`, `built_at`, `php_requirement`) into the app folder, which
`php artisan app:doctor` shows as **Version** (`config('app.version')`; a git checkout shows `dev`). The result is
`dist/clubportal-<version>.zip` (one top-level folder `clubportal-<version>/`) and its `.sha256`; the script refuses a
zip that contains `.env`, `tests/`, `node_modules/`, a `.sqlite` file or `storage/` contents. `dist/` is git-ignored.
If PHP on the build machine is older than 8.4, `COMPOSER_FLAGS=--ignore-platform-req=php scripts/build-release.sh ...`
gets past Composer's check for a test build; release builds use PHP 8.4 (CI does).

### Licence files

The script regenerates `docs/THIRD-PARTY-LICENSES.md` and `public/licenses.txt` from `composer.lock` and
`package-lock.json` (plus each installed package's licence file) and puts the fresh ones in the zip, so every site
serves the licences of the code it runs at `/licenses.txt`. If they differ from the committed ones it prints a
warning: run `npm run licenses` (needs `vendor/` and `node_modules/` installed) and commit both files.

## 2. Upgrade a site with the zip

> **`v1.0.0` (the March 2026 GitHub release) is superseded and must not be upgraded in place.** Its migrations were
> later edited (there was no live data yet), so `migrate` cannot bring a `v1.0.0` database, or one from any build
> before the next release tag, up to date. Install the next release tag fresh (see [INSTALL.md](INSTALL.md)), or
> rebuild with `php artisan migrate:fresh --force` (this deletes all data), and re-enter the club's data. From the first
> real install onwards, every schema change ships as a **new, additive migration**: never edit or delete a migration
> that a release has already shipped, so `migrate --force` always upgrades in place.

Read [CHANGELOG.md](../CHANGELOG.md) first: new `.env` settings, and the **files a release removes** (see step 4).
The app folder below is `~/clubportal`; use the PHP 8.4 binary (e.g. `/usr/local/bin/ea-php84` on cPanel EasyApache)
if `php` in the Terminal is older. Do every step in cPanel → **Terminal**.

**Keep the downtime short.** While the site is down, payment gateway callbacks get a 503 too. ToyyibPay payments made
in that window are picked up by the 15-minute `payments:reconcile-open-bills` check after `php artisan up`; Billplz
retries its callback per its own retry policy. Upload the zip (step 1) *before* going down.

1. **Upload the zip** (site still up). cPanel → **File Manager** → your home folder (the one that *contains*
   `clubportal`) → **Upload** `clubportal-<version>.zip`, then in the Terminal:
   ```bash
   cd ~ && sha256sum clubportal-<version>.zip          # compare with the .sha256 from the release
   unzip -q clubportal-<version>.zip                    # creates ~/clubportal-<version>/ next to ~/clubportal/
   ```
2. **Maintenance mode, then back up** (in this order, so no payment or upload lands after the backup):
   ```bash
   cd ~/clubportal
   php artisan down --secret="<a long random word>"
   php artisan backup:run
   cp -a public/.htaccess ~/htaccess-before-<version>   # cPanel MultiPHP may have written PHP-version lines into it
   ```
   Members see the maintenance page; opening `https://<domain>/<the secret>` once lets your browser through. Download
   the new zip from `storage/app/backups` (database and uploaded files; see [INSTALL.md](INSTALL.md#6-backups)), and
   keep your private copy of `.env` / `APP_KEY`.
3. **Copy the new release over the site:**
   ```bash
   cp -a ~/clubportal-<version>/. ~/clubportal/
   rm -f ~/clubportal/bootstrap/cache/*.php     # drop the old release's cached config/packages before any artisan call
   chmod -R 775 ~/clubportal/storage ~/clubportal/bootstrap/cache   # cp -a resets folder modes to the zip's
   ```
   This merges folder by folder and overwrites changed files. The zip has **no `.env`**, no `public/hot` and only empty
   `storage/` and `bootstrap/cache/` folders, so your `.env`, uploaded files, backups and `storage/app/.installed`
   stay as they are. **Never delete `.env` or `storage/`**, and never "delete the old folder first".
   - Don't use File Manager's **Move**/**Copy** for this: it does not reliably merge into existing folders and can
     skip or replace whole folders. If you have no Terminal, ask the host to run the `cp -a` line for you.
   - The web root stays the app's `public/` folder (see [INSTALL.md](INSTALL.md#step-3-point-the-domain-at-public)).
4. **Restore the PHP version line and delete removed files:**
   - `diff ~/htaccess-before-<version> ~/clubportal/public/.htaccess`. If the old file had cPanel's lines (a block
     starting `# php -- BEGIN cPanel-generated handler`), copy that block back into the new `public/.htaccess`, or
     choose PHP 8.4 for the domain again in cPanel → **MultiPHP Manager**. Without it the site may run on the host's
     default (older) PHP.
   - Delete every file the release's CHANGELOG entry lists as **removed**, in particular anything under
     `app/Console/Commands/` (an old command class left behind is still loaded and scheduled). `cp -a` never deletes.
5. **Database and caches:**
   ```bash
   php artisan migrate --force
   php artisan optimize:clear
   php artisan optimize          # config, routes, views and events cached again
   ```
6. **Bring the site back and check it:**
   ```bash
   php artisan up
   php -v                        # 8.4
   php artisan app:doctor        # every row PASS or an understood WARN; "Version" shows the new release
   rm -rf ~/clubportal-<version> ~/clubportal-<version>.zip
   ```
   Open the site in the browser too: `app:doctor` checks the command-line PHP, the website's version is set in
   MultiPHP Manager. `app:doctor` is read-only and exits with code 1 while any row is FAIL; each row has a one-line
   fix (see [INSTALL.md step 13](INSTALL.md#step-13-check-the-server)).

If something goes wrong before step 6, the site is still in maintenance mode: fix it, or roll back. The real rollback is
the **database restore** from the step 2 backup (see [INSTALL.md](INSTALL.md#restore)), because migrations are not
reversed; then copy the previous release's zip over the site the same way and run `rm -f bootstrap/cache/*.php`.
Copying an older zip does not remove files the newer release added, so delete those (see its CHANGELOG entry) too.

A one-command `php artisan app:update` (download, verify, extract, migrate) from the original plan is **still
deferred**; until then the steps above are the upgrade path.

Also:

- Re-run `php artisan config:cache` (or `optimize`) whenever `.env` changes: a cached config ignores `.env`.
- Bump `CACHE_VERSION` in `public/sw.js` for every release before tagging it (see
  [PWA service worker](#pwa-service-worker-publicswjs)).
- With an old or missing `public/build`, the classic admin pages load Bootstrap and Chart.js from jsDelivr and the
  app shows system fonts. The zip always carries the matching build.
- Install at the domain root (or a subdomain). Fonts and Bootstrap Icons are referenced as `/build/assets/...`, so a
  subfolder install would not find them.
- Never run `php artisan db:seed` on the server: the demo seeder refuses to run outside `local`/`testing`.

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

TRUSTED_PROXIES=                       # empty unless a proxy / Cloudflare really sits in front of PHP

REQUIRE_ADMIN_2FA=true                 # admins must turn on 2FA (never false on a live site)
HSTS_MAX_AGE=31536000                  # 1 year
HSTS_INCLUDE_SUBDOMAINS=false          # true only if every subdomain is HTTPS
HSTS_PRELOAD=false                     # true only if you own the domain and submit it to the preload list

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

The site is installable ("Add to Home Screen") through the routed manifest `/app.webmanifest` (portal name,
brand colour and icons from Portal settings; `AppManifestController`, sent with `Cache-Control: public, max-age=300`)
and `public/sw.js`. The static `public/manifest.webmanifest` stays as a fallback with the defaults. The service
worker caches only `/build/assets/*`, `/icons/*` and the hashed app icons in `/storage/portal/icons/*` (cache-first;
a new logo or colour gives new file names); the manifest, pages, Inertia JSON, attachments, payments and webhooks
always go to the network, and a failed page load shows `public/offline.html`.

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
