# Option A report (Laravel + Inertia + React)

Running log of each Option A step: what was built, review findings, and follow-ups.

## A1 — Front-end foundation

**Built:** Inertia v2 (`inertiajs/inertia-laravel` 2.0.28, `@inertiajs/react` 2.3.29) + React 18 + TypeScript + Tailwind 3 alongside the existing Blade pages. Mockup components, tokens (approved light theme + vivid-lavender dark theme) and BM/EN strings moved into `resources/js`. Nonce-based CSP kept: scripts and styles nonce-only, `style-src-attr 'unsafe-inline'` for Radix/Recharts inline styles. `/dev/ui-kit` proof page (local/testing only). `docs/RELEASE.md` covers build-then-upload for cPanel.

Inertia v2 was chosen over v3 because the v3 React client requires React 19.

**Checker findings fixed:** shared `club` prop (route-bound only), locale `ms` for members and guests, release doc, attachments use `url`/`mime` instead of mockup drawings, unused mockup tokens removed, Radix Dialog nonce verified with no CSP violations, small nits.

**Fable verdict:** GO.

**Follow-ups (non-blocking):**
- Stop sending `cdn.jsdelivr.net` in the CSP for Inertia responses; remove it entirely once Blade pages are migrated.
- Self-host the fonts (removes Google hosts from the CSP; helps the A4 PWA).
- Align `<html lang>` with the shared `locale` (members get `ms`).
- Add a CI workflow: `npm ci && npm run typecheck && npm run build` plus `php artisan test`.
- Add a production-environment test that `/dev/ui-kit` returns 404.
- Identify the one npm package with no declared licence; confirm the CC-BY-4.0 one is `caniuse-lite` (build-time only).
- `docs/RELEASE.md`: document `ASSET_URL` for subdirectory installs and `rm -f public/hot` before upload.
- Share `import_errors` / `import_imported` flashes when the member import page migrates.

**Known, not caused by A1:** one `LedgerTest` failure because this machine runs PHP 8.3 while `composer.lock` needs 8.4.

## A2 — Member pay flow on Inertia

**Built:** Member Home, Payments, Invoice/Receipt, new Review & pay page and status-aware PayResult (Berjaya / Dalam proses with polling / Bayaran belum diterima), all on Inertia with BM default. `pay` is POST-only and reuses an open bill only when its gateway and billed amount still match; changing an unpaid fee's amount clears its bill. Webhooks refuse to mark a fee paid when the paid amount is lower (audited as `payment.underpaid_webhook`). Single-payment routes require the owner, an active membership and an active club. Overdue reminder email links to the review page. Old member Blade views removed.

**Checker findings fixed:** underpayment through a reused bill (major), unverified "no money taken" wording on the failed page (major), overdue counts, inactive member/club access, return page without a bill, multi-club tab, `payment.bill_replaced` audit, `<html lang>`, 44px toggle, URL-encoding.

**Fable verdict:** GO.

**Follow-ups (non-blocking):**
- Lock `pay` per payment (`Cache::lock("pay:{id}")`, re-read the row inside) so two tabs or a retry can't create two bills.
- Payments list, generate-future and the dashboard club query should also respect `club_user.is_active` and `clubs.is_active`.
- Before go-live: webhooks fail closed when neither a paid amount nor `bill_amount` is available.
- Before go-live: when a bill code isn't found, fall back to `reference` (only with a paid amount present) so a member paying an old bill after an amount edit is matched or flagged in-app, not just logged.
- Audit a second successful webhook with a different transaction id on an already-paid fee (double payment → refund).
- Throttle `pay` and `generateFuture`; cap how far ahead `period_start` can go; unique index on (club, user, period_start).
- ToyyibPay sandbox: confirm the callback `amount` unit and whether customer-borne charges are included (alongside the `status` vs `status_id` check).
- Billplz callback for a replaced bill on a per-club-key club fails signature and is retried.
- Confirm with the committee that members should see club-wide fund totals.
- `bill_amount` was added to the existing migration because there is no live data; from v1.0 use additive migrations only.

## A3 — Fund Usage backend + Inertia pages (in review)

