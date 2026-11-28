# CLAUDE.md

Guidance for AI assistants working in this repository. It describes what the code does; when it disagrees with
the code, trust the code and fix this file.

## Project overview

**Club Portal**: a self-hosted Laravel app for small Malaysian staff clubs. Members pay fees by FPX (ToyyibPay or
Billplz, chosen per club) or by free bank / DuitNow transfer with a slip that an admin verifies. Admins record
spending ("Fund Usage": expenses and welfare cases) with documents and two-person approval. Also: installer, TOTP
2FA, roles, CSV member import, ledger with CSV/PDF export, audit log, installable PWA. Deployed on cPanel (PHP only,
prebuilt assets). User-facing docs: `README.md`, `docs/INSTALL.md` (first install), `docs/RELEASE.md` (build and
upgrade), `docs/USER-GUIDE.md`, `SECURITY.md`, `CREDITS.md` + generated `docs/THIRD-PARTY-LICENSES.md`.

## Stack

- **PHP 8.4** (`composer.json` and `composer.lock` need it), **Laravel 13**.
- SQLite by default (dev and tests); production target is MariaDB 10.11 on cPanel (`DB_CONNECTION=mariadb`). SQLite ignores VARCHAR lengths: run the suite on MariaDB too. `database` driver for session, cache (locks, rate limits) and queue; mail `log` locally.
- **Two front ends side by side:**
  - **Inertia 2 + React 18 + TypeScript + Tailwind 3** (Vite) for member pages and the admin Dashboard, Fund Usage and
    Verifications. Root view `resources/views/app.blade.php`, pages in `resources/js/Pages/{Member,Admin}`, shared
    components in `resources/js/components/{app,ui}`, member strings in `lang/member/<code>.json` (short keys,
    English is the reference; never through `__()`), loaded and typed by `resources/js/lib/i18n.ts` (every language
    choice goes through its helpers, never `lang === "…"` in a component, see `tests/Unit/MemberLangFilesTest.php`;
    Malay is still stored as `bm` in `cp_lang` / `cp-lang` / `?lang=`, read through `normaliseLang` and
    `App\Support\UiLang`, whose `available()` is the list of `lang/member/*.json` files; i18n.ts builds the same
    list with `import.meta.glob`, so a language is files only, see "Member language"), theme in
    `resources/js/lib/theme.tsx`. Which language a member gets: see "Member language" under Domain rules. Props are shared by `HandleInertiaRequests`.
  - **Blade + Tailwind** (same tokens, `resources/css/app.css` `.auth-*`, layout `layouts/auth.blade.php`) for the
    sign-in pages: login (also served at `/`, the front page, with a "how to join: contact your admin" section),
    2FA challenge and password pages.
  - **Blade + Bootstrap 5** (bundled by Vite: `resources/css/classic.css`, `resources/js/classic.ts`; the pinned
    jsDelivr copies with SRI only as a fallback when `public/build` is missing, see `App\Support\FrontendAssets`) for the installer and the remaining admin pages
    (members, payments, fee rates, job levels, discounts, categories, club profile, ledger, audit logs, clubs, admins,
    portal settings, statistics, classic dashboard; billing settings is Inertia). Use Bootstrap classes there, Tailwind only in the React pages.
- `pragmarx/google2fa`, `barryvdh/laravel-dompdf`, Recharts (React), Chart.js (Blade; bundled by Vite, jsDelivr only
  as the fallback without a build).

## Layout

