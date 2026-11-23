# Option A report (Laravel + Inertia + React)

Running log of each Option A step: what was built, review findings, and follow-ups.

**Status (22 Nov 2026):** A1–A5, P1–P3a, S1, R1–R7, G1–G3 and D1 (MariaDB / MySQL readiness) are done. The app
targets MariaDB 10.11 on cPanel and the suite passes on SQLite, MariaDB 10.11 and MySQL 8.0. **Next:** the 🔴
must-dos in the [before go-live checklist](#before-go-live-checklist), starting with a ToyyibPay sandbox run.

## A1 — Front-end foundation

**Built:** Inertia v2 (`inertiajs/inertia-laravel` 2.0.28, `@inertiajs/react` 2.3.29) + React 18 + TypeScript + Tailwind 3 alongside the existing Blade pages. Mockup components, tokens (approved light theme + vivid-lavender dark theme) and BM/EN strings moved into `resources/js`. Nonce-based CSP kept: scripts and styles nonce-only, `style-src-attr 'unsafe-inline'` for Radix/Recharts inline styles. `/dev/ui-kit` proof page (local/testing only). `docs/RELEASE.md` covers build-then-upload for cPanel.

Inertia v2 was chosen over v3 because the v3 React client requires React 19.

**Checker findings fixed:** shared `club` prop (route-bound only), locale `ms` for members and guests, release doc, attachments use `url`/`mime` instead of mockup drawings, unused mockup tokens removed, Radix Dialog nonce verified with no CSP violations, small nits.

**Final review verdict:** GO.

**Follow-ups (non-blocking):**
- ✅ Done in R3d: `cdn.jsdelivr.net` is only allowed when the jsDelivr fallback is in use (no `public/build`).
- ✅ Done in R3d: fonts are self-hosted; Google hosts removed from the CSP.
- Align `<html lang>` with the shared `locale` (members get `ms`).
- ✅ Done in D1: CI workflow (`.github/workflows/tests.yml`): type check, build, tests on SQLite, MariaDB and MySQL.
- Add a production-environment test that `/dev/ui-kit` returns 404.
- Identify the one npm package with no declared licence; confirm the CC-BY-4.0 one is `caniuse-lite` (build-time only).
- `docs/RELEASE.md`: document `ASSET_URL` for subdirectory installs and `rm -f public/hot` before upload.
- Share `import_errors` / `import_imported` flashes when the member import page migrates.

**Known, not caused by A1:** one `LedgerTest` failure because this machine runs PHP 8.3 while `composer.lock` needs 8.4.

## A2 — Member pay flow on Inertia

**Built:** Member Home, Payments, Invoice/Receipt, new Review & pay page and status-aware PayResult (Berjaya / Dalam proses with polling / Bayaran belum diterima), all on Inertia with BM default. `pay` is POST-only and reuses an open bill only when its gateway and billed amount still match; changing an unpaid fee's amount clears its bill. Webhooks refuse to mark a fee paid when the paid amount is lower (audited as `payment.underpaid_webhook`). Single-payment routes require the owner, an active membership and an active club. Overdue reminder email links to the review page. Old member Blade views removed.

**Checker findings fixed:** underpayment through a reused bill (major), unverified "no money taken" wording on the failed page (major), overdue counts, inactive member/club access, return page without a bill, multi-club tab, `payment.bill_replaced` audit, `<html lang>`, 44px toggle, URL-encoding.

**Final review verdict:** GO.

**Follow-ups (non-blocking):**
- Lock `pay` per payment (`Cache::lock("pay:{id}")`, re-read the row inside) so two tabs or a retry can't create two bills.
- ✅ Done in G2: the payments list, generate-future and the member and admin home club queries respect `club_user.is_active` and `clubs.is_active` (payments list and pay-ahead 403; the clubs drop off the home pages).
- ✅ Done in G1: webhooks fail closed when neither a paid amount nor `bill_amount` is available (409, audited once as `payment.webhook_amount_unknown`, shown on the fee's admin page).
- ✅ Done in G1 (flag only, by design): when a bill code isn't found but the reference (with that reference's own callback token) names a fee, it is **never settled** (the bill isn't ours, and `getBillTransactions` is not tied to our merchant account or reference, so anyone could pay a bill of their own and send its code). It is flagged on that fee and club instead (`payment.unmatched_webhook`, a warning on the admin payment page: check ToyyibPay before marking the fee paid).
- Audit a second successful webhook with a different transaction id on an already-paid fee (double payment → refund).
- Throttle `pay` and `generateFuture` (✅ both done; `pay` and `pay-all` got `throttle:10,1` in G1); cap how far ahead `period_start` can go (✅ done: `latestFutureEnd()`, 12 months); unique index on (club, user, period_start).
- ToyyibPay sandbox: confirm the callback `amount` unit and whether customer-borne charges are included (alongside the `status` vs `status_id` check).
- Billplz callback for a replaced bill on a per-club-key club fails signature and is retried.
- Confirm with the committee that members should see club-wide fund totals.
- `bill_amount` was added to the existing migration because there is no live data; from the first real install (`v2.0.0`) use additive migrations only.

## A3 — Fund Usage backend + Inertia pages (in review)

**Built:** private polymorphic `attachments` (encrypted at rest with `Crypt::encryptString` on the private `local` disk, `clubs/{id}/attachments/{Y}/{random}.bin`; sha256/size of the original), audited + throttled download route (club admins only); `expenses` gains type/status/approval/void columns (receipt column and public receipt links removed); `welfare_cases` with encrypted personal fields; `FundUsageService` maker-checker workflow; `Expense::counted()` in ledger, dashboards, statistics and the member card with portable date-range bounds; Inertia `Admin/FundUsage/{Index,Create,Show}` and `Admin/Dashboard` (old Blade dashboard kept at `admin.clubs.dashboard.classic`). Old Expenses GET URLs redirect to Fund Usage.

**Deferred:** welfare personal-data purge command (`retention_until` is set); `account_id` / accounts (PR2); emailing admins on void request (audit-only now); `backup:run`.

## A3 — Fund Usage (attachments + maker-checker + admin pages)

**Built:** Private encrypted attachments (sha256, admin-only, throttled, audited previews/downloads; unaudited thumbnail route), expenses extended with type/status/approval/void fields, `welfare_cases` with encrypted personal fields, `FundUsageService` enforcing maker ≠ checker, beneficiary can't approve or return, conflict-of-interest declaration, attachments frozen on submit, return needs a reason, two-person void, row locks on every transition. `Expense::counted()` (approved only, cash date = paid date or invoice date) drives the ledger and exports, both dashboards, statistics and the member card; SQLite-only `strftime` removed there. Inertia pages: Fund Usage list/create/edit/show and the new admin dashboard (Blade dashboard kept as `admin.clubs.dashboard.classic`). Admin authorization now requires an active membership in an active club.

**Checker findings fixed:** file/database consistency on edit and create (major), beneficiary return, certificate sighted by a different active admin, thumbnails no longer audited per render, cash-date totals, inactive admins blocked, upload form pairing, void-requested chip, explicit redirects, plus tests for each.

**Final review verdict:** GO.

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
- Welfare personal data is **not purged automatically** yet (`retention_until` is only recorded, although the form says data is deleted after about 7 years); until the purge command exists, purging is manual (see `docs/RELEASE.md`).
- Super admins are not counted in the "needs second approver" banner.

## A4 — Installable web app (PWA)

**Built:** Still the same browser website, now installable ("Add to Home Screen"): web manifest, icons (192, 512, maskable, apple-touch), a minimal service worker that caches only `/build/assets/*` and `/icons/*` (plus the offline page and its icon), an offline fallback page (BM + EN) for failed top-level page loads, and an install tip on member Home (Android button, iOS Share instructions; dismissible, hidden when installed). CSP gains `worker-src 'self'` and `manifest-src 'self'`. `.htaccess` serves `sw.js` and the manifest with `no-cache`. `docs/RELEASE.md` covers the cache version bump and how to remove the service worker.

**Checker:** no blockers or majors. Verified in production mode that no HTML, Inertia JSON, attachment or payment response is ever cached, logged-out start URL goes to login, switching users shows no stale data, and a cache version bump replaces old caches. Minors fixed: offline icon precached, cache writes awaited, orientation lock removed, prompt also on the no-clubs screen, iOS Chrome/Edge get Share instructions.

**Final review verdict:** GO.

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

## P2 — Manual DuitNow transfer with slip upload

**Built:** club bank details (`transfer_enabled`, bank name, holder, account number 6–20 digits/spaces/dashes, DuitNow ID) in Billing settings; can only be switched on when complete (`Club::transferReady()`), audited. ReviewPay offers "Pindahan bank / DuitNow" next to FPX: bank details with copy buttons, the reference `YURAN-{id}`, 1–3 slips (camera allowed), transfer date, optional bank ref. Slips are encrypted private `proof_of_payment` attachments, frozen on submit; the fee becomes `pending_verification` (member sees "Menunggu pengesahan"; excluded from the amount due on Home). `TransferVerificationService` (row lock + status guard + audit) handles submit/approve/reject; only an active club admin approves or rejects, never for their own fee (super admin may). Approve → paid, `paid_via='transfer'`, receipt email; reject (reason ≥ 5) → back to `pending`, BM/EN `TransferRejected` email, member can resubmit. New Inertia page `Admin/Verifications/Index` (waiting + recently handled from the audit log), nav badge and a dashboard panel. Payments, ledger (screen, CSV, PDF) and statistics show "Awaiting verification" separately; manual mark-paid/status edit refuse it; a fee with slips can't be deleted.

**Deviation (correctness):** online pay is refused while a slip is being checked, but if a real gateway payment still arrives for a `pending_verification` fee (an earlier open bill) it is settled as paid and flagged `payment.gateway_paid_during_verification` (also when it arrives after an approved transfer) so the treasurer can refund the duplicate; ignoring it would lose track of real money. Reminders already skip it (only `pending` is matched).

**Review gate:** checker found no blockers; 2 majors (slip cap locked members out after rejections; handled slips not viewable) and 6 minors (amount editable while verifying, approve overwrote `reference`, copy button kept spaces, verifying missing from summaries, paid date before transfer date, unlocked `markPaid`) were fixed. Final review: **GO**.

**Final review follow-ups (non-blocking):**
- 🔴 Before go-live: set PHP `upload_max_filesize` ≥ 12M and `post_max_size` ≥ 32M on cPanel (defaults are often 2M/8M while the form allows 3 × 10 MB); add a client-side size check in `FileUploader` and a translated message for `PostTooLargeException`. (The size check and the bilingual 413 page were done in S1; the host limits are a go-live item.)
- Render the `method`/`status` errors in the ReviewPay transfer form (today they are silently dropped).
- Lock `Admin\PaymentController::update` like `markPaid`.
- Show the open-bill warning on the transfer option too.
- Clear `paid_via`/`verified_*` when a fee leaves `paid`.
- Consider blocking admins from marking their own fee paid (pre-existing).
- Tag slips with a submission number instead of the one-second `created_at` split in `currentSlips()`.

**Follow-ups:** no in-app notification to admins when a slip arrives (badge + dashboard only); slips have no virus scan beyond the type/size checks. Deleting a club now also removes its encrypted attachment files; deleting a user whose fees cascade away (or who uploaded documents, `uploaded_by` is restrict-on-delete) is not handled yet and needs a "deactivate instead of delete" rule for users with financial records.

## S1 — Security hardening from the full audit

Two read-only audits (auth/access; money/files/injection) found no critical issue, SQL injection, XSS or path
traversal, but did find maker-checker bypasses, a webhook-token exposure path, races and hardening gaps. S1 fixes
them (details in `CHANGELOG.md`):

- **Webhooks:** URLs pinned to `APP_URL` (+ trusted hosts); per-bill HMAC callback token; 32+ character secret;
  ToyyibPay `getBillTransactions` confirm step (fails closed, gateway retries); `payment_bills` keeps every bill so a
  paid superseded bill still settles or is flagged; unmatched callbacks audited; transaction-id dedupe; gateway
  match; allow-listed log fields; throttled routes; per-fee pay lock.
- **Maker-checker:** no self-settling (mark paid / status edit); only the super admin grants club admin (audited,
  emailed); bank/DuitNow detail changes need a second approver; stale admin payment edits refused.
- **Accounts:** 2FA setup needs the password, codes can't be replayed, per-user lockout; password reset gives one
  answer for every email, is throttled and ends other sessions.
- **Install/deploy:** installer needs `INSTALL_TOKEN` and writes production settings; seeder local/testing only with
  no known password; production `.env` block and PHP upload limits (12M/32M) in `docs/RELEASE.md`.
- **Exports:** CSV formula cells neutralised, slugged download names; ledger sums in sen.

**Known limit:** a Billplz callback that matches no fee is checked with the global `BILLPLZ_X_SIGNATURE_KEY` only.
For a club with its own Billplz keys such a callback is refused (403) and not audited; trying every club's key was
not worth the cost. Billplz has no confirm step like ToyyibPay's, so its `x_signature` is the only proof.

**Deferred** (not in S1):
- forcing a password change on first login;
- mandatory 2FA for super admins;
- a slip submission number;
- deactivating instead of deleting users who have financial records (for now the delete is refused with a message).

**Review gate:** checker found no blockers; 1 major (a second gateway payment on another bill of an already-paid fee was not recorded) and 7 minors were fixed. Final review: **GO** (also ran the app in production mode to confirm host pinning).

**Final review follow-ups (non-blocking, most important first):**
1. 🔴 **Sandbox hard gate:** confirm ToyyibPay's server callback field names (`status`/`refno` vs `status_id`/`transaction_id` read by `ToyyibPayGateway::parseWebhook`), that it retries non-200 callbacks, how soon `getBillTransactions` lists a payment, and what `billpaymentAmount` holds. If retries don't happen, set `TOYYIBPAY_VERIFY_CALLBACK=false` or add reconciliation.
2. Audit (once per bill) when `getBillTransactions` is unreachable (503 path), like the 409 case.
3. Installer: check the typed `app_url` host matches the request host, so a typo can't lock the site out.
4. Map untrusted-host errors to 400 instead of 500.
5. Password-reset limiter keyed by IP + email (staff share one office IP); set `TRUSTED_PROXIES` if the host uses a proxy.
6. ✅ Done (go-live hardening): a wrong password or code on the admin 2FA setup shows the setup page again with the same key.
7. Unmatched-webhook audit rows can still be added with any real paid bill code (no settlement possible); low priority.
8. Docs: a bank change requested by the only super admin for a club with no admin can't be approved; cancel and let a club admin request it.

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

- **Time:** A1–A4 were committed between 07:37 and 11:17 UTC on 2026-11-15 (about 4 hours of wall-clock time
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

### cPanel deploy (summary; first install in `docs/INSTALL.md`, upgrades in `docs/RELEASE.md`)

1. Get `clubportal-<version>.zip` from the GitHub Release (CI builds it on each `v*` tag) or build it with
   `scripts/build-release.sh` (G3). It holds `vendor/` and `public/build/`. **The server never needs Composer or Node.**
2. Upload and unzip it; for an upgrade, `cp -a` it over the app folder (never `.env` or `storage/`; see RELEASE.md).
3. On the server: `php artisan migrate --force`, `optimize:clear`, `optimize`, then `app:doctor`.
4. Bump `CACHE_VERSION` in `public/sw.js` every release.
5. `.htaccess` needs Apache `mod_headers` (standard on cPanel) for the `sw.js`/manifest `no-cache` headers.
6. Keep the previous release's `public/build/assets` for one release, so open tabs don't hit missing chunks.
7. Set `TOYYIBPAY_WEBHOOK_SECRET` (32+ characters), `APP_KEY` and, for the first install, `INSTALL_TOKEN` on the host, and run `/install` straight after the upload (S1). `E2E_FAKE_GATEWAY` must never be set there; the provider also refuses to run unless `APP_ENV=local`.

### Before go-live checklist

All ★ and go-live items from A1–A4 plus what A5 and the final review found. **Must-do before go-live** items are marked 🔴; the rest can follow soon after launch or are optional.

**🔴 Must-do before go-live**
- [ ] ToyyibPay and Billplz **sandbox** runs end to end on the real host, including a real callback after the webhook CSRF fix. ToyyibPay: follow [SANDBOX-TEST.md](SANDBOX-TEST.md) (`php artisan toyyibpay:sandbox-check`, callback field names in the Audit Log); real FPX stays off (`TOYYIBPAY_SANDBOX=true`) until every item passes.
- [ ] Gateway secrets set on the host: `TOYYIBPAY_WEBHOOK_SECRET` and `BILLPLZ_X_SIGNATURE_KEY` (placeholders are refused).
- [ ] Host WAF/ModSecurity and HTTPS/www redirects don't block or 301 the gateways' server-to-server POSTs.
- [ ] `APP_KEY` backed up off-server; `storage/app/private` (outside `public_html`) included in backups with the database. G2: `php artisan backup:run` (daily) zips the database and `storage/app/private`; INSTALL.md §6 covers keeping `APP_KEY` separately and downloading backups. Left on the host: store `APP_KEY` off-server and download the zips.
- [ ] PHP 8.4 on the host (both cPanel hosts offer it; it only needs enabling). `composer.json` already says `^8.4`.
- [ ] Create the MariaDB database and user on the host and set `DB_CONNECTION=mariadb` (INSTALL.md section 4). Don't go live on SQLite.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, HTTPS forced, `SESSION_SECURE_COOKIE=true`.
- [ ] S2 sign-in hardening: `REQUIRE_ADMIN_2FA=true` (every admin sets up 2FA at the next sign-in), `TRUSTED_PROXIES`
  empty unless a real proxy, HSTS left at this host only unless the whole domain is HTTPS, and the post-install
  `curl -I` checks in INSTALL.md §5 (`.env`, `vendor/`, logs, `.git` not reachable; headers once on pages and
  static files; `expose_php` off).
- [ ] Webhooks fail closed; per-payment pay lock (cache driver `database`/`file`, not `array`); unique index (club, user, period_start). Fail closed done in G1 and the unique index exists (P1). Done in code: `php artisan app:doctor` has a **Cache store** row that fails on `array` / `null`, so running it on the host (INSTALL.md step 13) confirms the driver. Left: on the real host, check that `php artisan app:doctor` shows the cache store row as PASS (not `array`).
- [x] A way to reconcile missed callbacks (manual "re-check bill status" or a scheduled job); cron for the scheduler. Done in G1 (`payments:reconcile-open-bills` every 15 minutes plus the "Semak status bayaran" requery; cron line in INSTALL.md step 12).

Full list by area:

**Payments**
- [ ] ToyyibPay **sandbox** run end to end: confirm the callback `amount` unit (ringgit vs sen), whether customer-borne charges are included, and `status` vs `status_id`. Script: [SANDBOX-TEST.md](SANDBOX-TEST.md).
- [ ] In the same sandbox run (S1): confirm that ToyyibPay **retries a callback answered with a non-200** (the confirm step answers 503/409 and relies on it), how soon after payment `getBillTransactions` lists the transaction (a lag means 409s), and what its `billpaymentAmount` holds when the customer bears the charge.
- [ ] Re-test a real sandbox callback on the host after the A5 **webhook CSRF fix** (it previously got 419).
- [ ] `TOYYIBPAY_WEBHOOK_SECRET` set on the host to a random value (placeholders are refused).
- [x] Lock `pay` per payment (`Cache::lock("pay:{id}")`, re-read the row inside). Done in S1.
- [x] Webhooks **fail closed** when neither a paid amount nor `bill_amount` is available. Done in G1 (409 + `payment.webhook_amount_unknown`).
- [x] **Reference fallback** when a bill code isn't found (only with a paid amount present). Done in G1 as flag-only: filed under the fee and club the reference names, never settled automatically (the unrecorded bill can't be proven ours).
- [x] Audit a second successful webhook with a different transaction id on an already-paid fee (double payment). Done in S1 (`payment.gateway_paid_twice`, flagged for a refund).
- [x] Throttle `pay`/`generateFuture`, cap `period_start`, unique index (club, user, period_start). Done: the unique index (P1) and the `generateFuture` throttle (10/min) exist; `pay` and `pay-all` are throttled 10/min since G1; pay ahead refuses a period ending after `Member\PaymentController::latestFutureEnd()` (12 months ahead).
- [ ] ToyyibPay sandbox (G1): check that the callback `refno` equals `billpaymentInvoiceNo` from `getBillTransactions` (the reconcile records that id; if they differ, the confirm-step count still stops a false "paid twice"), and run SANDBOX-TEST.md test 8 (lost callback settled by the 15-minute check).
- [ ] G1 follow-ups: (a) the reconcile and the status check only look at **unsuperseded** bills, so a superseded bill (single or group) paid with a lost callback is never surfaced, and needs a manual check in the gateway; (b) the status JSON routes (`payments.status`, `payments.group-status`) have no route throttle of their own: gateway calls are capped at one per bill per 30 s, but the routes themselves are not rate-limited.
- [ ] G3 follow-up: `tests/Feature/InstallationTest.php` writes the real `.env` (and `storage/app/.installed`) and
  restores them afterwards. A killed test run can leave a modified `.env` behind, which on a production box would be the
  live one. Point the installer at a temporary env file (and a temp storage path) in tests instead.
- [ ] Billplz has no reconcile yet: add a get-bill call to `BillplzGateway` before Billplz goes live, or accept that a lost Billplz callback needs a manual check.
- [ ] Billplz callback for a replaced bill on a per-club-key club (S1 keeps replaced bills in `payment_bills` and matches them; still to try in the Billplz sandbox).
- [ ] **Real iPhone payment return** from FPX/bank in the installed PWA (separate cookie jar from Safari).

**Data and files**
- [ ] **Back up `APP_KEY`** off-server; document rotation with `APP_PREVIOUS_KEYS` (attachments and welfare fields are encrypted with it).
- [ ] PHP upload limits on the host: `upload_max_filesize = 12M`, `post_max_size = 32M` (documented in `docs/INSTALL.md` step 10).
- [ ] Check cPanel **`memory_limit`** (a 10 MB file peaks at ~50–60 MB to encrypt/decrypt). `php artisan app:doctor` (G2) checks it (≥ 128M) with the upload limits, PHP 8.4, `APP_ENV`/`APP_DEBUG`/https/`SESSION_SECURE_COOKIE`, the cache store and the cron job: run it on the host.
- [x] Serve real downscaled **thumbnails** (or audit the thumbnail route); today it returns the full original. Done in G2 (GD re-encoded copy, 480 px / 1600 px for the slip preview, EXIF dropped, 404 when it can't be decoded).
- [x] **Block club deletion** when records exist (the cascade wipes approved records and leaves encrypted files on disk). Done in G2 (members, fees, bills, expenses or documents → "Deactivate it instead").
- [ ] From the first real production install (`v2.0.0` onwards), use additive migrations only: no shipped migration is edited again.
- [ ] Tell admins not to type personal data in description, return or void reasons.

**Hosting**
- [ ] `APP_ENV=production` and `APP_DEBUG=false` on the host (the e2e guards rely on `APP_ENV`).
- [ ] **PHP 8.4 on the host** (`composer.lock` requires it; this machine's PHP 8.3 is why `LedgerTest` fails).
- [ ] Apache `mod_headers` (and `mod_deflate` for compression, the biggest Lighthouse win) enabled.
- [ ] Send **`no-store` on authenticated pages** (bfcache shows the old page on Back after logout).
- [x] Keep the previous build's assets for one release. Done: the upgrade copies the new release over the site with `cp -a` (RELEASE.md §2), which never deletes, so the previous build's `public/build/assets` stay in place.
- [ ] Install at the domain root, or fix absolute paths and set `ASSET_URL` for a subfolder.
- [ ] Add a production test that `/dev/ui-kit` returns 404.
- [x] Add a CI workflow (`npm ci && npm run typecheck && npm run build`, `php artisan test`), with the tests on a MariaDB service as well as SQLite (D1). Done: `.github/workflows/tests.yml` runs on SQLite, MariaDB 10.11 and MySQL 8.0 and rolls the migrations back and forward. Left (optional): the e2e smoke as a nightly job.

**Product and decisions**
- [ ] Confirm with the committee that members may see club-wide fund totals.
- [ ] Check inline PDF preview under the sandbox CSP in Chrome and Safari; decide how to handle HEIC photos.
- [x] Optional: self-host fonts, which removes Google hosts from the CSP and helps offline/PWA. Done in R3d (Fontsource, bundled by Vite).
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

### Final review — advisory recommendation

Verdict on A5: GO. Hardening applied before commit: the private `local` disk is no longer served over HTTP (`'serve' => false`), with a test that the `storage.local*` routes stay absent.

Advice to the owner: continue with Option A. The slice reused payments, webhooks, auth/2FA and the audit log almost untouched, deploys as plain PHP with no Node on the server, and grew the suite from 153 to 263 tests. The one serious defect found (webhooks getting 419) was pre-existing and unrelated to the stack; a rewrite would re-open that kind of money-path risk across the whole codebase and add Node-on-cPanel hosting risk. Option B only becomes worth it if the real host can't run PHP 8.4, or if the owner finds the React pages hard to edit after trying one or two. Suggested order: the 🔴 must-dos and a real-host sandbox payment first, then migrate the remaining Blade pages module by module, retiring Bootstrap last. Re-measure Lighthouse on the host with compression on; the 69 was measured uncompressed.

### P1 final review: GO

Applied before commit: ToyyibPay "club absorbs" keeps the pre-P1 `billChargeToCustomer=1` until the sandbox run confirms how blank vs `1` is read (avoids members being charged RM1 more than shown).

Follow-ups (non-blocking):
- P3 sandbox: first item is confirming `billChargeToCustomer` values and the callback `amount` unit; then decide whether to enable `TOYYIBPAY_CHARGE_VERIFIED`.
- Columns and the unique index were added by editing existing migrations (no live data); any existing database needs `migrate:fresh`.
- Admin fee `store` overlap check has no lock/transaction; two concurrent creates could overlap.
- Review → pay race: if an admin flips the fee setting between page load and POST, the charge can differ from what was shown; post the expected total and reject a mismatch.
- Fee KPI and the member-pays line use the configured fee (default RM1), not the gateway's real fee (Billplz RM1.25 + SST); label as "estimated".
- Pay-ahead ignores discounts and uses today's rate for all months.

### Docs and licensing follow-ups (non-blocking)
- Enable GitHub private vulnerability reporting so the `SECURITY.md` link works.
- Licence script strictness: append the GPL-3.0 text (incorporated by LGPL-3.0), pick up the DejaVu font and `sRGB2014.icc` licence files under `vendor/dompdf/dompdf/lib/res/`, and add fallback copyright notices for `react-remove-scroll-bar` and `victory-vendor` (no licence file upstream).
- Link `/licenses.txt` from the app footer or an About page.

### R1a follow-ups (non-blocking)
- ✅ **Done in R1e.** ReviewPay StickyBar: same border, padding and safe-area inset pinned or not (only position and background change), so it cannot flip near the 18% threshold.
- ✅ **Done in R1e.** `authLangChosen` uses the normalised `cp_lang` value; `__($message)` and the status line in `auth/partials/alerts.blade.php` are guarded with `is_string`.
- ✅ **Done in R1e.** Feature test for the sign-in fallback style when `public/build/manifest.json` is missing (`AuthPagesTest`).
- ✅ **Done in R1e.** Translated 429 page (`errors/429.blade.php`, auth layout, BM/EN from the sign-in language or `cp_lang`), with tests.

### R1b follow-ups (non-blocking)
- ✅ **Done in R1e.** Group result page and status poll read "paid" only while every fee in the set is still paid; otherwise the member is sent to their fee list (test in `PayAllTest`).
- ⏸ **Deferred.** `flagGroup` refund-flag dedupe scans the latest 500 flag rows per club; revisit if volume grows.
- ✅ **Done in R1e.** Admin dashboard slips panel groups pay-all slips into one row with fee count and total (shared `TransferVerificationController::slipSets`).

### R1c follow-ups (non-blocking; to pick up in R1d/R1e)
- ✅ **Done in R1d.** Reverting an online-paid fee warns and a hand re-mark keeps its charge; the remaining case is documented in R1e (USER-GUIDE, "Bank and gateway charges").
- ✅ **Done in R1d.** Statistics uses FundFigures (bank charges included).
- ✅ **Done.** `FundFigures::spentSplitBetween` docblock describes `welfareCount` (admin screens only).
- ✅ **Done in R1d/R1e.** Ledger rows use a "Former member" fallback; removing a member only detaches them from the club (payments keep `user_id`), so paid fees stay in AGM history. Decision: keep detach; documented in USER-GUIDE, covered by `R1ePolishTest`.
- ✅ **Done.** BM label for the charges line on the member card ("Caj bank / gateway"); the split line reads "Caj bank". Wrapping at 390 px checked in R1e (no overflow at 200% text).
- ✅ **Done in R1e.** USER-GUIDE says which figure to quote at the AGM with a manual opening balance, and how gateway settlement dates differ from the bank statement.

### R1d follow-ups (non-blocking; for R1e)
- ✅ **Done in R1e.** Docs screenshots retaken (all 16 plus `member-pay-all.png`).
- ✅ **Done in R1e.** USER-GUIDE: record a lost first gateway charge as a manual bank-charge expense.
- ✅ **Done in R1e.** Club name shown at most twice on desktop admin (brand subtitle dropped; nav group and page eyebrow remain).
- ✅ **Done in R1e.** `resolveClubForMember` club-admin fallback filters active pivot and active club like `managesClub` (test in `R1ePolishTest`).
- ⏸ **Deferred.** Statistics runs ~8-10 queries per club; batch/cache if clubs grow.
- ✅ **Done in R1e.** Theme button label "Theme (System)" (Blade and React); date hint on admin date inputs: the picked date written out ("16 Nov 2026") under the field (Blade layout script and React `DateHint`), so it never contradicts the browser's own date order; SRI (sha384, `crossorigin="anonymous"`) on the Bootstrap, Bootstrap Icons and Chart.js CDN tags, hashes computed from the npm package files jsDelivr serves (Chart.js switched to the package's own `dist/chart.umd.js`); payments table shows status under the member's name on phones with a scroll hint and a focusable scroll region.

### R1e notes
- Spending categories have an optional `name_ms` (migration edited in place: existing databases need `migrate:fresh` before the first release, as noted in README).
- Checker round: the Verifications sidebar badge counts slip sets (`TransferVerificationController::waitingSlipCount`, one SQL query); "Bank charges" category note on the Fund Usage form and Expense categories page; date hints echo the picked date; screenshots retaken with real encrypted demo attachments and `en-GB` admin locale.
- Re-review (Impeccable critique A/B and audit) still to run separately.

### R1e follow-ups (non-blocking)
- Member card may show both "Caj bank" (hand-entered category) and "Caj bank / gateway" (automatic); merge or rename the automatic line to "Caj gateway".
- Bank-charges hint matches the English category name; use a category flag instead.
- `byCategoryBetween` groups by (name, name_ms); same-named categories with different BM names split.
- Fund Usage list shows a red "0" attachments count on approved records; make it muted.
- Welfare form reuses the expense description example.
- Check audit-log filter alignment with the date echo at 768 px.
- Move `slipSets`/`waitingSlipCount` from the controller into a Support class.
- Only if deploying without `public/build` (jsDelivr fallback): open one Blade admin page with devtools and confirm no SRI errors. Normal deploys bundle Bootstrap and Chart.js.

### R2a follow-ups (non-blocking)
- ✅ Done in G1 (was 🔴 P3): "Semak status bayaran" now asks ToyyibPay itself (status poll of the single-fee and group result pages, at most once per bill every 30 s), and `payments:reconcile-open-bills` runs every 15 minutes over open ToyyibPay bills opened 10 minutes to 3 days ago. Both settle through the webhook's own code (`SettlementService`) and audit `payment.reconciled`. Billplz bills are skipped (no get-bill call in `BillplzGateway` yet) and still rely on the callback.
- Blade Payments summary total is a separate query from the status counts (harmless race).

### R2b follow-ups (non-blocking)
- Inertia requests that hit 419/500/503 show the branded page inside Inertia's error modal (iframe nonce mismatch likely); force a full-page reload on 419 for Inertia requests.
- Return a small text/JSON body (not branded HTML) for `/webhook/*` errors.
- `/up` health view now carries the CSP and may render unstyled (status stays 200).
- On PHP 8.4, re-check an untrusted Host header returns 400 (here 8.3 gives 500 via isVirtual).
- Billing WhatsApp field: hint that landlines need WhatsApp Business; help copy: a shared receipt link needs the member's own login.
- Statistics "Collected vs spent" trend is still a Chart.js bar chart (token colours); convert if wanted.

### R3a follow-ups (non-blocking)
- A 429 on the member security forms shows Inertia's error modal with the English page; map it to a BM `member.too_many_tries` flash.
- Tie `two_factor_verified` to the user id and regenerate the session after confirm.
- Expire `two_factor_setup_pending` after ~10 minutes.
- Consider requiring a code (or recovery code) to turn 2FA off.
- Add a "simpan di tempat selamat" hint next to the recovery-codes download.
- Encrypted history needs HTTPS in production (Inertia stores plain history over http).

### R3b follow-ups (non-blocking)
- The thumb route serves full-size originals (no-store); add real resized thumbnails later.
- Add a 300% zoom step for dense bank slips.
- "Start reviewing" and the shortcut hint show from md up only.

### R3c follow-ups (non-blocking)
- Payments wrapper is a tab stop with nothing to scroll below 1200px; drop the tabindex there.
- Make the Name/Member cell a row header (`th scope="row"`) so each card is announced by name; add a caption to Payments.
- Two-column card grid from md to xl to halve scrolling on tablets/small laptops.
- Installer still uses px font sizes; `fund-meta.ts` `bm` fields may be dead (check in R3d).

### R3d (performance)
- Member pages no longer load the tooltip library; the entry chunk fell from 158.8 to 130.1 kB gzipped and member Home's total JS from 175 to 156 kB gzipped.
- Fonts (Plus Jakarta Sans, Bricolage Grotesque, JetBrains Mono) are self-hosted from Fontsource (OFL-1.1) with the body font preloaded; Google Fonts removed.
- Bootstrap 5.3.8, Bootstrap Icons 1.13.2 and Chart.js 4.6.1 are bundled by Vite for the classic pages; jsDelivr (pinned, with SRI) is only a fallback when `public/build` lacks the classic entries. Zero third-party requests in the normal path.
- lodash-es, axios and qs remain (pulled in by `@inertiajs/core` and `laravel-precognition`); the i18n dictionary is still in the entry chunk.
- R3d follow-ups: the service worker's static cache grows with each hashed build until `CACHE_VERSION` is bumped; the `$bsTheme` manifest check in `layouts/app.blade.php` could move into `FrontendAssets`; the legacy `bootstrap-icons.woff` ships unused.

### R4a follow-ups (non-blocking)
- composer.json now requires PHP ^8.4: confirm the production host runs 8.4 before deploying (8.3 needs --ignore-platform-req=php).
- On an 8.4 host, check one 405 page renders (tests assert it as an exception because 8.3's var-dumper crashes on render).
- Browser-bar colours live in both lib/theme.tsx and partials/pwa-head.blade.php; make one source later.

### R4b follow-ups (non-blocking)
- Catch-all routes use Route::any, so OPTIONS on a known path returns 405 instead of Laravel's automatic 200; restrict to GET/POST/PUT/PATCH/DELETE if CORS is ever needed.
- A guest on an unknown /admin/* URL starts a session row; add a throttle on fallback.admin if session-table spam matters.
- Old-format recovery codes (with 0/O/1/I/L) still verify; mention in release notes.
- AdminErrorPage relies on the framework's password_hash_<guard> session key; keep the test that renders the admin variant when upgrading Laravel.
- route:cache works again (duplicate 'login' route name removed); RouteCacheTest guards against duplicate names.

### After R4: final Impeccable scores and what is left
Scores: design critique 30/40 (Good), technical audit 18/20 (Excellent); axe found no WCAG A/AA violations across 198 sweeps.

Remaining items (content and product choices rather than polish):
- Member Fund page: show welfare (kebajikan / khairat) and bank charges as their own rows instead of inside "Lain-lain", and show the fund balance ("Baki dana") next to the overspend note.
- Audit log: stack as cards under 768 px (it scrolls sideways at 390 because of a hidden label inside the scroller); give action chips meaningful colours; use friendly kinds and FU-numbers instead of "(proof_of_payment)" and "Expense #12".
- Fund Usage list: on phones lead each card with payee + amount + status; fold the KPI tiles on small screens; show a neutral "—" for no documents and warn only for approved records without any; footer total should say it covers all statuses.
- Members: a fee past its due date should leave "Akan datang" and say "Lewat selepas <date>"; use one BM word for overdue ("Lewat", drop "Tertunggak").
- Sign-in/reset footer "Hubungi pentadbir kelab" should be a link (club WhatsApp or email, or a short "Lupa e-mel?" note).
- Smaller: lazy-load the chart on the admin Dashboard/Ledger; branded 405 page; "You recorded this" link styled as a link; record-payment radio alignment; subset Bootstrap Icons; success page says the receipt "akan" and "telah" been sent; slip-check could flag date/reference mismatches.


### R5a follow-ups (non-blocking)
- Go-live: set `APP_TIMEZONE=Asia/Kuala_Lumpur` in `.env`; with the UTC fallback a fee moves to "Perlu dibayar" up to 8 hours late.
- A club expense category literally named "Welfare"/"Kebajikan" would show two legend lines with that name (sums stay correct).
- Under "Akan datang", a not-yet-due fee's chip can still read "Perlu dibayar", the same words as the new group heading; consider "Belum bayar" for the chip.
- Home "Akan datang" still mixes processing/verifying rows from the `take(5)` list (pre-existing).
- Payments "Bayar semua" shows when the selected year has open fees, while its total covers all open fees in the club.
- The sign-in help `<summary>` has no chevron; only the underline signals it opens.
- The help block also shows on the 2FA, confirm-password and error pages (shared `layouts.auth`); its view composer runs one small query per render.
- "Lain-lain" can hold a single category when exactly five expense categories exist.
- Stale comment on `fromReserves` in `resources/js/types/member.ts`.

### R5b follow-ups (non-blocking)
- Fund Usage footer "approved (counted in Spent)" can differ from the Spent tile (cash-date window, bank charges); consider "approved" plus a tooltip.
- Ledger with a manual opening balance no longer shows the computed "records say RM X" hint; consider restoring it.
- Audit log: old attachment rows whose file name holds `"` or a newline keep the raw kind; search by FU number misses old rows that store "Expense #N".
- Audit lookup hardening: add the page's club ids to the `forPage` `whereIn` queries (safe today via the per-row club check).
- ISO dates in typed text outside quotes are reformatted on display (harmless).
- Audit log stacks below 1200 px (existing `.table-stack` breakpoint) and adds a fourth "warning" chip group; the admin 405 variant only shows for an explicit `abort(405)`.

## After R5: Impeccable scores and what is left
Scores: critique 31/40 (target 32), audit 18/20 (target met). History: critique 25 → 27 → 29 → 30 → 30 → 31; audit 11 → 15 → 15 → 16 → 18 → 18.

Critique (P1 first):
- [P1] Fund Usage desktop table at 1366 px cuts off the Amount column (1126 px table in a 1076 px scroller).
- [P1] Dashboard / Fund Usage "Fund balance" hint says "collected minus spent" but the figure includes the opening balance; say "Opening balance + collected − spent" or link to the Ledger.
- [P2] Fund Usage list "Date paid" falls back to the invoice date for unpaid records; detail rail labels the waiting checker step "APPROVED · CHECKER".
- [P2] Blade admin pages (Payments, Members, Audit log) still differ from React: headers, no sidebar toggle, 24-hour times, mm/dd/yyyy date pickers.
- [P2] Sign-in help gives no contact when the club has no WhatsApp number; fall back to email or reword.
- [P2] Admin phone Dashboard: put work queues above the four figure cards.
- [P3] "Perlu dibayar" chip under the "Perlu dibayar" heading; empty "Akan datang" still shows "Lihat semua"; four actions on the paid result page; "14 member(s)"; monospace "No documents" chip; Members has no search; audit chips read like code names; 405 page in BM for English admins.

Audit:
- [P1] Admin Dashboard scrolls sideways at 320 px: the approvals row for an item you can't approve (badge, reason, View) doesn't wrap (`Dashboard.tsx` ~233–240).
- [P2] At 200% text, Dashboard (table under chart not in a scroller), Ledger (status pill), Billing (save bar) and Verifications ("Approve RM" button) scroll sideways.
- [P2] FileUploader drop zone is a `<label role="button">` (axe `aria-allowed-role`); use a real button.
- [P3] No skip link on member pages; member h1 smaller than section h2; 20 px admin quick links; overshooting tick easing.

### R6 follow-ups (non-blocking)
- Tailwind breakpoints are now in em (same as px at 16 px), so layouts step down when the browser text size is raised.
- FileUploader browse button's accessible name on desktop is just "browse"; consider "Browse files" in visible text (label-in-name).
- `BalanceHint` is duplicated in `Dashboard.tsx` and `FundUsage/Index.tsx`; move it to `Kpi.tsx`.
- No test covers the rendered "—" + "Invoice {date}" for approved records without a paid date.
- Fund Usage table scrolls inside its box at 768/1024 px (page itself doesn't); `PageHeader` forces `min-h-10` on action children.
- Sign-in help shows a club's email only when it has no WhatsApp number (product choice).

## After R6: Impeccable scores and what is left
Scores: critique **32/40** (target met), audit **19/20** (target met). No P0 or P1 in either. History: critique 25 → 27 → 29 → 30 → 30 → 31 → 32; audit 11 → 15 → 15 → 16 → 18 → 18 → 19.

Critique (P2):
- One fee, two dates: Invoice and Semak show "Tarikh akhir 15 Nov" (passed) while Home says "Lewat selepas 22 Nov"; show the grace date there too.
- "No documents" warning on 9 of 10 Fund Usage rows; use one banner plus a filter, or a small icon.
- Classic Payments, Members and Audit log still differ from React: no sidebar toggle or subtitle, "Record payment…" label cut off, 14 red "Remove" buttons, misaligned filter labels.
- Billing gateway-fee card uses developer wording about the sandbox.
- Verifications slip cards are about 680 px tall with an empty right column.
Critique (P3): PayResult still has four actions; member bottom bar spans the full width at 1366; Dashboard cards (year to date) vs chart (last 12 months); "Monthly report (classic)" label; unlabelled welfare/bank slivers; px KPI icons; Fund Usage tab strip has no scroll cue; "Club Portal" brand in English on BM pages; admin 405 uses the member guest card; no-channel sign-in help gives no contact.

Audit:
- [P2] Member h1 (18 px) smaller than section h2 (20 px); flat scale on ReviewPay.
- [P3] Overshooting pop-in/stamp easing; 20 px Dashboard quick links; React admin skip link 20 px tall; names truncated with "…" at 200% text on Verifications, Dashboard, Ledger and Fund Usage.

### R7 (last design round) follow-ups — after launch
- Fund Usage: with the `nodocs` filter on, switching tabs keeps it (the void tab then shows an empty list under the banner); clear it on tab change.
- The "no documents" count includes welfare approved on a sighted certificate with no attachment (same as the old chip); consider excluding those.
- The printed invoice also shows "· Lewat" next to the due date; confirm that is wanted.
- Tests: pagination keeping `nodocs`; `nodocs=1&tab=void` returns nothing; drafts excluded from the count.

Design rounds stop here (critique 32/40, audit 19/20). Remaining polish above waits for real feedback after go-live.

### P3a (sandbox prep) follow-ups
- Warn at boot or in `sandbox-check` when `APP_ENV=production` and a club has ToyyibPay active while `TOYYIBPAY_SANDBOX=true`.
- `sandbox-check --payment` on a fee whose member was deleted throws instead of refusing.
- Bill names are cut at 30 characters after "Club Fee "; long club names lose their end (cosmetic).
- Dedupe and "paid twice" flags now key on the callback `refno`; the sandbox run must confirm it is unique per payment.

### G1 follow-ups (non-blocking, from the final review)
- Status-check audit rows say "System" but keep the member's IP and user agent; blank them under `asSystem` or accept as trace data.
- Reconcile: legacy fees (no `payment_bills` row) have no cursor, so with many in the 3-day window the same first rows are asked each run; the main cursor advances before processing (a crash skips the rest of that batch until it wraps).
- `auditUnmatched` dedupe scans only the latest 500 entries across all clubs.
- Add a `throttle` to the member status JSON routes (the gateway call is already capped per bill).
- Extra-payment flags from reconcile show synthetic ids (`<txn>#2`); say in the flag text to look up the real transaction in ToyyibPay.
- Largest remaining gap: a superseded bill paid with a lost callback is never surfaced.

### G2 follow-ups (non-blocking, from the final review)
- Doctor "Backups" row passes on any recent zip; match the backup name pattern and warn when the newest zip has no database.
- Thumbnails over 25 MP or beyond `memory_limit` show a broken image (404); add a "preview unavailable" placeholder in the UI. "Open full size" still works.
- Add the `exif` extension to the doctor (without it phone photos lose their rotation).
- `chmod 0600` the temporary dump and the final backup zip on shared hosts.
- An attachment deleted while a backup is being zipped fails that night's run (it exits non-zero and prunes nothing).
- Inactive members get 403 on the payments list but can still open direct payment links for an open bill (in-flight payments complete); confirm the intended UX.

### G3 follow-ups (non-blocking, from the final review)
- Pin the publish job's third-party action (`softprops/action-gh-release`) to a commit SHA, since that job holds `contents: write`.
- Prune vendor `tests/` folders from the zip to make it smaller (harmless, outside the web root).
- Add `storage/app/.install-token` and `.env.example.bak` cases to the `--check-list` unit test (the regex already catches both).

## D1 — MariaDB / MySQL readiness

**Why:** both cPanel hosts run MariaDB 10.11, so production is MariaDB, not SQLite. SQLite ignores VARCHAR lengths and
stores any bytes, so the suite never saw what MariaDB / MySQL strict mode refuses. The code must stay MySQL-compatible.

**Fixed:**
- Columns too short for what the app allows (edited in place, no live data yet): `audit_logs.description` → `text`
  (settlement notes run past 255), `expenses.description` → 500 (the form allows 500),
  `payments.transfer_bank_ref` → 255 (mark-paid allows 255).
- Member CSV import: emails are matched without case (a mixed-case duplicate was a 500 on MariaDB and a second account
  on SQLite), a lost race becomes a row error, names over 255 characters are refused, and Windows-1252 files (Excel on
  Windows) are converted to UTF-8 instead of failing with error 1366.
- User agents are cut with `mb_strcut` after `mb_scrub` (a `substr` could split a character: error 1366).
- Bank-detail approval fingerprint sorts the JSON keys (MySQL 8 stores JSON with sorted keys, so the hash changed).
- `add_performance_indexes` `down()` puts the foreign keys' plain indexes back before dropping (error 1553 on rollback).
- Installer checks the PDO extension of the configured database (it checked `pdo_sqlite` even on MySQL).
- Import page buttons wrap on phones ("Cancel" was cut off); the dashboard chart labels every other month on phones.
- Docs: INSTALL.md section 4 is "MariaDB / MySQL" (`DB_CONNECTION=mariadb`, supported versions, collation),
  README, `.env.example`, CLAUDE.MD, USER-GUIDE.

**Verified:** 737/737 tests on SQLite, MariaDB 10.11.14 and MySQL 8.0.46; `migrate:fresh` → `reset` → `migrate` on
both; LAN test bed moved to MariaDB with every table copied (row counts match); browser sweep as super admin, club
admin and member at 320 / 390 / 768 / 1366 px, light and dark, BM and EN, on MariaDB and MySQL: no server errors and
no sideways scrolling. Slip approval, a 300-character Fund Usage entry and the CSV cases checked in the browser.

### D1 follow-ups
- ✅ Concurrency on MariaDB / MySQL (22 Nov). 400 simultaneous callbacks to the test site: 0 server errors (302
  refused, 98 throttled; the throttle's `cache` table is on MariaDB). Settlement race (24 processes settling the same
  90 fees through single and "Bayar semua" bills at once) found two bugs, both fixed: superseding a fee's group bills
  locked every member's group bills (deadlocks, error 1213, in every run; now by primary key, see
  `PaymentBill::supersedeOpenGroupsContaining`), and a group delivery that waited for the fee locks read a stale
  REPEATABLE READ snapshot and wrote a second refund flag (the first read in `markGroupPaid` is now a locking one).
  After the fix: 0 errors and no duplicate flags in 10 runs (24,000 settle calls) on MariaDB 10.11 and MySQL 8.0.
- ✅ Backup and restore on MariaDB (22 Nov): `backup:run` with MySQL 8's `mysqldump` and with MariaDB's own; both
  dumps restored cleanly and every table matched the live database (row counts and `CHECKSUM TABLE`; `audit_logs` only
  differs by the backup's own `system.backup` row), and the zipped uploads were byte-identical. A dump made by
  MariaDB 10.11.8+ starts with a `/*M!999999\- enable the sandbox mode */` line that older MariaDB and MySQL clients
  refuse: restore on the same host (or the same MariaDB version) it was made on.
- ✅ CI now runs the tests on SQLite, MariaDB 10.11 and MySQL 8.0 (`.github/workflows/tests.yml`).
- On SQLite `LOWER()` folds ASCII only, so a CSV email differing only in a non-ASCII capital isn't caught there
  (MariaDB's collation catches it). The `LOWER(email)` lookup can't use the index; fine at club size.
- Screenshot open menus and dialogs ("More", record payment, approvals) in the next browser sweep.

## Go-live hardening (PR #26, merged 22 Nov)

Front page (`/` is the sign-in page with "how to join"), dependency update (54 advisories → 0, audited in CI), login
and password hardening (per-account pause, separate limiters, no account enumeration, 500 ms sign-in floor, forced
change of emailed temporary passwords, admin 2FA required, 12+ characters and breach check, audit + owner emails) and
per-club job levels. 826 tests on SQLite, MariaDB and MySQL; settlement race unchanged (PR #25 merged first).

## Settings (PR #27, merged 22 Nov)

**Phase 1:** club profile page for club admins (name, logo, contact, optional ROS no., address, financial-year start
month); logos re-encoded to PNG (no SVG/GIF); club identity on member home, invoices, receipts, ledger PDF and the
single-club sign-in page; receipts keep the issuer details they were issued with (`payments.issuer_snapshot`); portal
name and logo (super admin), also the mail sender name; "this year" follows the club's financial year (the classic
dashboard is labelled "calendar year").

**Phase 2:** six brand colour presets (WCAG AA checked in light and dark by a unit test) on every page, app icons
generated from the portal logo and a routed `/app.webmanifest`; per-club receipt numbers `{PREFIX}-{FY}-{NNNN}`
(locked counter, never reused, not gap-free; a set paid together shares one number and each fee's page says so);
receipt footer in the snapshot; BM/EN privacy notice at `/privacy`.

**Verified:** 965 tests on SQLite, MariaDB 10.11 and MySQL 8.0 (a 67-character index name that only MySQL/MariaDB
refuse was caught here and is now guarded by `IndexNameLengthTest`); upgrade, rollback and re-upgrade with data on both;
settlement race with receipt numbers (24 processes, 2,400 settle calls): 0 errors, every paid fee numbered, no number
twice, no fee on two live receipts. Two security reviews (phase 1: 4 low, phase 2: 2 integrity + 2 low), all fixed.
Browser run on the test bed: profile, logos (SVG refused), FY labels, 320 px and dark mode, receipt snapshot after a
rename, portal name, teal preset, icons (200 image/png), receipt `KSU-2026-0001` with footer, a two-fee slip as
`KSU-2026-0002` with the "part of receipt" note, `/privacy` in BM and EN.

### Settings follow-ups
- The race harness drives gateway settlement only: slip approval and a revert racing a settlement were not run
  concurrently on MariaDB / MySQL.
- Still lavender: charts, emails, the ledger PDF, `offline.html`; the manifest `background_color` is shared.
- ✅ Receipt year follows the paid date (owner's call, 22 Nov); the audit log names issued and voided numbers.
- One club admin alone can change what receipts print (name, registration no., prefix, footer): audited, no second
  approval. Deleting a paid fee voids its number; refusing the delete would be stricter.
- A receipt-number sequence starts at 0001 when this ships; fees paid before keep `RCP-{id}` / `RCP-G{id}`.

## Cancel payment (branch `cancel-payment-impl`)

"Cancel payment…" on a paid fee (reason per how it was paid, internal note, confirmation): the fee is unpaid again,
its receipt number void, a `payment_cancellations` row keeps the old details, one audit row per fee, one email per
cancel; a set paid together is cancelled together; replays and late callbacks of the cancelled payment are ignored;
the Edit page can no longer take a fee back from Paid. An online payment made while the fee's slip was being checked
goes back to that slip instead.

**Verified:** 986 tests on SQLite, MariaDB and MySQL; upgrade / rollback / re-upgrade with data on both; cancel race
(settlement workers + cancel workers on the same fees): 0 errors, every paid fee with one live receipt, every
cancelled number void, no double cancel, in 5 MariaDB and 4 MySQL runs (most at 16 + 4 workers: the 2 GB test VM ran
out of memory at 24 + 6, and that first run's ~20 lock-wait timeouts on MariaDB coincided with it; cause inferred);
independent review (4 minor, fixed); browser run on the test bed (slip cancel, set cancel, member banners).

### Cancel payment follow-ups
- Cancel workers only cancel online payments: a slip approval racing a cancel was not run concurrently.
- Cancelling a payment dated in an earlier financial year changes that year's totals (the audit log is the trail).
- Test bed `LOG_LEVEL=warning` hides log-mailer emails: cancellation emails were checked by the suite only.

## Monthly fee generation (branch `monthly-fees`, on top of `cancel-payment-impl`)

**Generate fees** (Payments → "Generate fees for a month"): pick this or next month and a due date, preview every
member (billed RM x, or skipped: inactive, joined later, no rate / RM 0 rate, already has a fee covering the month,
busy), confirm; the server recomputes and refuses if anything changed since the preview (count, total and a
fingerprint of who and how much). Rerun is safe. One `FeeRates` rule prices generation and pay ahead (each month at
its own rate); a future-dated rate keeps the old one until the day before; a backdated correction replaces what it
covers; deleting an open rate reopens its predecessor; a data migration closes old gaps. Overdue reminders now only
go to active members of active clubs.

Also fixed: every Delete button on Fee Rates deleted rate #club_id (present since the first commit; found in the
browser).

**Verified:** 1030 tests on SQLite, MariaDB, MySQL; four simultaneous generations of one month on both databases
(41 members, each billed once, total right); upgrade/rollback/re-upgrade with data; browser on the test bed
(preview, confirm prompt, create, rerun creates nothing, member sees the fee; future rate saved, pay-ahead priced
RM 40 + 45 + 45 = RM 130 and charged that, future rates deleted and the old ones reopened).

### Monthly fee follow-ups
- Generation racing a member's pay ahead for the same month was not run concurrently (the shared lock and the
  unique index cover it in code and tests).
- A fee an admin deleted on purpose comes back if the month is generated again.
- Generated fees carry no discount; only this month and next can be generated.
- The overdue reminder job ran only inside the test suite.

## User guides (Word, English)

`docs/guides/Club-Portal-Member-Guide.docx` and `docs/guides/Club-Portal-Treasurer-and-Club-Admin-Guide.docx`, guide
version 1.0 for v2.0.0: screenshots from a throwaway demo club (seeded, renamed "Sunrise Staff Club"), every label
checked against the code, reviewed by a copywriter pass (which corrected nine claims) and the advisor; both pass the
Office XML validator. Not rendered to pages (no LibreOffice here): the contents page fills in when Word updates fields
on opening. They are edited in Word from now on; the generator script and screenshots are kept outside the repo
(~/Documents/Club Portal guides/) and would overwrite manual edits if rerun.

## Release v2.0.0 (23 Nov 2026)

First release since the superseded March `v1.0.0` (fresh install only). Prepared in PR #30: service-worker cache v3,
licence files regenerated (the build only warns when the committed ones are stale; the zip carries the regenerated
files). Versioned `v2.0.0` (the `v1.0.0` tag is taken by the old
release; a major version marks "install fresh"). Before tagging: the release zip was built with
`scripts/build-release.sh` on the test VM (19 MB; no `.env`, app tests or dev packages; `public/build` and the guides
inside), the LAN test bed was upgraded from it by `docs/RELEASE.md` §2 (app:doctor showed the new version), and a
first install from it on an empty MariaDB database was walked end to end (installer, forced admin 2FA, club, fee rate,
members, Generate fees twice, a member's first sign-in with the emailed temporary password and forced change).
Still open for go-live: the real-host ToyyibPay / Billplz sandbox run (go-live checklist).

## Breached-password check switch (branch `breach-check-setting`, 23 Nov 2026)

- Portal settings (super admin) gets a **Breached-password check** checkbox, on by default. Only `breach_check = off`
  is stored in `portal_settings`; no row, a v2.0.0 settings cache without the key, a down database or a site not
  yet installed all read as on (`PortalSettings::breachCheckOn()`).
- `Password::defaults()` reads the switch on every validation (reset, change). With it off, only the 12-character
  minimum applies and nothing is sent to Have I Been Pwned.
- The form sends a hidden `0` + checkbox `1`; a request without the field leaves the switch alone. Audited as
  "breached-password check turned off / on".
- Installer: the first super-admin password also gets `uncompromised()` (always on: no settings exist yet; passes if
  the service is unreachable), with a plain message for a leaked password.
- Read as super-admin only: passwords belong to people, not to one club, so a club admin switch doesn't fit.

## Installer checks (branch `installer-checks`)

No separate `installer.php`. `bootstrap/requirements.php` is the one list (PHP 8.4.1, the twelve extensions, the PDO
extension per driver, writable folders) read by `app:doctor`, `/install` and a guard at the top of `public/index.php`
(`bootstrap/preflight.php`, before the autoloader, old-PHP syntax).

Checked first on the test VM (PHP 8.4.28, scratch checkout, `php -n` with every shared extension but the ones left
out, `/install` not installed, file sessions to isolate the extension):
- Missing `ctype`, `curl`, `dom`, `fileinfo`, `gd`, `mbstring`, `tokenizer`, `xml` (with `dom`, `simplexml`,
  `xmlreader`, `xmlwriter`), `zip` or `iconv`: `/install` still renders (Symfony polyfills), so they are `/install`
  rows only. Missing `pdo`: HTTP 500 (`Class "PDO" not found`). With the default database sessions and a migrated
  SQLite database, a missing `pdo_sqlite` is a 500 too (`could not find driver`). `openssl`, `filter`, `hash`,
  `session`, `json` are compiled into this PHP and could not be left out: `openssl` is in the guard on reasoning
  (cookie encryption), not tested.
- An unwritable `storage/framework/views` (no compiled views yet; the log shows `tempnam()` falling back to the temp
  folder) or `bootstrap/cache` (no package manifest yet; cause not captured): 500. Both are in the guard before
  install. Without a `.env` the guard asks for no PDO driver (the database isn't chosen yet).
- The minimum version: `vendor/composer/platform_check.php` refuses below `PHP_VERSION_ID 80401`, so 8.4.1 (the
  installer and `app:doctor` said 8.4.0). `release.json` now says `>=8.4.1`, and the build refuses a zip whose
  platform check disagrees.
- `/install` before `php artisan migrate`, `SESSION_DRIVER=database` (the `.env.example` default): HTTP 500
  `no such table: sessions`, and with no `database/database.sqlite` yet a 500 "Database file ... does not exist".
  With `.env.example`'s `APP_DEBUG=true` that 500 is Laravel's full debug page (paths, PHP version, the query).
  INSTALL.md step 6 tells people to migrate first; making `/install` work before the tables exist is a separate,
  open item (not changed here).
- No step in `docs/INSTALL.md` or `docs/RELEASE.md` copies or edits `public/index.php`, so the guard ships with the
  release as is (the release zip carries both new files; checked).

Results: the guard on the local PHP 8.3.6 (copy of `public/` + `bootstrap/` with Composer's real platform check)
answers 503 "This portal needs PHP 8.4.1 or newer ... choose PHP 8.4" with `no-store`, `nosniff`, `noindex`, where
`master` gives Composer's 500. On the VM: missing `pdo_sqlite` or `pdo` → the 503 page naming them; unwritable
views → the 503 page; missing `zip` or `mbstring` → `/install` 200 with the failing row, its fix and the button
disabled. PHPUnit on the VM: 1048 tests pass (new `PreflightTest`, `RequirementsTest`, extended `InstallationTest`).
Not proven: `php -l` under an old PHP (no Docker or old PHP on this machine or the VM); a token scan in
`PreflightTest` covers it, and a CI job lints the three files on PHP 5.6 and 7.4 (passed on PR #35).

## Language files, phase 1 (branch `i18n-language-files`, 23 Nov 2026)

A move with no visible change, so later languages are a file each. Member side only; Malay stays the default.

- **What moved.** The 314 `{bm, en}` entries of the `dict` in `resources/js/lib/i18n.ts` and the month names (long
  in `i18n.ts`, short in `lib/format.ts`, keys `month_1..12` / `monthShort_1..12`) are now `lang/member/ms.json` and
  `lang/member/en.json`, written by `scripts/i18n-extract.mjs` from master's source. Vite bundles both (no fetch; the
  service worker and CSP need nothing). Each language is merged over English, and `DictKey` comes from `en.json`, so
  `tsc` still catches a wrong key. 35 new keys hold the text that was inline in components: chart labels and
  summary, money-flow diagram, theme toggle, dialog/sheet "Close", uploader defaults, the "Others" slice, and the
  Malay fund statuses the admin tooltip shows. Every `lang === "bm"` style branch is gone from components (Charts,
  CategoryBar, Diagrams, ThemeToggle, ReviewPay, PayResult, ReceiptCard, MemberShell, FileUploader, dialog, sheet,
  `format.ts`, `app.tsx`); they use `textIn` / `textsIn`, `localText` (data sent in Malay and English),
  `localeTag`, `LANG_LABELS`. Pinned Malay text (the WhatsApp message, "Members see") uses `textIn(key, "ms")`.
  Unused `bm` fields of `attachmentKinds` and the money-flow `verb` field were dropped.
- **Codes.** `Lang` is `"ms" | "en"` (the keys of the file map). Malay is still **written** as `bm`: the `cp_lang`
  cookie, `cp-lang` storage and the sign-in switch links (`?lang=bm`), because master's readers accept only
  `bm`/`en` and a tab or sign-in page left open would drop `ms`. Reading accepts `bm` and `ms` through one
  normaliser per side: `normaliseLang` / `storedLang` in `i18n.ts`, `App\Support\UiLang` in PHP (`AuthPageLocale`,
  `layouts/auth`, the branded and 429 error pages). An unknown value still means Malay.
- **Laravel and short keys.** Checked on the VM (tinker): `__('navHome')` returns `navHome` under both `ms` and `en`
  (a key missing from a JSON file shows the raw key, no fallback, and `lang/member/*.json` is not loaded by
  Laravel); with `en` and the fallback locale set to `ms`, `__('Password')` still gives `Password`, so JSON
  keys do not fall back either). So the short keys must never go through `__()`; `lang/ms.json` (sentence keys) is unchanged.
- **Proof.** Value identity: `node scripts/i18n-extract.mjs --extra-ok` (ref 06e97d6 by default) compares every value
  with master's `dict` and month arrays in both directions: 676 values identical (314 entries + 24 month names, × 2
  languages), 0 problems. `tsc --noEmit` and `npm run build` pass (locally and on the VM). New PHPUnit tests:
  `tests/Unit/MemberLangFilesTest.php` (every file has exactly `en.json`'s keys and `{placeholders}`, no empty text,
  all month keys; a scan of `resources/js` that fails on any `"bm"` literal, inline `bm:` pair or comparison with
  `"bm"`/`"ms"`/`"en"` outside the i18n module; on master the scan fails as expected), `tests/Unit/UiLangTest.php`,
  and in `AuthPagesTest` `?lang=bm|ms` and a `bm|ms` cookie both give Malay while the cookie, `cp-lang` and switch
  links still say `bm`. `OverdueWordingTest` reads `ms.json` now. Full suite on the VM (SQLite): 1071 passed.
- **Visible text, master vs branch.** Same seeded SQLite database (demo + e2e seeders, copied fresh per run), each
  ref built and served in turn on :8099, Selenium on Kali capturing body text, title, `<html lang>`, every
  `aria-label` / `title` / `alt` / `placeholder` / `lang` attribute, console errors, and the `cp_lang` cookie and
  `cp-lang` value after each switch. 43 captures per run: sign-in, password reset request, 2FA challenge, member
  Home, Payments, Fund, receipt, unpaid invoice, Security, PayResult paid / pending / failed, Review & pay (FPX,
  transfer form, after a slip: verifying, then toggled to BM and back), Pay all review, admin Dashboard and Fund
  Usage list, in Malay and English (switch link on sign-in, BM/EN toggle in the app). **0 differences.** One console
  error on both (Security: "Unable to encrypt history", plain http on a LAN address).
- **Not proven in a browser:** `MoneyFlowDiagram` (no caller), the Dev UiKit page, uploader defaults in Malay (member
  pages pass their own copy), the CollectedSpentChart in Malay (only the Dev page uses it; its summary now takes the
  label words in both languages, identical with the default labels).

**Adding a language now:** `lang/member/<code>.json` (copy `en.json`, translate every value, keep the
`{placeholders}`), import it and add it to `FILES`, `LANG_LABELS` and `LOCALE_TAGS` in `resources/js/lib/i18n.ts`
(`tsc` asks for the last two), add the code to `App\Support\UiLang` (`CODES`, `LABELS`, `NAMES`), and Laravel's
`lang/<code>.json` plus `lang/<code>/{auth,passwords,validation}.php` for the sign-in pages and messages. Still to do
before that: the BM/EN toggle UI for more than two, emails, a saved per-user language, PHP strings.