**Built:** private polymorphic `attachments` (encrypted at rest with `Crypt::encryptString` on the private `local` disk, `clubs/{id}/attachments/{Y}/{random}.bin`; sha256/size of the original), audited + throttled download route (club admins only); `expenses` gains type/status/approval/void columns (receipt column and public receipt links removed); `welfare_cases` with encrypted personal fields; `FundUsageService` maker-checker workflow; `Expense::counted()` in ledger, dashboards, statistics and the member card with portable date-range bounds; Inertia `Admin/FundUsage/{Index,Create,Show}` and `Admin/Dashboard` (old Blade dashboard kept at `admin.clubs.dashboard.classic`). Old Expenses GET URLs redirect to Fund Usage.

**Deferred:** welfare personal-data purge command (`retention_until` is set); `account_id` / accounts (PR2); emailing admins on void request (audit-only now); `backup:run`.

## A3 — Fund Usage (attachments + maker-checker + admin pages)

**Built:** Private encrypted attachments (sha256, admin-only, throttled, audited previews/downloads; unaudited thumbnail route), expenses extended with type/status/approval/void fields, `welfare_cases` with encrypted personal fields, `FundUsageService` enforcing maker ≠ checker, beneficiary can't approve or return, conflict-of-interest declaration, attachments frozen on submit, return needs a reason, two-person void, row locks on every transition. `Expense::counted()` (approved only, cash date = paid date or invoice date) drives the ledger and exports, both dashboards, statistics and the member card; SQLite-only `strftime` removed there. Inertia pages: Fund Usage list/create/edit/show and the new admin dashboard (Blade dashboard kept as `admin.clubs.dashboard.classic`). Admin authorization now requires an active membership in an active club.

**Checker findings fixed:** file/database consistency on edit and create (major), beneficiary return, certificate sighted by a different active admin, thumbnails no longer audited per render, cash-date totals, inactive admins blocked, upload form pairing, void-requested chip, explicit redirects, plus tests for each.

**Fable verdict:** GO.

**Follow-ups (non-blocking; go-live items marked ★):**
- ★ Thumbnail route serves the full original image without an audit row — serve a real downscaled thumbnail (or audit it) before go-live, since welfare photos can be sensitive.
- Approve should carry `updated_at`/version and reject if the record changed after the approver loaded it.
- Allow a void request to be withdrawn or rejected.
- ★ Back up `APP_KEY` off-server; document key rotation with `APP_PREVIOUS_KEYS`.
- ★ Check cPanel `memory_limit`: a 10 MB file peaks at ~50–60 MB to encrypt/decrypt; consider a lower cap or chunked encryption.
- Check inline PDF preview under the sandbox CSP in Chrome and Safari.
- HEIC iPhone photos are rejected — convert client-side or show a clear message.
- ★ Block club deletion when records exist (cascade wipes approved records and leaves encrypted files on disk).
- Tell admins not to type personal data in description/return/void reason (these reach the audit log and ledger).
- Deferred as planned: welfare purge command, void notifications, `backup:run`, `account_id` (PR2).
- Super admins are not counted in the "needs second approver" banner.

## A4 — Installable web app (PWA)

**Built:** Still the same browser website, now installable ("Add to Home Screen"): web manifest, icons (192, 512, maskable, apple-touch), a minimal service worker that caches only `/build/assets/*` and `/icons/*` (plus the offline page and its icon), an offline fallback page (BM + EN) for failed top-level page loads, and an install tip on member Home (Android button, iOS Share instructions; dismissible, hidden when installed). CSP gains `worker-src 'self'` and `manifest-src 'self'`. `.htaccess` serves `sw.js` and the manifest with `no-cache`. `docs/RELEASE.md` covers the cache version bump and how to remove the service worker.

**Checker:** no blockers or majors. Verified in production mode that no HTML, Inertia JSON, attachment or payment response is ever cached, logged-out start URL goes to login, switching users shows no stale data, and a cache version bump replaces old caches. Minors fixed: offline icon precached, cache writes awaited, orientation lock removed, prompt also on the no-clubs screen, iOS Chrome/Edge get Share instructions.