```
app/Contracts/                 PaymentGateway, ConfirmsWebhookPayments
app/Http/Controllers/Admin/    Club, ClubProfile, ClubBilling, PortalSettings, PrivacyNotice, Member, FeeRate, JobLevel, Payment, FeeGeneration,
                               FundUsage, Attachment, TransferVerification, Dashboard, Ledger, Statistics, AuditLog, AdminUser, Profile, ...
app/Http/Controllers/Member/   Dashboard, Payment (review, pay, status, transfer, slip, invoice, generateFuture), Fund, PayAll
app/Http/Controllers/          InstallController, WebhookController, HomeController, PrivacyController, AppManifestController,
                               FallbackController, Auth/*
app/Models/                    User, Club, Payment, PaymentBill, PaymentCancellation, Expense, WelfareCase, Attachment, FeeRate,
                               Discount, ExpenseCategory, AuditLog, ClubJobLevel, PortalSetting
app/Services/                  AuditService, AttachmentService, LogoService, FundUsageService, TransferVerificationService,
                               Payments/{SettlementService, FeeBillService, PaymentCancellationService, MonthlyFeeGenerator},
                               BankDetailsChangeService, PaymentGateways/{PaymentGatewayManager,ToyyibPayGateway,BillplzGateway}
app/Support/                   Portal/{PortalSettings (portal name, logo, brand key, breach_check: only "off" stored, default_locale: only a non-Malay code), BrandPalette (colour presets), AppIcons (PWA icons), PrivacyNotice}, UiLang + UserLocale + MailText (member language, see Domain rules), presenters/stats (Admin, Member), Payments helpers (OwnFeeRule, GatewayFee,
                               FeeRates, WebhookSecret, BillplzSignature, ...), Export/CsvSafe, Auth/AccountLoginBlock,
                               Doctor/HostProbe (app:doctor, /install), Requirements, ReleaseInfo, SchedulerHeartbeat, FrontendAssets
app/Providers/                 AppServiceProvider (rate limiters), E2eFakeGatewayServiceProvider (local + flag only)
routes/web.php                 all HTTP routes; routes/console.php schedules overdue reminders daily 08:00 in the app timezone (APP_TIMEZONE; Asia/Kuala_Lumpur recommended = 8 am Malaysia, UTC when unset; phpunit runs UTC),
                               payments:reconcile-open-bills every 15 min, the scheduler heartbeat every minute
                               (SchedulerHeartbeat, read by app:doctor) and backup:run daily 02:15 Asia/Kuala_Lumpur
database/seeders/              DatabaseSeeder (demo, local/testing only), E2eSeeder (e2e fixtures)
public/sw.js, manifest.webmanifest   PWA (bump CACHE_VERSION every release); the live manifest is the routed /app.webmanifest, the static file a fallback
public/update/                 the standalone updater (index.php + lib/, namespace ClubPortalUpdater, plain PHP, no Composer); public/update-guard.php = swap guard
app/Support/Updates/           Manifest, UpdateCheck, PreChecks, UpdateJob, UpdateOutcomes, PausePage, UpdaterFiles, UpdaterHost (the Laravel side of the updater)
app/Console/Commands/AppUpdate app:update [--check|--yes|--finish]; release-policy.json + scripts/{build-release.sh,release-manifest.php} build the manifest
scripts/licenses.mjs           writes docs/THIRD-PARTY-LICENSES.md (npm run licenses)
mockup/                        standalone design mockup (sample data only; see mockup/README.md)
tests/Feature/{Admin,Member}, tests/Feature/*.php, tests/Unit, tests/e2e/smoke.mjs (Playwright)
```

## Routes and middleware

- `/install` (`redirect_if_installed`, POST throttled) needs `INSTALL_TOKEN` (or `storage/app/.install-token`), 16+ chars.
  Its requirement rows (PHP, every extension, writable folders, `.env`; reads `HostProbe`, so tests fake a host) and
  `app:doctor` take the list from `bootstrap/requirements.php` (`App\Support\Requirements`); change requirements there only.
- `public/index.php` first calls `clubportal_preflight()` (`bootstrap/preflight.php`, before the autoloader): a 503
  "Server setup needed" page on PHP below the list's version, and before install for a missing boot extension / PDO
  driver or unwritable views / `bootstrap/cache`. That file, `bootstrap/requirements.php` and `public/index.php` must
  parse on old PHP (`array()`, no types, `fn`, `match`, `?->`, `??`): Pint skips the two bootstrap files, CI lints
  them on PHP 5.6 / 7.4, `PreflightTest` scans their tokens. Keep the installed path to a version compare + `file_exists`.
- `POST /webhook/toyyibpay` and `/webhook/billplz`: outside `check_installed`, no auth, `throttle:webhooks`, and
  `withoutMiddleware(PreventRequestForgery::class)`. Laravel 13's web group runs `PreventRequestForgery`; excluding
  `VerifyCsrfToken` does **not** work (tests skip CSRF, so only `WebhookCsrfExemptionTest` / e2e catch it).
