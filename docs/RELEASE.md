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