**Fable verdict:** GO.

**Follow-ups (non-blocking):**
- Test on a real iPhone: an installed app has separate cookies from Safari, so a bank/FPX return may land in Safari logged out (the webhook still records the payment).
- After a deploy, keep the previous build's `public/build/assets` for one release (or reload on chunk-load error) so open tabs don't hit missing chunks.
- `/icons/*` isn't content-hashed; changing an icon needs a `CACHE_VERSION` bump.
- `start_url` is `/my/dashboard` — make sure admin-only users are redirected sensibly.
- Hide the install tip inside WhatsApp/Facebook in-app browsers on iOS (no Add to Home Screen there).
- Absolute paths assume the app is at the domain root, not a cPanel subfolder.
- bfcache shows the old page on Back after logout (pre-existing): send `no-store` on authenticated pages or reload on `pageshow`.

## P1 — Billing frequency and gateway-fee setting

**Built:** per-club billing settings (`allow_monthly/quarterly/yearly`, `default_plan`, `gateway_fee_payer` club|member, `gateway_fee_sen`) on a new Blade page `admin.clubs.billing.edit` for that club's admins and super admins, validated and audited (`club.billing_updated`). ToyyibPay's `billChargeToCustomer` now comes from `ToyyibPayGateway::BILL_CHARGE_TO_CUSTOMER` (club → blank, member → `'0'`; **TODO: confirm semantics in sandbox**); Billplz adds the fee to the bill amount when members pay. Bills store `bill_fee_payer`/`bill_fee_sen`; an open bill is reused while amount and gateway match and keeps its stored fee mode (shown on ReviewPay) even if the setting changes. "Member pays" is disabled for ToyyibPay until `TOYYIBPAY_CHARGE_VERIFIED=true`. ReviewPay shows "Yuran + caj = Jumlah" when members pay. Pay-ahead chooser (1/3/12 months, allowed plans only, default plan, savings hint) creates one fee record per period ending at most 12 months ahead, offers only uncovered start months, and refuses overlaps (also in admin create), plus a unique index on (club, user, period_start). Admin dashboard KPI "Gateway fees this year" sums `fee_absorbed_sen`, set with `paid_via` by the verified webhook. Committee note `docs/payment-options.md`.

**Follow-ups:** confirm `billChargeToCustomer` and whether ToyyibPay's callback `amount` includes the charge (P3 sandbox); the configured fee is what members are shown, ToyyibPay applies its own.

## A5 — Verification and decision summary

### What was verified

| Check | Result |
|---|---|
| End-to-end smoke (`tests/e2e/smoke.mjs`, Playwright/Chromium against `php artisan serve` + fresh SQLite + `E2eSeeder`) | **Pass.** Member: login → Home shows RM 50.00 due → Review & pay → POST pay → redirect to the (faked) ToyyibPay bill → real `/webhook/toyyibpay` callback with the configured token (wrong token → 403) → PayResult "Berjaya" → Home "Semua yuran telah dibayar". Admin A: Fund Usage → expense RM 12.34 with a PNG + PDF → submitted. Admin B: approve with the declaration → "Spent this year" RM 0.00 → RM 12.34. 10 steps, 13 light/dark screenshot pairs (390×844 member, 1366×860 admin); no console errors, CSP violations, failed requests or horizontal overflow. A second run without re-seeding fails and exits 1, as intended. |
| `php artisan test` | 263 tests, 262 pass. The only failure is the known `LedgerTest` date-range test (this machine runs PHP 8.3; `composer.lock` needs 8.4). |
| `npx tsc --noEmit` | Clean. |
| `npm run build` (`vite build`) | Clean, 3,607 modules, ~5 s. |
| Lighthouse 12, mobile, member Home logged in (session cookie via `--extra-headers`) | Performance **69**, Accessibility **100**, Best practices **100**, SEO 91. FCP 4.1 s, LCP 4.6 s, TBT 250 ms, CLS 0. |

