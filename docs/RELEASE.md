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
3. On the server: `php artisan migrate --force`, then `php artisan config:cache`, `php artisan route:cache` and `php artisan view:cache`.

`public/build`, `public/hot` and `node_modules` are git-ignored, so every release must run step 1.

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
