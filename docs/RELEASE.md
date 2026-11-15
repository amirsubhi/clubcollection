# Release build (cPanel)

The server never needs Node. Front-end assets are built locally or in CI and uploaded with the code.

1. On a machine or CI runner with PHP 8.3+ and Node 22:
   ```bash
   composer install --no-dev -o
   npm ci && npm run build
   ```
   `npm run build` writes `public/build/` (including `manifest.json`), which Laravel's `@vite` reads.
2. Upload the app, including `vendor/` and `public/build/`. Do not upload `node_modules/` or `public/hot`
   (a leftover `public/hot` makes Laravel load assets from a Vite dev server).
3. **First install only:** before the upload, put a random `INSTALL_TOKEN` in the server's `.env` (see the block
   below), then **run the installer at `https://<domain>/install` straight after the upload**. Without a token the
   installer is disabled; with one, whoever reaches `/install` first still needs the token. The installer writes
   `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true` (for an https URL), `LOG_LEVEL=warning`,
   creates the super admin and `storage/app/.installed`, and blanks the token. Never run `php artisan db:seed` on
   the server: the demo seeder refuses to run outside `local`/`testing`.
4. On the server: `php artisan migrate --force`, then `php artisan config:cache`, `php artisan route:cache` and `php artisan view:cache`.

`public/build`, `public/hot` and `node_modules` are git-ignored, so every release must run step 1.

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
BILLPLZ_X_SIGNATURE_KEY=...            # from Billplz; placeholders are refused
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