**Bug found by the smoke test and fixed (pre-existing, not caused by Option A):** real gateway callbacks to
`/webhook/toyyibpay` and `/webhook/billplz` were rejected with **419 (CSRF)**. The routes excluded
`VerifyCsrfToken`, but Laravel 13's web group runs its parent `PreventRequestForgery`, and exclusion matches by
class, so nothing was excluded. Feature tests never saw it because the CSRF middleware skips itself under
PHPUnit. In production no payment would have been marked paid automatically. Fixed in `routes/web.php`;
`tests/Feature/WebhookCsrfExemptionTest.php` checks the resolved middleware list and fails without the fix.

**Lighthouse caveats:** measured on `php artisan serve`, which sends no compression, under Lighthouse's simulated
slow 4G. The two biggest costs are text compression (≈367 KiB savings available) and the 464 KB main `app` chunk
(154 KB gzipped; ≈263 KiB unused on Home). On Apache with `mod_deflate` the score should be higher, but that was
not measured. The SEO point lost is a missing `<meta name="description">`, which doesn't matter for a logged-in page.

**Not verified here:** a real ToyyibPay sandbox bill and callback (bill creation is faked with `Http::fake`;
the webhook itself is real), a real cPanel host, and a real iPhone.

**E2E support added (local only):** `Database\Seeders\E2eSeeder` (throws outside local/testing) and
`App\Providers\E2eFakeGatewayServiceProvider` (active only when `APP_ENV=local` **and** `E2E_FAKE_GATEWAY=1`,
never when `isProduction()`; it can also move uploads to `E2E_PRIVATE_ROOT`). Both guards are covered by
`tests/Feature/E2eSupportGuardTest.php`. See `tests/e2e/README.md` for how to run it.

### Metrics

- **Time:** A1–A4 were committed between 07:37 and 11:17 UTC on 2026-11-15 (about 4 hours of agent wall-clock time
  after the mockup, including the review gates). A5 took about another hour.
- **Lines of code** (`git diff ab0cd3d^..HEAD`, A1–A4, before A5): 148 files, +16,518 / −1,263, of which
  - TypeScript/TSX: **+6,278** (pages 2,545; app components 2,171; UI primitives 674, mostly moved from the mockup; lib 467; types 282; entry 68);
  - PHP app code: **+2,703 / −294** (app/ 2,703 added; routes, migrations, seeders, factories and bootstrap another +246);
  - Blade views: +41 / **−836** (old member pages removed);
  - tests: **+2,032 / −126**;
  - lockfiles +4,572 (generated), CSS/JS/config/PWA files +503, docs +133.
  - A5 adds about 620 lines: the smoke script, its README, the seeder, the provider, 2 test files and the route fix.
- **Reuse of the existing backend:**
  - Kept unchanged: `PaymentGatewayManager`, `AuditService`, `Club`, `User`, `TwoFactorChallengeController` and the 2FA middleware.
  - Small changes: `ToyyibPayGateway` (+4/−2), `BillplzGateway` (+6), `WebhookController` (+26, the underpayment guard), `Payment` (+24), `LoginController` (+10, Inertia logout), `SecurityHeaders` (+57/−6, nonce CSP for Vite).
  - New backend: Fund Usage (attachments, maker-checker service, welfare cases, presenters/stats, Inertia middleware), about 2,000 lines of PHP.
  - Payments, gateways, webhooks, auth/2FA, audit and the data model were reused rather than rewritten. The existing app had about 4,400 lines of PHP in `app/` before Option A and about 6,800 now.
- **Tests:** 153 before Option A → 257 after A4 → **263** now.
- **Build output:** `public/build` 1.3 MB on disk across 40 assets. Main `app` JS 464 KB (154 KB gzip); `Charts` (Recharts) 403 KB (111 KB gzip), loaded only on pages with charts (not member Home); CSS 46 KB (9 KB gzip); page chunks 4–29 KB each.

### cPanel deploy (summary; details in `docs/RELEASE.md`)