- Everything else is inside `check_installed` (needs `storage/app/.installed`), then
  `['auth', 'auth.session', 'two_factor', 'password_changed', 'admin_two_factor']`:
  - `password_changed` (`EnsurePasswordChanged`): `users.must_change_password` (set where an admin emails a temporary
    password: member store, CSV import, admin store) sends the user to `password.change` (Blade, auth layout) until
    done; only that page, its language switch (`member.language.update`) and `logout` pass. Cleared there and by a password reset.
  - `admin_two_factor` (`RequireAdminTwoFactor`): with `config('security.require_admin_two_factor')`
    (`REQUIRE_ADMIN_2FA`, default true) an admin (`isAdmin()`) without 2FA only gets `profile.security`,
    `profile.2fa.enable|confirm`, `password.change*` and `logout`. `Tests\TestCase` sets it false; turn it on in a
    test that needs it. Both redirect with `Inertia::location` (409 for Inertia visits).
  - `member` group, prefix `/my`: member pages. `MemberOnly` only checks auth (admins can use it too).
  - `super_admin` group, prefix `/admin`: clubs, admins, statistics, platform audit log, portal settings
    (`admin.portal-settings.edit|update`), privacy notice (`/admin/portal-settings/privacy`,
    `admin.privacy-notice.edit|update`; named apart so `admin.portal-settings.*` doesn't match it).
  - `club_admin` group, prefix `/admin`: shallow resources under `/admin/clubs/{club}/...`, Fund Usage, verifications,
    attachments, club profile (`admin.clubs.profile.edit|update`), billing, ledger, Cancel payment
    (`GET|POST /admin/payments/{payment}/cancel`, `admin.payments.cancel-form|cancel`), Generate fees
    (`GET|POST /admin/clubs/{club}/payments/generate`, `admin.clubs.payments.generate[.store]`, before the payments resource).
- `GET /privacy` (`privacy`): public privacy notice, inside `check_installed` + `auth_locale`, no `auth`/`guest`
  (members open it too). Text from `App\Support\Portal\PrivacyNotice` (portal_settings keys `privacy_notice_ms|en`,
  `privacy_contact_name|email`, kept out of `PortalSettings::KEYS`); plain text only, escaped, paragraphs from blank
  lines; a neutral placeholder when unset. Never ship default legal wording.
- `GET /app.webmanifest` (`pwa.manifest`, `AppManifestController`): outside `check_installed`, no auth, and the
  session / cookie / CSRF / Inertia middleware removed (no Set-Cookie). It only reads settings: never generate icons there.
- `/two-factor-challenge` deliberately has **no `auth`**: login logs the user out and stores `two_factor_user_id`;
  the controller gates on that session key. Don't add `auth` there.
- Put literal child routes (e.g. `members/import`) **before** `Route::resource(...)->shallow()`.
- In production URLs are built from `APP_URL` and other hosts are refused (`trustHosts`).

## Domain rules (keep them)

- **Payment.status**: `pending | paid | overdue | pending_verification`. `transfer_*` / `verified_*` are not fillable;
  only `TransferVerificationService` sets them. `paid_via` is the gateway name, `transfer`, or null (manual).
- **Bills**: every gateway bill is a `PaymentBill`; `pay` is POST-only and runs under a per-fee cache lock. ToyyibPay
  callbacks carry a per-bill HMAC token and are confirmed with `getBillTransactions` before settling; Billplz uses
  X-Signature. Webhooks fail closed (secret < 32 chars or placeholder = refuse) and are idempotent.
- **Maker-checker**: Fund Usage approve (different admin, not the welfare beneficiary, CoI declaration), void
  (request + confirm by another admin), bank/DuitNow detail changes (`BankDetailsChangeService`), and `OwnFeeRule`
  (a club admin can't settle, verify, edit, cancel or delete their own fee; super admin exempt). Only the super admin grants the club-admin role.
- **Counted totals**: only `approved` expenses count (`Expense::scopeCounted`); money is summed in sen.
- **Job levels are per club** (`club_job_levels`, `ClubJobLevel`, `Club::jobLevels()`): `club_user.job_level` and
  `fee_rates.job_level` hold the level's stable `code` (slugged once from the first name, never changed); show the
  `name` with `$club->jobLevelName($code)` (falls back to the code). Validate input with
  `Rule::in(array_keys($club->activeJobLevelNames()))`; display lists use `jobLevelNames()` (inactive included). A level
  in use can only be deactivated. New clubs get one level (`createDefaultJobLevel()`: `member` / "Ahli"); factory
  clubs get `executive` and `manager` (`ClubFactory::withJobLevels()` to change). Never hard-code a level list.
- **Attachments**: encrypted with `Crypt` on the private `local` disk (`serve => false`), served only via
  `admin.attachments.show` (audited) / `thumb` (not audited) or the member's own slip route. Locked once submitted.
- **Logos** (club and portal) only through `LogoService`: PNG / JPEG / WebP (by header, extension and detected MIME;
  never SVG or GIF), 2 MB, 4000 px; re-encoded with GD to a PNG of max 512 px at `club-logos/{uuid}.png` or
  `portal/{uuid}.png` on the `public` disk. Never delete an old logo file (receipt snapshots point at it). Build URLs
  with `LogoService::url()` / `Club::logo_url` (null when the file is gone).
- **Receipt snapshot**: `payments.issuer_snapshot` (not fillable) is written once by `Payment::booted()` (saving)
  when a fee becomes paid, from `Club::issuerSnapshot()`; never overwritten while paid (a revert clears it). Receipts use
  `MemberPaymentPresenter::issuer()` (snapshot, else the live club for older fees); invoices use the live club. The
  club's receipt footer (`clubs.receipt_footer`, plain text, max 300) is in the snapshot; an older snapshot without a
  `footer` key shows none. Render it escaped (`whitespace-pre-line` in React, `nl2br(e())` in Blade).
- **Receipt numbers**: `{PREFIX}-{FY start year}-{NNNN}` from `App\Support\Payments\ReceiptNumbers::allocate()`,
  per club and financial year (the year of the paid date, fixed at issue; `ReceiptNumbers::allocate(..., $paidOn)`), prefix `clubs.receipt_prefix` (read at issue time).
  One fee: `Payment::booted()` saving hook, when it becomes paid and `receipt_no` is null (next to the snapshot). A
  group bill / slip: `SettlementService::markGroupPaid()` and `TransferVerificationService::approveGroup()` allocate
  once with the bill id and set `receipt_no` + `receiptAllocationId` on each fee **before** saving it (so the hook
  skips it). `payments.receipt_no` is not unique (a group shares it); uniqueness is on `receipt_allocations`
  (club + number, club + year + seq). Leaving paid (Cancel payment) clears `receipt_no` and voids its `receipt_allocation_payments` row;
  never reuse a number, never promise gap-free. Display with `Payment::receiptNumber()` /
  `MemberPaymentPresenter::groupReceiptNo()` (legacy `RCP-{id}` / `RCP-G{id}` when null). Not fillable.
- **Fee amount**: only through `App\Support\Payments\FeeRates` (`monthlySen($club, $user, $month)`,
  `monthlySenByMonth()`, `inForce()`): the member's `club_user.job_level` → the rate with `effective_from` ≤ the
  month's last day and `effective_to` NULL or ≥ its first day, ignoring rows with `effective_to` < `effective_from`
  (`FeeRates::superseded()`: replaced by a backdated rate, kept as history); the latest `effective_from` wins (then
  the highest id); no rate = null, RM 0 = 0 (a waived month). Saving a rate sets `effective_to` = its
  `effective_from` − 1 day on every rate of the level that is open or ends on/after that day; deleting the open rate
  reopens the predecessor that ended the day before it started (when no open rate remains). Migration
  `close_fee_rate_gaps` extended legacy rates closed early. Pay ahead charges the sum of each covered month's rate
  (refused on a month with no rate or a RM 0 total); Generate fees one month's (RM 0 = skipped "Rate is RM 0").
  Never read "the current rate" with `whereNull('effective_to')` for billing.
- **Generate fees** (`App\Services\Payments\MonthlyFeeGenerator`, `Admin\FeeGenerationController`): this month or
  next month only; due date max(today, the month's 1st) .. end of the following month. `preview()` gives every member
  one outcome (in this order: inactive membership, joined after the month, already has any fee overlapping the
  month, no rate, rate is RM 0); the POST recomputes it and refuses when count, total (sen) or
  `MonthlyFeeGenerator::fingerprint()` (HMAC, app key, of sorted `user_id:sen` + month + due date) differ from the
  hidden fields the preview posted. Each fee: the `pay-ahead:{club}:{user}` cache lock (outside the transaction),
  active `club_user` + active club re-check (else skipped inactive), overlap re-check + insert + `payment.created`
  audit (with the run key) in a small transaction, `UniqueConstraintViolationException` = already billed; a held lock
  = skipped "busy". One `payment.generated` summary row. No mail. A deleted fee is re-created on a rerun (known).
- **Overdue reminders** (`payments:send-overdue-reminders`): only fees of an active club whose member has an active
  `club_user` row in that club; others stay `pending` (no flip, no mail) and are reminded once the member is active
  again (stored `overdue` = already reminded).
- **Cancel payment** (`App\Services\Payments\PaymentCancellationService`, `admin.payments.cancel-form|cancel`): the
  only way a paid fee goes back to unpaid (`PaymentController::update()` refuses a status change of a paid fee).
  Reason codes per `paidMethod()` (slip / manual / gateway; `REASONS`), validated on the server; note internal (never
  sent to the member). A fee whose `receipt_no` matches a group `payment_bills` row (payment_id NULL) is cancelled
  with every fee still carrying that number (never match a NULL number). A `payment_cancellations` row per fee is
  written **before** the save (it copies receipt no., snapshot, paid fields); the fee is saved through Eloquent to
  `pending` (the hook voids the number), clearing paid_via/gateway/verified_*/transfer_* and `transfer_group_id`
  (the set stays traceable via `payment_bill_items` + `payment_cancellations.payment_bill_id`). Exception: an online
  payment that arrived while the fee's slip (its own, or a set's still open) was being checked goes back to
  `pending_verification` with its transfer fields kept, so the slip can still be decided. `thankyou` of a cancelled
  payment redirects to the invoice. Never clear
  `payments.transaction_id` or the bill's `transaction_id`/`paid_at` there: `transactionAlreadyUsed()` (which also
  reads `payment_cancellations`) refuses replays, and `markPaymentPaid()` never settles a fee again through a single bill with `paid_at` set (a late callback under another id). One `payment.cancelled` audit row per fee, one `PaymentCancelled`
  email per cancel after the commit (a mail failure is logged only). Member props: `MemberPaymentPresenter::cancellation()`
  / `groupCancellation()` (receipt no., date, reason code only).
- **Financial year**: "this year" for a club is `FundFigures::yearToDate($today, $club)` /
  `financialYearStart()` / `yearLabel()` (`clubs.financial_year_start_month`, 1 = calendar year, label "FY 2026/27"
  otherwise). Don't use `startOfYear()` for a club's year. The classic dashboard and the member payments list still
  browse fees by calendar year (deliberately).
- **Portal name**: `PortalSettings::apply()` sets `config('app.name')` at boot (cached, `rescue()`d, skipped before
  install; `app.default_name` keeps APP_NAME). Pages must render with the table empty or the database down.
  `PortalSettings::put()` clears the cache and re-applies.
- **Brand colour**: only a `BrandPalette::PRESETS` key is stored (`portal_settings.brand`; lavender = no row); never
  accept a hex or CSS. `partials/brand-style` (in `app.blade.php`, `layouts.auth`, `layouts.app`) emits one nonced
  `<style>` built from the constants that re-points the brand tokens (primary, ring, deep, accent, info + foregrounds)
  and the four Bootstrap triplets; nothing for lavender, so tokens.css renders as built. Neutrals, status colours,
  charts and `.paper` receipts stay fixed. A new preset or token must pass `tests/Unit/BrandPaletteTest.php` (WCAG
  contrast); lavender must equal tokens.css. Browser-bar colours come from `pwa-head` (`window.cpThemeColor`).
- **App icons** (`AppIcons`): GD-made from the portal logo at `portal/icons/{hash}-*.png` (hash = logo path + preset +
  `VERSION`), made only on Portal settings save / open; pages and the manifest fall back to `public/icons` when the
  set is missing. Old icon files are kept.
- **Member language** (member side only; admin pages and admin emails stay English): one rule,
  `App\Support\UserLocale::resolve($user, $request)`. A signed-in member and every member email: `users.locale`
  (saved; not fillable) → `PortalSettings::defaultLocale()` (Portal settings `default_locale`, only a non-Malay code
  stored; missing/unreadable = `ms`). A guest (and an admin on a sign-in page): the browser's choice (`?lang=`, else
  the `cp_lang` cookie) → the portal default. **An account's language is written only by `UserLocale::save()` from
  the owner's own `POST /my/language`** (`member.language.update`, `throttle:language`, its own limiter: a plain
  `throttle:N,1` shares one per-user counter with pay; allowed by `EnsurePasswordChanged`; JSON = 204, a form =
  back). Never save from a cookie, `cp-lang` storage or `?lang=` (a shared browser would hand one person's language
  to the next). Used by `AuthPageLocale` (a signed-in member's `?lang=` is ignored and their switch is a POST form,
  `$authLangForm`), `HandleInertiaRequests::locale()` (members and guests; admins get the app locale) and app.tsx
  (`props.langSave` `{url, saved}`: a member opens in their own unsent choice, `lib/langSave.ts`
  `cp-lang-unsaved` = `<user id>:<code>:<ms>`, dropped after 24 h and resent with its original time, else the
  server's answer; never `cp-lang`; with nothing saved, never write the portal default into `cp-lang` / `cp_lang`).
  The branded error pages (`errors/_branded`, 429) still read `cp_lang` directly (no session there).
  **Emails**: `User` implements `HasLocalePreference` (`preferredLocale()` = `UserLocale::forMail()`: saved → portal
  default for members, English for admins; no request, the password reset link included), which Laravel applies
  only when `Mail::to()` gets the **model**: always pass the User (`MemberEmailLanguageTest` scans `app/` for
  `Mail::to(...->email)`; only `ClubAdminNotice` in `Club::notifyAdmins` is addressed by string, pinned to English
  with `->locale()`). Subjects are built in `envelope()` (runs under the recipient's locale), never in the
  constructor. Email text: `__('English sentence')` with the Malay in `lang/ms.json` (bold words as `**…**` via
  `App\Support\MailText::bold()`), dates with `UiLang::date()` (the member app's month names, not Carbon's). Every
  key needs its `lang/ms.json` entry (the test checks); new Malay goes in `docs/i18n-new-wording.md` for the owner.
- **Language files** (`docs/i18n-translator-brief.md` is the translator's guide and lists what each language needs):
  - **`_meta`**: each `lang/member/<code>.json` starts with `"_meta"` (name, label, htmlLang, localeTag, beta), read
    by `langMeta()` / `UiLang::meta()`, never English's. `<html lang>`, `lang=` and `hreflang` use `htmlLang`
    (Chinese is `zh-Hans`), never the code.
  - **Pickers**: the member app picker (`LangToggle` in MemberShell, a native `<select>`) and the sign-in picker
    (`layouts/auth`, a `<details>` list: `?lang=` links for guests, a POST form to `member.language.update` for a
    signed-in member, `$authLangForm`) show `label` closed, and `name` plus "(beta)" when open.
  - **Dates**: never hard-code a date order. Use `fmtDate` / `fmtMonth` / `fmtPeriod` / `fmtDateTime` and
    `UiLang::date()`, which follow the pattern keys (`dateFormat`, `monthYearFormat`, `periodSameYear`,
    `periodRange`, `dateTimeFormat`, `timeFormat`).
  - **Counts**: `<base>_one` / `<base>_other` keys through `countText` / `useCountText` (`Intl.PluralRules`); in
    emails, `trans_choice('… :count …|…')`. Never `n === 1 ? …`.
  - **Sentences**: one key per sentence. Never join translated pieces or lowercase a translation in code.
  - **Server text**: member flashes and errors are `member.*` codes (`useServerText`), because member routes never
    set the locale.
  - **Wording**: all Malay is Malaysian Malay (Bahasa Malaysia, DBP usage), never Indonesian words or spellings
    (owner's rule). Readers are in Malaysia, so Chinese is Simplified as used in Malaysia and Tamil is Malaysian
    usage. New Malay goes to the owner (`docs/i18n-new-wording.md`).
  - **Pseudo-language**: `php scripts/i18n-pseudo.php` writes the made-up language `xx` (bracketed, 40% longer) into
    a test or dev copy, to find hard-coded text. It is gitignored, never shipped, and refused under
    `APP_ENV=production`.
  - **Tests**: `MemberLangFilesTest` (keys, placeholders, `_meta`, pattern keys, count pairs), `LaravelLangFilesTest`
    (every `lang/<code>.json` covers `lang/ms.json`; every member language has its Laravel files), `UiLangTest`
    (the TS and PHP lists built the same way). `tests/Support/FakeMemberLanguages` adds a temporary third language
    in a test.
- **Encrypted at rest**: club gateway secrets (casts), welfare personal fields (casts), attachments, and
  `users.two_factor_secret` / recovery codes (manual `encrypt`/`decrypt`; use `User::getTwoFactorSecret()`).

## Lock order (MariaDB / MySQL)

Every transaction that settles or decides fees takes row locks in this order, inside one `DB::transaction` (PR #25
fixed deadlocks from mixing it up): `payment_bill_items` (shared, group settlement) → `payments` (`lockForUpdate`,
by id) → `payment_bills` (the bill being settled) → `receipt_counters` (one row per club + financial year). Group slip
approval locks its `payment_bills` row before its fees; that row is only reached through the slip. The receipt
counter is always taken after the caller's own locks; rows locked after it (the fee's own bill, open group bills
covering the fee, via the Payment `saved` hook) are reached only through fee rows the transaction already holds.
Cancel payment follows the path that settled the set: a slip group's `payment_bills` row, then its fees by id (as
slip approval); an online group bill after its fees (as `markGroupPaid`); no receipt counter.
Advance the counter with the upsert in `ReceiptNumbers::allocate()` (never "SELECT … FOR UPDATE, insert if
missing": a locking read of a missing row takes a gap lock and two first receipts of a year deadlock). A deadlock
(1213) rolls back the whole transaction: never catch it and carry on.

## Patterns

- **Shallow-route auth**: controllers for shallow resources use `Concerns\AuthorizesClubResource` and call
  `$this->authorizeClubAdmin($model->club)`.
- **Audit every write**: `AuditService::log($action, $description, $model, $clubId, $old = [], $new = [])` (positional).
- **CSP nonce**: inline `<script>`/`<style>` in Blade needs `nonce="{{ $cspNonce }}"`. Fonts, Bootstrap and
  Chart.js are self-hosted from `public/build` (no Google Fonts); `cdn.jsdelivr.net` is allowed only when the build is
  missing (`FrontendAssets::classicBundled()` false, see `SecurityHeaders`); form-action allows the ToyyibPay and Billplz hosts. Images: `'self' data:` (+ ASSET_URL host) only, so logos are
  served same-origin from `/storage` via `asset()`.
- **Sign-in header** (`layouts/auth.blade.php`, `authBrand` from the AppServiceProvider composer): the one active
  club's name + logo, else the portal name + logo. New Tailwind classes there must be in a file Tailwind scans.
- **Rate limits**: named limiters in `AppServiceProvider`: `login` (5/min per account + IP, 30/min per IP; a hit is a
  `Lockout` event); `two-factor` (5/min per pending user + IP, 30/min per IP); `password-email`, `password-reset`,
  `password-confirm` (each 5/min per account + IP, 30/min per IP); `password-change` and `two-factor-setup` (5/min per
  user); `install` (5/min per IP); `webhooks` (300/min per IP); `language` (20/min per user, the member language
  switch); CSV import `throttle:5,1`. Every plain `throttle:N,1` is keyed on the user (or IP) alone, with no
  prefix, so all of them share one counter per user: give a route that must not eat into another's budget a named limiter. Per-account sign-in pause: `App\Support\Auth\AccountLoginBlock` (keyed on the user id, 10
  failures / 15 min; config `security.account_login`; the "paused" email at most once an hour). Every failed
  sign-in takes at least `auth.timebox_duration` (500 ms, `AUTH_TIMEBOX_MICROSECONDS`) so unknown emails are not faster. Sign-in audit rows and owner emails:
  `App\Listeners\AuthSecurityListener` (auto-discovered: don't register it by hand). Mail that must not slow the
  answer: `App\Support\AfterResponse` (no queue worker on cPanel);
  Fund Usage / verification / billing writes, portal settings, privacy notice and club profile updates `throttle:20,1`;
  pay, pay-all, slip upload, pay-ahead and Cancel payment `throttle:10,1`; every numeric throttle names its own counter
  (`throttle:N,M,<name>|`; pay + pay-all share `member-pay|`, the two transfers `member-transfer|`): a bare
  `throttle:N,M` keys by the user alone and would share one counter with every other one (`ThrottleCountersTest`); Generate fees POST `throttle:6,1`; opening
  a slip or attachment (member slip, `admin.attachments.show`) `throttle:60,1`, attachment thumbnails `throttle:300,1`.
- **Exports**: `Cache-Control: no-store`, attachment disposition, `CsvSafe` for formula cells.
- **Gateway access** goes through `PaymentGatewayManager::for($club)`, never a concrete gateway.

## In-app updater (v2.2.0+)

Spec `docs/superpowers/specs/2026-11-27-in-app-updater-design.md`; user/host docs in `docs/RELEASE.md` §2, `docs/INSTALL.md` §7, `SECURITY.md`.

- **Layout.** The Updates page and routes (`/admin/updates*`, super admin) and `app/Support/Updates/*` live in Laravel. The
  engine is `public/update/lib/*` (`Steps`, `Journal`, `Lock`, `Swap`, `Unpack`, `Download`, `ZipSafety`, `Recovery`, `Outcome`,
  `Report`, `Page` for every screen, `Auth` for the `cp_update` token) behind `public/update/index.php`. It must not use
  Laravel, Composer or the database except in the steps that boot the new app on purpose (`BootedApp`/`LaravelApp`).
  `app:update` drives the same `Steps`. State is files under `storage/app/updater/` (`job.json`, `journal.json`, `lock`,
  `outcome.json`, `history/`, `webhooks/`, `blocked`); one `flock` serialises every step and recovery.
- **The installed release's updater runs every update**, never the new one: a bug fix to the engine reaches a site one
  release later, and the engine must keep working with the next release's zip layout. `public/update/` and
  `public/update-guard.php` are moved in by the last step (`finish`), not by the swap.
- **The guard** (`public/update-guard.php`, first lines of `public/index.php` and `artisan`) must **parse and run on PHP
  5.6**: no short arrays, type declarations, `??`, arrow functions, `match`, named arguments. CI job `old-php-parse`
  (`.github/workflows/tests.yml`) runs `php -l` on it under 5.6 and 7.4; add any new file that runs before PHP 8.4 is
  checked to that list. It reads only fixed paths, never a path from the request.
- **`release-policy.json`** (`min_from_version`, `manual_action`) is read by `scripts/release-manifest.php` into the
  manifest; change it per release deliberately. `### What's new` in the CHANGELOG (1-5 bullets, 200 characters) is
  required by the release build.
- **Never touched by a swap:** `.env*`, `storage/`, `database/` except migrations/seeders/factories, `public/update/`,
  `public/update-guard.php`, and files that are not in the old release's `release-files.json` (site-local files are kept
  and reported). `public/.htaccess` is carried over last.
- **Never edit a shipped migration**, and keep migrations additive: the previous release's code undoes a failed update
  against the new schema, and a failed migration blocks the button (`storage/app/updater/blocked`).
- **Public-repo rule for the updater:** no host names, LAN addresses or home paths in code, tests or docs.
- Tests: `tests/Unit/Updates`, `tests/Feature/UpdateGuardTest.php`, `tests/Feature/Admin/UpdatesPageTest.php`, `tests/Feature/AppUpdateCommandTest.php`; they use a
  scratch app folder (`CLUBPORTAL_UPDATER_APP`) and the `AppUpdate::$tune/$child` seams, never the real tree.

## Dev workflow

| Command | What it does |
|---|---|
| `composer setup` | install, copy `.env`, `key:generate`, `migrate --force` (no npm) |
| `npm ci && npm run build` | build `public/build` (needed to serve any Inertia page; tests that render the root view call `withoutVite()`) |
| `npm run dev` / `composer dev` | Vite dev server / `artisan serve` + queue + log tail |
| `php artisan test` (`composer test`) | PHPUnit, SQLite in memory; `RefreshDatabase` + `.installed` marker in `TestCase` |
| `DB_CONNECTION=mariadb DB_HOST=… DB_PORT=… DB_DATABASE=<empty test db> DB_USERNAME=… DB_PASSWORD=… php artisan test` | same suite on MariaDB / MySQL (`phpunit.xml` env isn't forced); must pass on SQLite, MariaDB 10.11 and MySQL 8.0 (CI: `.github/workflows/tests.yml`, every push to master and PR) |
| `npm run typecheck` | `tsc --noEmit` |
| `php artisan db:seed` | demo data, local/testing only; super admin password from `SEED_SUPER_ADMIN_PASSWORD` or printed once |
| `node tests/e2e/smoke.mjs` | browser smoke test; setup in `tests/e2e/README.md` |
| `vendor/bin/pint` | code style (`pint.json`) |
| `npm run licenses` | regenerate `docs/THIRD-PARTY-LICENSES.md` from the lockfiles (`scripts/licenses.mjs`); run after any dependency change |

Known: on PHP 8.3 one `LedgerTest` test fails (the lock needs 8.4).

## Testing conventions

- Helpers in `Tests\TestCase`: `actingAsSuperAdmin()`, `actingAsClubAdmin($club)`, `actingAsMember($club)`.
  `TestCase` also turns off `security.require_admin_two_factor` and fakes the breached-password check
  (`$fakeBreachedPasswordCheck = false` + `Http::fake` to test the real one). New passwords use `Password::defaults()`
  (12+, not breached unless `PortalSettings::breachCheckOn()` is false; read per validation): test passwords must be
  12+ characters. The installer always adds `uncompromised()`.
- Cross-club access to admin resources: `assertForbidden()`. Member routes hide other people's data with 404:
  `assertNotFound()`.
- 2FA challenge tests pre-seed `withSession(['two_factor_user_id' => $id])`.
- Add tests next to the area you touch (`tests/Feature/Admin`, `tests/Feature/Member`, top-level for webhooks/security).

## Pitfalls

- From v2.0.0 (the first production release) migrations are additive only: never edit a released migration, add a new
  one. A database from before v2.0.0 needs `migrate:fresh`.
- MariaDB / MySQL (strict mode) refuse what SQLite accepts: a value longer than its VARCHAR (1406), invalid UTF-8
  (1366; cut strings with `mb_strcut`, not `substr`), and a duplicate that differs only in case or accents under
  `utf8mb4_unicode_ci` (1062; compare emails lowercased). MySQL 8 stores JSON with its keys sorted: never compare or
  hash JSON columns by key order. In migrations, an index that starts with a foreign-key column replaces the FK's own
  index, so a `down()` that drops it must put a plain index back first (error 1553).
- `CACHE_STORE` must not be `array` in production (pay lock, rate limits).
- `bootstrap/app.php`'s `withMiddleware` callback runs before `.env` is loaded: never read `env()` there. Trusted proxies
  come from `config('app.trusted_proxies')` (`TRUSTED_PROXIES`) via `AppServiceProvider::configureTrustedProxies()`.
- Webhook routes: never add `auth` or CSRF; the token/signature is the auth.
- A missing `public/build/manifest.json` breaks every Inertia page; delete a stray `public/hot` before building/uploading.
- Don't relax security defaults (CSP nonces, throttles, encrypted columns, webhook verification, maker-checker) without a reason and a test.

## Working principles

- Surgical changes in the existing style; ask when a request has several sensible readings.
- Before calling a task done: add or update tests, run `php artisan test`, and for front-end changes `npm run typecheck`
  and `npm run build`.
- Keep the docs in step with the code: first-install facts live in `docs/INSTALL.md` (README and `docs/RELEASE.md`
  only summarise or link to it); a new direct dependency also goes into `CREDITS.md`.