1. Build locally or in CI: `composer install --no-dev -o`, `npm ci && npm run build`. **The server never needs Node.**
2. Upload the app including `vendor/` and `public/build/`. Leave out `node_modules/` and `public/hot`.
3. On the server: `php artisan migrate --force`, `config:cache`, `route:cache`, `view:cache`.
4. Bump `CACHE_VERSION` in `public/sw.js` every release.
5. `.htaccess` needs Apache `mod_headers` (standard on cPanel) for the `sw.js`/manifest `no-cache` headers.
6. Keep the previous release's `public/build/assets` for one release, so open tabs don't hit missing chunks.
7. Set `TOYYIBPAY_WEBHOOK_SECRET` (and `APP_KEY`) on the host. `E2E_FAKE_GATEWAY` must never be set there; the provider also refuses to run unless `APP_ENV=local`.

### Before go-live checklist

All ★ and go-live items from A1–A4 plus what A5 and Fable's final review found. **Must-do before go-live** items are marked 🔴; the rest can follow soon after launch or are optional.

**🔴 Must-do before go-live**
- [ ] ToyyibPay and Billplz **sandbox** runs end to end on the real host, including a real callback after the webhook CSRF fix.
- [ ] Gateway secrets set on the host: `TOYYIBPAY_WEBHOOK_SECRET` and `BILLPLZ_X_SIGNATURE_KEY` (placeholders are refused).
- [ ] Host WAF/ModSecurity and HTTPS/www redirects don't block or 301 the gateways' server-to-server POSTs.
- [ ] `APP_KEY` backed up off-server; `storage/app/private` (outside `public_html`) included in backups with the database.
- [ ] PHP 8.4 on the host; align `composer.json` (`php ^8.3`) with the lock so a wrong host fails loudly.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, HTTPS forced, `SESSION_SECURE_COOKIE=true`.
- [ ] Webhooks fail closed; per-payment pay lock (cache driver `database`/`file`, not `array`); unique index (club, user, period_start).
- [ ] A way to reconcile missed callbacks (manual "re-check bill status" or a scheduled job); cron for the scheduler.

Full list by area:

**Payments**
- [ ] ToyyibPay **sandbox** run end to end: confirm the callback `amount` unit (ringgit vs sen), whether customer-borne charges are included, and `status` vs `status_id`.
- [ ] Re-test a real sandbox callback on the host after the A5 **webhook CSRF fix** (it previously got 419).
- [ ] `TOYYIBPAY_WEBHOOK_SECRET` set on the host to a random value (placeholders are refused).
- [ ] Lock `pay` per payment (`Cache::lock("pay:{id}")`, re-read the row inside).
- [ ] Webhooks **fail closed** when neither a paid amount nor `bill_amount` is available.
- [ ] **Reference fallback** when a bill code isn't found (only with a paid amount present).
- [ ] Audit a second successful webhook with a different transaction id on an already-paid fee (double payment).
- [ ] Throttle `pay`/`generateFuture`, cap `period_start`, unique index (club, user, period_start).
- [ ] Billplz callback for a replaced bill on a per-club-key club.
- [ ] **Real iPhone payment return** from FPX/bank in the installed PWA (separate cookie jar from Safari).

**Data and files**
- [ ] **Back up `APP_KEY`** off-server; document rotation with `APP_PREVIOUS_KEYS` (attachments and welfare fields are encrypted with it).
- [ ] Check cPanel **`memory_limit`** (a 10 MB file peaks at ~50–60 MB to encrypt/decrypt).
- [ ] Serve real downscaled **thumbnails** (or audit the thumbnail route); today it returns the full original.
- [ ] **Block club deletion** when records exist (the cascade wipes approved records and leaves encrypted files on disk).
- [ ] From v1.0, use additive migrations only.
- [ ] Tell admins not to type personal data in description, return or void reasons.

**Hosting**
- [ ] `APP_ENV=production` and `APP_DEBUG=false` on the host (the e2e guards rely on `APP_ENV`).
- [ ] **PHP 8.4 on the host** (`composer.lock` requires it; this machine's PHP 8.3 is why `LedgerTest` fails).
- [ ] Apache `mod_headers` (and `mod_deflate` for compression, the biggest Lighthouse win) enabled.
- [ ] Send **`no-store` on authenticated pages** (bfcache shows the old page on Back after logout).
- [ ] Keep the previous build's assets for one release.
- [ ] Install at the domain root, or fix absolute paths and set `ASSET_URL` for a subfolder.
- [ ] Add a production test that `/dev/ui-kit` returns 404.
- [ ] Add a CI workflow (`npm ci && npm run typecheck && npm run build`, `php artisan test`). Optionally add the e2e smoke as a nightly job.

**Product and decisions**
- [ ] Confirm with the committee that members may see club-wide fund totals.
- [ ] Check inline PDF preview under the sandbox CSP in Chrome and Safari; decide how to handle HEIC photos.
- [ ] Optional: self-host fonts, which removes Google hosts from the CSP and helps offline/PWA.
- [ ] Optional: split the 464 KB main chunk.

### Option A vs Option B — what the evidence says

The Option A slice was built and verified end to end on the existing Laravel backend:
- It covers login, the member pay flow with a verified webhook, Fund Usage with encrypted multi-file upload and two-person approval, the admin dashboard, and the PWA.
- Payments, gateways, webhooks, auth/2FA, audit logging and the data model were reused almost unchanged.
- The new work was mainly front-end (about 6,300 lines of TSX) plus the Fund Usage domain (about 2,000 lines of PHP), backed by about 110 new tests.
- Deployment stays the same as today on cPanel: PHP only, with prebuilt assets uploaded and no Node process on the server.

The weaker points:
- Mobile Lighthouse performance was 69 on an uncompressed dev server.
- Two stacks now coexist (the remaining Blade pages still load Bootstrap from a CDN).
- The A5 smoke run found a pre-existing webhook bug that unit tests could not catch, so the go-live checklist above matters more than the stack choice.

Option B (AdonisJS) would mean re-implementing the same gateway, webhook, audit and auth logic in a new codebase and running a Node app under cPanel Passenger. Nothing in A1–A5 required that. The plan says to reconsider B only if A falls short on cPanel or on ease of editing. The first can only be confirmed on the real host (PHP 8.4, `mod_headers`/`mod_deflate`, memory limit). The second is the user's call after editing a page or two. **The decision is the user's.**

### Final review (Fable) — advisory recommendation

Verdict on A5: GO. Hardening applied before commit: the private `local` disk is no longer served over HTTP (`'serve' => false`), with a test that the `storage.local*` routes stay absent.

Advice to the owner: continue with Option A. The slice reused payments, webhooks, auth/2FA and the audit log almost untouched, deploys as plain PHP with no Node on the server, and grew the suite from 153 to 263 tests. The one serious defect found (webhooks getting 419) was pre-existing and unrelated to the stack; a rewrite would re-open that kind of money-path risk across the whole codebase and add Node-on-cPanel hosting risk. Option B only becomes worth it if the real host can't run PHP 8.4, or if the owner finds the React pages hard to edit after trying one or two. Suggested order: the 🔴 must-dos and a real-host sandbox payment first, then migrate the remaining Blade pages module by module, retiring Bootstrap last. Re-measure Lighthouse on the host with compression on; the 69 was measured uncompressed.

### P1 final review (Fable): GO

Applied before commit: ToyyibPay "club absorbs" keeps the pre-P1 `billChargeToCustomer=1` until the sandbox run confirms how blank vs `1` is read (avoids members being charged RM1 more than shown).

Follow-ups (non-blocking):
- P3 sandbox: first item is confirming `billChargeToCustomer` values and the callback `amount` unit; then decide whether to enable `TOYYIBPAY_CHARGE_VERIFIED`.
- Columns and the unique index were added by editing existing migrations (no live data); any existing database needs `migrate:fresh`.
- Admin fee `store` overlap check has no lock/transaction; two concurrent creates could overlap.
- Review → pay race: if an admin flips the fee setting between page load and POST, the charge can differ from what was shown; post the expected total and reject a mismatch.
- Fee KPI and the member-pays line use the configured fee (default RM1), not the gateway's real fee (Billplz RM1.25 + SST); label as "estimated".
- Pay-ahead ignores discounts and uses today's rate for all months.
