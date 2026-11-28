# Changelog

All notable changes to this project will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [Unreleased]

### What's new
- The super admin can now update Club Portal from the **Updates** page with one button. The portal downloads, checks and installs the new version in about 2 minutes.
- If an update goes wrong or the page is closed, the old version is put back by itself. Members see a "back in a few minutes" page, and payment confirmations received meanwhile are kept.
- The dashboard tells the super admin when a newer version is out, with what is new in it, and the Updates page checks that the site is ready before the button turns on.
- Club admins see a short note when a security fix is waiting, and who to ask to install it.

### Added
- **Update from the admin** (super admin, **Updates** in the sidebar): the page shows the installed and latest version and
  the checks (PHP, the zip and curl extensions, a writable app folder, free disk space, no member paying right now).
  Pressing the button opens the standalone updater at `/update/` (own page, no database, token cookie), which downloads the
  release zip from this repository's GitHub release, verifies its SHA-256 against `clubportal-manifest.json`, unpacks it
  beside the app, saves a database copy (the `backup:run` rule, or asks for a cPanel backup when PHP may not start
  programs), pauses the portal, swaps the code folders with a journaled rollback, runs `migrate --force` and
  `optimize`, checks the new version over HTTP and opens the portal again. Closing the page for 10 minutes, or any failed
  step, puts the old files back (a failed migration is the one case that needs a person: the button then stays off
  until a super admin confirms the data was checked). Never touched: `.env*`, `storage/`, `database/` except
  migrations, seeders and factories, and files that belong to the site.
- `php artisan app:update` does the same from the terminal (`--check` shows the versions, `--yes` skips the question; `--database-backed-up` and `--manual-action-done` answer the two "has this been done" questions when `--yes` is used).
  If the server does not let PHP start programs it stops after the switch and prints
  `php artisan migrate --force && php artisan optimize && php artisan app:update --finish`; run that to finish.
- **Swap guard** (`public/update-guard.php`, the first lines of `public/index.php` and `artisan`): written for PHP 5.6 and
  later so that it can still run while files are being switched. During an update it shows the members' pause page
  (503, `Retry-After: 120`), saves at most 200 payment callbacks of 64 KB to `storage/app/updater/webhooks/`
  (`/webhook/toyyibpay`, `/webhook/billplz` only) and the portal processes them after it opens again.
- **Result screens, history and email**: the outcome (done, put back, stopped before anything changed, could not be
  undone) shows on the updater page and the Updates page with **Copy report** and **Report a problem**, in the audit log,
  in a notice for every super admin until dismissed, and in an email when mail is set up. The report never contains
  `.env` values, tokens, cookies or database passwords.
- `release-policy.json`: a release can say which version a site must be on before it can use the button
  (`min_from_version`) and a thing to do by hand first (`manual_action`); the release build puts both into the manifest.
- `docs/INSTALL.md`: what the updater needs from the host (zip and curl extensions, writable app folder and its
  parent, free space, an nginx location for `/update/`, the portal at the domain root) and the manual steps when an
  update could not be undone. `docs/USER-GUIDE.md`: the Updates page. `SECURITY.md`: the trust model and the
  pages that need no sign-in.

### Upgrade note
- **v2.2.0 is the first release with the button.** A site on v2.1.0 or earlier gets there by the manual zip steps in
  `docs/RELEASE.md` §2 (no new required `.env` settings; the optional `UPDATE_CHANNEL=next` is for testing release candidates). From v2.2.0 on, updates
  can use the button. Every later release's updater is the one already installed, so a fix to the updater itself reaches
  a site one release later.

## [v2.1.0] — 2026-11-27

> **Upgrade:** from v2.0.0 or v2.0.1 with the release zip and `docs/RELEASE.md` §2. One new migration
> (`users.locale`, nullable), run by `php artisan migrate --force`; no new `.env` settings and no files removed.
> **Also a security release:** it updates Symfony for five advisories published on 25 November 2026 (see Security).

### Security
- Symfony updated to 8.1.12 (`http-kernel`, `routing`, `mailer`, with `http-foundation`, `mime`, `var-dumper`) and
  `egulias/email-validator` to 4.0.5. Fixes CVE-2026-133743 and CVE-2026-133746 / CVE-2026-133754 (forwarded headers
  or host placeholders could make generated links point at another host) and CVE-2026-133751 / CVE-2026-133755 (a man
  in the middle could get around the mailer's `require_tls` or inject replies after STARTTLS). v2.0.0 and v2.0.1 carry
  the affected versions.

### Added
- **Each member's language is remembered.** A member picks once with the language picker in the app (or on the
  change-password page): the choice is saved on the account (`POST /my/language`, its own rate limit, so it never
  uses up payment attempts) and follows them to another phone or browser, the signed-in pages and their emails. The
  picker still changes the page at once; a choice made offline is sent when the connection is back (kept for 24
  hours). Only that choice saves: nothing a browser merely carries (an earlier choice in the `cp_lang` cookie or the
  app's storage, a `?lang=` link) is written to an account, so someone signing in on a browser another person used
  gets their own language. A member who chose a language before this release sees the portal default until they pick again.
- **Default language** on Portal settings (super admin, audited, "Saved: default language."): the language of the
  member app, sign-in pages and member emails for members who have not chosen one. Bahasa Melayu unless changed. The
  list is every language with a `lang/member/<code>.json` file. Admin pages and admin emails stay in English.
  Accounts with an admin role (club admins and the super admin) always get English emails and never have a language
  saved, even when they pay their own fees in the member app.
- `docs/i18n-new-wording.md`: the new Malay (and the little new English) the emails needed, approved by the owner.
- **Malay emails greet by the time of day**: "Selamat pagi" (05:00–11:59), "Selamat petang" (12:00–18:59) or
  "Selamat malam" (19:00–04:59), Malaysia time when the email is sent, instead of "Salam" (the password reset's
  "Hello!" too, via `App\Notifications\ResetPassword`). Other languages keep their opener. Also from the owner's
  Malay review: "Pegawai perlindungan data" on the privacy notice and "Bayaran tertunggak" as the overdue heading.
- **Chinese (Simplified), Tamil, French and Spanish** on the member side (member app, sign-in pages, member emails),
  marked **beta** in the picker until a native speaker reviews each file. Chinese and Tamil are written for readers
  in Malaysia. Each was checked by a blind back-translation into English (no wrong meaning on money, status or
  security text). Admin pages stay English.
- Pay ahead: the plan cards stack one per row on phones narrower than 360 px, and the "Recommended" badge never runs
  past its card (it ran off the screen at 320 px, in English too).
- **Language picker** instead of the v2.0.1 BM / EN switch, in the member app and on the sign-in pages. It lists every
  language by its own name, marks first translations "(beta)", and shows the short label ("BM") when closed, so it
  stays compact on a phone. A choice made in it is saved on the account as above. The sign-in pages' picker works without
  JavaScript.
- Ready for more languages. A language is now files only: `lang/member/<code>.json` carries its own name, label,
  `<html lang>` and locale (`_meta`), plus `lang/<code>.json` and `lang/<code>/{auth,passwords,validation}.php`.
  The member app and PHP read the list from the folder. Date order, plural forms and whole sentences are keys
  translators fill in (`docs/i18n-translator-brief.md`).

### Changed
- **Member emails are in one language, the member's** (their saved choice, else the portal default): welcome,
  payment confirmation, overdue reminder, transfer slip rejected, payment cancelled, the sign-in and password-change
  security notices, and the password reset link (whose Malay is new). **Members with no saved choice now get the
  confirmation, reminder and welcome emails in Malay, which were English before**; the three emails that carried BM
  and EN together now carry one of them. Dates in emails use the member app's month names ("5 Ogo 2026"). The
  welcome email to a new admin account and the club admin notices stay English.
- One rule decides the member-side language everywhere (`App\Support\UserLocale`): a signed-in member's pages and
  every member email use the account's saved language, else the portal default; the browser's choice (`?lang=` /
  `cp_lang`) only applies to the sign-in pages before signing in. On the change-password and privacy pages a
  signed-in member's language choice is a small form that saves it.
- Malay wording review (Malaysian Malay, DBP usage): 21 strings reworded, 15 of them already live in v2.0.1 (for
  example "Ahli baharu?", "Perlu bantuan?", "jangan bayar sekali lagi"); listed before → after in
  `docs/i18n-new-wording.md`. A test now refuses common Indonesian words in every Malay file.
- Member app text moved from the dictionary inside `resources/js/lib/i18n.ts` to one file per language,
  `lang/member/ms.json` and `lang/member/en.json` (bundled, English is the reference), so a later release can add a
  language by adding a file. Every BM/EN choice in the React components now goes through the i18n module. Malay is
  still stored as `bm` in the `cp_lang` cookie and the app's saved choice, so open tabs and sign-in pages keep
  working. Nothing on screen changes.
- English text with a count now says "1 fee" where it said "1 fees". The forms are `_one` / `_other` keys, and
  emails use `trans_choice`. Malay text is unchanged.
- Pay-ahead form errors (start date, payment period) show in the member's language; they were English sentences.

- PWA service worker cache `v5`.

### Fixed
- **Rate limits no longer share one counter.** A plain `throttle:N,M` counts per signed-in user across *every* such
  route, so viewing 20 slip thumbnails blocked an admin's next save or verification for a minute, and 10 slip views
  blocked a member's payment (in v2.0.0 and v2.0.1). Each route now has its own counter; pay and pay-all still share
  one (10 payment attempts a minute in all).

## [v2.0.1] — 2026-11-23

> **Upgrade:** from v2.0.0 with the release zip and `docs/RELEASE.md` §2. No database changes. A patch release that
> also carries two small features: the breached-password check switch and the installer's full requirement checks.

### Added
- A guard at the top of `public/index.php` (`bootstrap/preflight.php`, before the Composer autoloader): on PHP older
  than 8.4.1 every page is a short "Server setup needed" page (HTTP 503, `Cache-Control: no-store`, `noindex`) that
  says to choose PHP 8.4 in cPanel, instead of Composer's platform-check error (HTTP 500). Before the install it also
  names a missing `pdo` / `openssl` / database PDO extension or an unwritable `storage/framework/views` or
  `bootstrap/cache`, which made `/install` itself fail with a 500. Written so an old PHP can parse it; CI lints it on
  PHP 5.6 and 7.4.
- `bootstrap/requirements.php`: one list of the server requirements (lowest PHP, extensions, PDO extension per
  database, writable folders) read by `app:doctor`, `/install` and the guard; a test checks it against Composer's
  platform check.
- **Breached-password check switch** on Portal settings (super admin; on by default; audited): with it off, new
  passwords on reset and change need only the 12-character minimum and nothing is sent to Have I Been Pwned. A site
  upgraded from v2.0.0 keeps the check on.

### Changed
- `/install` checks everything `app:doctor` checks about the server: the PHP version, every required extension and
  the database's PDO extension, writable `storage/` and `bootstrap/cache`, and `.env`. Each failed row has a one-line
  fix (e.g. "Enable the zip extension in cPanel → Select PHP Version → Extensions"); **Install Application** stays
  disabled while any row fails.
- The minimum PHP is 8.4.1 everywhere (`/install`, `app:doctor`, `release.json`'s `php_requirement`), what the
  locked dependencies need; it was 8.4.0 in the installer and `app:doctor`. The release build refuses a zip whose
  Composer platform check disagrees with `bootstrap/requirements.php`.
- `app:doctor` starts each run with no rows (a second run in the same process repeated the first one's rows).
- **Installer:** the first super-admin password is also checked against known breaches (passes if the service
  can't be reached), with a plain message when it has been seen in a leak.

### Changed (Portal settings, from an Impeccable review: critique 30/40, audit 18/20, 0 axe violations)
- A field error shows once, under its field (no duplicate alert at the top); a plain message for a portal name with
  hidden characters; the success message says what changed.
- Brand colours on a phone: the radio stays beside the colour name, so the list is about half as long.
- Breached-password check: one line of help above the choice, the details under "How it works", "(recommended)" on
  the label, and a warning line while it is off. The intro text is capped at a readable width.
- From a copy review: the save message is whole sentences ("Saved: portal name." / "Breached-password check turned
  off."); the help says what Have I Been Pwned is; the off warning no longer reads as if your own password leaked.

### Changed (installer, from a design critique and a copy review)
- The count of failing checks shows above the list, a failing row is tinted with a red edge, and the form is dimmed
  with the reason right above **Install Application** while any check fails. Pass/Fail badges meet AA contrast.
- Fix lines say where to type commands ("In the Terminal, run …"); the password hint mentions the data-leak check.
- "Server setup needed" page: the command in a copyable block, and a last line for anyone who can't find the
  setting (send the page to the host's support; club members tell the portal admin). English only: it runs before
  the app, so it can't use the language files.
- PWA service worker cache `v4`.

### Known issue
- Opening `/install` before `php artisan migrate` has run gives an HTTP 500 (with `APP_DEBUG=true`, Laravel's debug
  page). `docs/INSTALL.md` step 6 runs the migrations first.

### Documentation
- `docs/screenshots/`: all 17 screenshots retaken from the current app (per-club receipt numbers with club details and Share, the current admin menu, Billing settings with the overdue and WhatsApp sections).
- Treasurer & Club Admin Guide **1.1** (`docs/guides/`): section 3.3 says a new club starts with one job level, "Ahli".
- `docs/INSTALL.md`: `composer.json` requires PHP `^8.4` too (the "still says ^8.3" note is gone); the extension
  table lists `curl`, `xml` and `zip` (required by `app:doctor`; the backup uses `ZipArchive`); the `app:doctor`
  summary names the APP_KEY, Database and "No Vite dev-server file" rows; PostgreSQL is marked untested (CI covers
  SQLite, MariaDB and MySQL).
- `docs/RELEASE.md`: the list of what the release build drops matches `scripts/build-release.sh` (`CLAUDE.MD`,
  `public/storage`, `.phpunit.result.cache`, `.git` folders under `vendor/`).
- `SECURITY.md`: `v2.0.0` is the supported release (fixes land on `master` and ship in the next tag); the password
  rules are described accurately (12+ characters and the breached-password check for resets and changes; the
  installer's own rule for the first super admin); the per-account sign-in pause settings
  (`LOGIN_ACCOUNT_MAX_FAILURES` / `_DECAY_MINUTES` / `_BLOCK_MINUTES`) with their defaults; the own-fee rule covers
  cancelling and deleting.
- `.env.example`: commented `LOGIN_ACCOUNT_*` and `LOG_DAILY_DAYS` with their defaults.
- `docs/SANDBOX-TEST.md`: Generate fees only offers this month and next, so the test member's three fees come from
  **Payments → Add** (or Generate fees plus one added by hand).
- `CLAUDE.MD`: Bootstrap and Chart.js are bundled by Vite (jsDelivr only as the fallback without a build, also in
  the CSP; fonts are self-hosted); each rate limiter is described with its own limits and every route throttle is
  listed; newer services, controllers and support classes in the layout; every scheduled job; the `club_admin` route
  group sits with the other groups; migrations are additive only from v2.0.0.
- `CREDITS.md`: package versions taken from the lock files (Laravel 13.43.0, Tinker 3.2.0, bacon-qr-code 3.1.1,
  dompdf 3.1.6, Guzzle 8.5.5).
- `README.md`: admins must set up 2FA, the password rules and the 15-minute per-account sign-in pause; the
  "rate is RM 0" skip reason of Generate fees; the own-fee rule covers cancelling and deleting a payment.
- `docs/USER-GUIDE.md`: a "Record a payment received by hand" section; contents links to it and to cancel a payment,
  club profile, receipt numbers, portal settings and the privacy notice; member labels as the pages show them (result
  pages, **Slip dihantar**, **Ambil gambar slip**, **Akaun saya**, **Hidupkan 2FA** / **Sahkan dan hidupkan**) and
  the admin 2FA buttons; the grace period and WhatsApp help on Billing settings with the current labels; the ledger's
  "(set manually)" mark; the Audit Log's **Payment Cancelled**.
- `docs/option-a-report.md`: go-live checklist items that are done are ticked (pay-ahead cap, self-hosted fonts,
  previous build's assets kept, cache-store check in `app:doctor`); the release build warns about stale licence
  files rather than refusing.
- This changelog: v2.0.0 ships service worker cache `v3`, and Bootstrap / Chart.js bundled by Vite (SRI applies to
  the jsDelivr fallback only); a summary of design review rounds R2–R7.

## [v2.0.0] — 2026-11-23

> **Upgrade note:** the March 2026 GitHub release `v1.0.0` (listed below as v0.0.1) is superseded. Do not upgrade it
> in place: install this release fresh. From the first real install onwards, all schema changes are new, additive
> migrations.

### Security: dependency update
- `composer update`: Laravel 13.1.1 → 13.43.0 and Symfony, Guzzle and dompdf updates, clearing 54 known advisories
  (among them CRLF / header injection in the `email` rule and `symfony/mime`, which the password-reset mail used).
  CI now fails on a locked package with a known advisory (`composer audit`) or a high npm advisory in shipped code.

### Added (user guides)
- `docs/guides/`: an English **Member Guide** and **Treasurer & Club Admin Guide** as Word documents (guide version 1.0,
  for v2.0.0) with screenshots, a version table and a contents page, ready to edit and share with clubs.

### Added (monthly fee generation)
- **Generate fees for a month** (Payments → "Generate fees for a month", `GET|POST /admin/clubs/{club}/payments/generate`,
  `admin.clubs.payments.generate[.store]`, club admins of the club and the super admin, POST `throttle:6,1`): bills
  every member a monthly fee for this month or next month at the rate in force for their job level, with a due date
  from today (default the 15th) to the end of the following month. A preview lists every member as billed or
  skipped (inactive membership, joined after the month, already has a fee covering the month in any status or plan,
  no rate, rate is RM 0); the POST recomputes it and refuses when the count, the total or a fingerprint of who is
  billed what (HMAC of the billed member + amount pairs, month and due date) changed. The due date can't be before
  the billed month's 1st. Fees are created one by one under the member's pay-ahead lock with active membership (in
  an active club) and the overlap checked again (a duplicate is reported as already billed). One
  `payment.created` audit row per fee carrying the run key and one `payment.generated` summary row. No email is sent;
  an inactive club is refused. Known limit: a fee deleted on purpose is created again if the month is generated again.
- `App\Support\Payments\FeeRates`: the one rule for a member's monthly fee (the rate in force in the month; the
  latest start wins; a rate that ends before it starts never counts; no rate = null, RM 0 = 0, a waived month),
  used by Generate fees and pay ahead.
- Data migration `2026_11_26_000000_close_fee_rate_gaps`: extends a rate the old form closed on the day a
  later-starting rate was entered to the day before that rate, so the months in between have a rate again
  (`down()` is a no-op).

### Changed (monthly fee generation)
- **Pay ahead** prices a period as the sum of each month's rate in force, so a future-dated rate applies from its
  start month (with one rate, amounts are unchanged). A RM 0 month adds nothing; a period with a month that has no
  rate, or that totals RM 0, is refused.
- **Fee rates**: a new rate ends every rate of the level still in force on the day before it starts. A rate that
  starts later keeps the old one until then (it used to end today, leaving the months in between without a rate;
  the list labels it "From …"), and a backdated correction wins over a rate saved earlier that starts after it
  (kept as history, "Replaced before it started"). Deleting a level's open rate reopens the rate that ended the
  day before it started.
- **Overdue reminders** only take fees of an active club whose member still has an active membership in it: fees of
  inactive or removed members and of inactive clubs are no longer flipped to overdue or emailed. A reactivated
  member's fees are reminded on the next run.

### Added (cancel payment)
- **Cancel payment** ("Batalkan bayaran", `PaymentCancellationService`): on a paid fee's admin page, **Cancel
  payment…** opens a form with a reason that fits how it was paid (checked slip: not genuine, money not received,
  recorded by mistake, other; recorded by hand: money not received, recorded by mistake, other; online FPX: refunded,
  other), an optional internal note (500 characters) and a confirmation. `POST /admin/payments/{payment}/cancel`
  (club admins of the fee's club and the super admin; never an admin's own fee; `throttle:10,1`). The fee goes back
  to unpaid in one transaction under the settlement lock order; its receipt number is voided (never reused) and the
  issuer snapshot cleared; a "Bayar semua" bill or slip is cancelled as one set (every fee still carrying its receipt
  number). `payments.transaction_id` and the bill's transaction and paid time are kept, and the new table
  `payment_cancellations` (additive migration) records every cancelled payment (receipt number, amount, paid date,
  method, gateway transaction, bank references, set, issuer snapshot, reason, note, who, when), so a replayed gateway
  callback never marks the fee paid again. A single FPX bill that settled a fee once never settles it again (a late
  callback of the cancelled payment under another transaction id, e.g. after a status-check settlement, is ignored;
  flagged for a refund only when the gateway lists a second successful payment). One `payment.cancelled` audit row per fee; one email per cancel to the
  member (`PaymentCancelled`, BM and EN, neutral wording per reason; the note is never sent). The admin payment page
  lists the cancellations.
- Member pages: an unpaid fee whose payment was cancelled shows "Resit {no} telah dibatalkan pada {date}. … Yuran
  ini belum dibayar." above the invoice; once paid again its receipt notes the earlier, cancelled number. A cancelled
  "Bayar semua" result page shows "Resit {no} dibatalkan" instead of a receipt. The old FPX return page of a
  cancelled payment leads to the fee page.
- An online payment made while the fee's own slip, or its set's slip, was still being checked: cancelling it puts
  the fee back to awaiting verification on that slip (email and banner say the slip is still being checked).

### Changed (cancel payment)
- The payment **Edit** page no longer takes a fee back from Paid: the server refuses a status change of a paid fee
  ("To undo a payment, use Cancel payment…") and the dropdown offers only Paid for it. The gateway-charge warning
  moved to the cancel form. A cancelled fee leaves the income of its paid date, so cancelling a payment dated in an
  earlier financial year changes that year's totals (the form warns; the audit log is the trail).

### Added (settings phase 2: receipts and privacy)
- **Receipt numbers per club**: `{PREFIX}-{FY start year}-{NNNN}` (e.g. `RCP-2026-0001`), a sequence per club and
  financial year (`clubs.financial_year_start_month`; the year the money was paid in, fixed at issue), issued when a fee becomes
  paid. The prefix (default `RCP`, 2–6 of A–Z / 0–9) is set on the Club profile and applies to later receipts only.
  A "Bayar semua" bill or slip is one receipt with one number shared by its fees (stored on the bill and on each fee).
  Numbers are **never reused** (not gap-free): a fee taken back from paid keeps its number recorded as void and gets
  a new one when paid again. Shown on the member receipt and group receipt, the receipt email, the admin payment page
  and the ledger (screen, CSV `Receipt No.` column, PDF). Fees paid before keep `RCP-{id}` / `RCP-G{id}`. New
  tables `receipt_counters`, `receipt_allocations`, `receipt_allocation_payments`; new columns
  `clubs.receipt_prefix`, `payments.receipt_no`, `payment_bills.receipt_no`.
- **Receipt footer**: an optional plain-text footer per club (Club profile, up to 300 characters, line breaks kept,
  control characters refused, audited; new `clubs.receipt_footer`), printed escaped under "computer-generated" on the
  official receipt and the receipt email. It is part of the issuer snapshot, so an issued receipt keeps the footer it
  was issued with; receipts issued before have none.
- **Privacy notice**: a public `/privacy` page (no sign-in, sign-in layout, BM by default with the EN switch) showing
  the portal's privacy notice and an optional data-protection contact, linked from the sign-in footer and the member
  app's account section. The super admin writes it on a new page, **Privacy notice** (`/admin/portal-settings/privacy`,
  stored in `portal_settings`, audited): BM and English plain text (escaped, paragraphs from blank lines), a contact
  name and email. No legal text ships as a default: until one is saved the page shows a neutral placeholder.

### Added (settings phase 1: club profile, portal settings, financial year)
- **Club profile** page for club admins (and the super admin), `/admin/clubs/{club}/profile`: name, logo, contact
  email, registration no. (ROS/PPM), address and the month the financial year starts. Audited with old / new values.
  New `clubs` columns `registration_no`, `address`, `financial_year_start_month` (default 1).
- **Safe logo handling** (`LogoService`, used by the club profile, the super admin's club forms and portal settings):
  PNG / JPEG / WebP only (SVG and GIF refused), up to 2 MB and 4000 × 4000 px, decoded and re-encoded with GD as a PNG
  of at most 512 px under a random name on the public disk. Old logo files are kept (issued receipts may show them).
  The PHP `gd` extension is now needed for logo uploads.
- **Receipt snapshot**: new `payments.issuer_snapshot` (JSON) holds the club's name, registration no., address, logo
  path and email, written once when a fee becomes paid (gateway, transfer approval, an admin recording it, group
  bills) and never overwritten. Receipts render from it; fees paid before this release show the club as it is now.
- Members see the club's logo and name on Home, and logo, registration no. and address on invoices and receipts
  (including "Bayar semua"). The ledger PDF header adds the registration no. and address.
- **Sign-in pages** show the club's name and logo when exactly one club is active; otherwise the portal brand.
- **Portal settings** (super admin, `/admin/portal-settings`, new `portal_settings` table): portal name (applied as
  `app.name` at runtime, cached; falls back to `APP_NAME` when unset or when the database can't be read) and portal
  logo (sign-in header and admin sidebar).
- **Financial year**: a club's start month drives "this year" on the Dashboard, Fund Usage, the Ledger default and
  presets and the members' fund page (labelled e.g. "FY 2026/27"). Start month 1 keeps the calendar year exactly.

### Added (settings phase 2: brand colour and app icon)
- **Brand colour** (Portal settings): six presets (Lavender, the default, Teal, Blue, Green, Maroon,
  Slate) with light / dark swatches; only the preset key is stored (no free colour or CSS). Applied at runtime, without
  a rebuild, by one nonced `<style>` in the member app, the sign-in and error pages and the admin pages, and to the
  browser bar (`theme-color`); nothing is added for the default, so it renders as before. A unit test checks WCAG
  contrast for every preset in both themes. Status colours, charts, receipts and emails keep their colours.
- **App icon and manifest**: with a portal logo, GD makes the app icons (192 / 512 "any" and maskable, apple-touch-icon
  180, favicons 32 / 48) under hashed names, so a new logo or colour gives new URLs. The manifest is now served by the
  app at `/app.webmanifest` (portal name, brand colour, icons; no session; works before the install); the static
  `public/manifest.webmanifest` stays as a fallback. Without a logo or GD the built-in icons are used. Phones that
  installed the app earlier may keep the old icon and name until it is reinstalled. Service worker cache `v3` in this release.

### Added (Option A: new member app and admin pages)
- **Front end:** Inertia 2 + React 18 + TypeScript + Tailwind 3, built with Vite (`npm run build`; the server
  never needs Node). Light and dark themes; member pages in Bahasa Melayu by default with an English toggle.
  The installer and the remaining admin pages are still Blade + Bootstrap.
- **Job levels per club:** each club defines its own job levels (tahap jawatan) on a new **Job levels** page (add,
  rename, reorder, deactivate / reactivate, delete while unused; all audited) instead of the fixed GM / AGM / Manager /
  Executive / Non-Executive list, which also clashed with "AGM" (Annual General Meeting) in the ledger. A new club
  starts with one level, "Ahli". Existing clubs keep the five old levels (migration backfill), so nothing changes for
  them until an admin edits them. Members, fee rates and the CSV import (level by code or name, any case) use the
  club's active levels; a deactivated level stays on the members already on it. `club_user.job_level` and
  `fee_rates.job_level` are now VARCHAR(40).
- **Front page:** `/` is now the sign-in page itself (no redirect), with a "New member? How to join" section
  (no self sign-up: contact your club admin, linked when there is one active club) and a show/hide password button.
- **Member app:** Home (amount due, upcoming and recent fees, club-wide "where your money goes" totals), Payments by
  year with pay-ahead, invoice / official receipt, Review & pay (POST-only pay) and a status-aware result page.
- **Fund Usage:** expenses and welfare (kebajikan) cases with maker-checker approval (conflict-of-interest
  declaration, return with reason, two-admin void), private encrypted attachments with audited downloads, encrypted
  welfare personal details, and an Inertia admin dashboard.
- **PWA:** web manifest, icons, a minimal service worker (`public/sw.js`, static assets only) and an install tip.
- **Small fixes:** the installer requires PHP 8.4 (as `composer.lock` does); the demo super admin is
  `superadmin@example.com`; the dashboard chart's end labels no longer overlap when both lines end close together;
  the receipt's paid date uses BM month names on BM pages.
- **Fix:** `TRUSTED_PROXIES` set in `.env` was ignored (it was read with `env()` in `bootstrap/app.php`, before `.env`
  loads). It is now `config('app.trusted_proxies')`, applied in `AppServiceProvider`; empty still trusts nothing.
- **Docs:** README rewrite with screenshots, `docs/USER-GUIDE.md`, `docs/payment-options.md`, end-to-end smoke test
  (`tests/e2e/`).

### Design fix round (R1a–R1e, from the design review)
- **R1a, sign-in and member basics:** sign-in, 2FA and password pages work on phones (one column, Lavender look with
  dark mode, "Club Portal" brand, BM/EN switch shared with the member app, accessible errors, a plain fallback style
  when the build is missing); `<html lang>` follows the language toggle; member pages hold up at 200% text; the
  network-dots hero is replaced by a membership card; Review & pay has a sticky pay bar, no duplicate amount, FPX
  disabled with a reason when unavailable, and camera-first slip upload on phones.
- **R1b, "Bayar semua":** pay every open fee with one gateway bill (one gateway charge) or one transfer slip
  (`YURAN-G{id}`); group bills in `payment_bills` + `payment_bill_items`; the webhook settles a set atomically with the
  same token, confirmation, amount and dedupe checks; the treasurer approves or rejects a group slip as one set.
- **R1c, one set of fund figures:** `FundFigures` is the single source for the balance, collected and spent
  (approved expenses + welfare + absorbed gateway charges), each with its period; Ledger and Billing settings rebuilt
  on Inertia (computed opening balance, period presets, monthly bank-charge rows); `APP_TIMEZONE`
  (`Asia/Kuala_Lumpur` from the installer) so dates and the 08:00 reminder follow Malaysia time.
- **R1d, classic admin pages:** Bootstrap pages use the same tokens, dark mode, sidebar (one `AdminNav` source),
  headings and accessible forms as the React pages; Statistics uses `FundFigures`; members list links fixed.
- **R1e, polish:**
  - Forms: `Field` links each control to its label, hint and error (`aria-describedby`), sets `required` /
    `aria-required` and `aria-invalid` on inputs, textareas and selects; the Fund Usage "Record type" switch is a
    labelled radio group with arrow keys; a failed submit moves focus to the error summary. The file uploader ties
    its error and limits to every way in.
  - One `h1` per page and no skipped heading levels (member header title is the `h1`, card titles are `h2`).
  - "Close" on dialogs and sheets and the uploader's default text follow the page language (BM on member pages).
  - With reduced motion, spinners stop turning but gently fade, so a busy state never looks frozen.
  - Admin date fields write the picked date out under the field ("16 Nov 2026", React and Blade), so day and
    month can't be mixed up whatever order the browser's date control uses.
  - The Verifications sidebar badge counts slips, not fees (a "Bayar semua" slip counts once).
  - A note on the "Bank charges" category (Fund Usage form and Expense categories page): only charges the portal
    can't see; absorbed gateway fees are added automatically.
  - Expense categories have an optional Bahasa Melayu name (`expense_categories.name_ms`, edited on the Expense
    categories page); members see it on "Ke mana wang anda pergi". Demo and e2e seeders include BM names and a
    "Bank charges" category.
  - Admin lines no longer mix BM and English in one phrase (dashboard slips panel, ledger manual opening balance).
  - The dashboard slips panel shows a "Bayar semua" slip as one row with its fee count and total; a settled group
    no longer reads "paid" after an admin reverts one of its fees.
  - Sign-in: the saved language only counts when it is `bm`/`en`; alert messages are guarded; a translated, styled
    429 "too many attempts" page.
  - Classic pages: Bootstrap, Bootstrap Icons and Chart.js are bundled by Vite (R3d); the pinned jsDelivr copies,
    with Subresource Integrity, are only a fallback when `public/build` is missing; the club name is no longer repeated under the brand; the theme button reads
    "Theme (System)"; on phones the payments table shows each status under the member's name, with a scroll hint.
  - The member page's club fallback for admins only uses clubs they still actively manage.
  - The review page's sticky pay bar keeps the same size pinned or not, so it can't flicker at the threshold; the
    receipt amount wraps at large text sizes.
  - Docs: screenshots retaken (new `member-pay-all.png`); README and user guide cover "Bayar semua", the new ledger
    and billing pages, bank charges, the 08:00 Malaysia-time reminder, which figure to quote at the AGM, and that
    removing a member keeps their payment history.

### Design review rounds (R2–R7)
- **R2:** "Record payment…" dialog for money received outside the app; branded 419 / 500 / 503 error pages; an
  untrusted Host header is refused; an optional club WhatsApp number for member help on Billing settings.
- **R3a–R3c:** member **Keselamatan & 2FA** page with recovery codes to download; slip review on Verifications with
  zoom; the classic Payments table shown as cards on small screens.
- **R3d, performance:** fonts self-hosted (Fontsource, Google Fonts removed), Bootstrap, Bootstrap Icons and
  Chart.js bundled by Vite (zero third-party requests in the normal path), and a smaller entry chunk for member pages.
- **R4:** PHP 8.4 required; a catch-all route (`FallbackController`) so unknown URLs get the branded 404 page; recovery codes without
  look-alike characters; `route:cache` works again. Scores: critique 30/40, audit 18/20, no axe WCAG A/AA violations.
- **R5:** member Fund page and Home "Akan datang" grouping refined; sign-in help with the club's WhatsApp or email;
  audit log with friendly record names (FU numbers) that stacks on small screens.
- **R6:** layouts follow the browser text size (breakpoints in em); fund balance hints and Fund Usage dates
  clarified. Scores: critique 32/40, audit 19/20, no P0 or P1.
- **R7 (last design round):** a "no documents" filter and banner on Fund Usage. Design rounds stop at critique
  32/40, audit 19/20; remaining polish waits for feedback after go-live.

### Documentation and licensing
- New `docs/INSTALL.md`: one complete first-install guide (requirements and PHP extensions, local and cPanel paths,
  MySQL, after-install setup, backups, troubleshooting). README keeps a short summary; `docs/RELEASE.md` now covers
  building a release and upgrading.
- New `SECURITY.md` (private reporting, supported versions, security model) and `mockup/README.md`.
- `CREDITS.md` rewritten from the installed packages' own licence files, with copyright holders, the pinned CDN
  versions, the fonts actually loaded, design acknowledgements and a trademarks notice. New
  `docs/THIRD-PARTY-LICENSES.md` (package list and full licence texts) and `public/licenses.txt` (the texts, served
  at `/licenses.txt` by every install), both generated by `scripts/licenses.mjs` (`npm run licenses`).
- `LICENSE`: copyright holder is now "Amir Subhi and clubcollection contributors" (still MIT).

### Release zip for cPanel (G3)
- **`scripts/build-release.sh <version> [git ref]`** builds `dist/clubportal-<version>.zip` (+ `.sha256`) from a clean
  `git archive` export, never the working tree: `composer install --no-dev -o`, `npm ci && npm run build`, the licence
  files regenerated (with a warning when the committed ones are stale), then dev-only files dropped (`node_modules`,
  `tests`, `mockup`, `scripts`, `.github`, `phpunit.xml`, `public/hot`, `.env*` except `.env.example`, `*.sqlite`,
  `storage/` and `bootstrap/cache/` contents; the empty skeleton folders stay). It writes `release.json` (version,
  commit, build time, PHP requirement) and refuses a zip that still contains anything that must not ship (`.env*`, `auth.json`, `.installed`,
  `bootstrap/cache/*.php`, `public/storage`, storage files...).
- **`.github/workflows/release.yml`:** on a `v*` tag, a read-only job runs the tests on PHP 8.4 and builds the zip; a
  separate publish job (`contents: write`) attaches the zip and its `.sha256` to the GitHub Release (tags with a `-`
  become pre-releases).
- **`config('app.version')`** comes from `release.json` (`dev` in a git checkout); `app:doctor` prints it.
- **Docs:** RELEASE.md describes the zip upgrade (verify the hash, `down --secret` then `backup:run`, `cp -a` over
  the site keeping `.env` and `storage/`, restore cPanel's PHP lines in `public/.htaccess`, delete removed files,
  `migrate --force`, `optimize:clear` + `optimize`, `up`, `app:doctor`); INSTALL.md and
  the README use the zip for a first install too, so the host needs no Composer or Node. The one-command
  `app:update` stays deferred.

### Host readiness and data safety (G2)
- **`php artisan app:doctor`:** a read-only check of the server with one PASS / WARN / FAIL row and a one-line fix per
  check: PHP 8.4 and extensions (incl. the database's PDO driver, gd, zip), `APP_ENV` / `APP_DEBUG` / https `APP_URL` /
  `SESSION_SECURE_COOKIE` / `APP_TIMEZONE` / `APP_KEY`, the cache store (not `array`), upload and memory limits, the
  private files folder, `storage:link`, the front-end build and no `public/hot`, webhook secrets for the gateways in
  use, `TOYYIBPAY_SANDBOX=true` while a club uses ToyyibPay (WARN), log/array mail in production (WARN), the cron job
  (a new every-minute heartbeat; FAIL when over 10 minutes old) and the latest backup. Outside production the
  live-site-only checks are WARN. Exit code 1 on any FAIL; `--json` for scripts; never prints a secret. Last step of
  INSTALL.md (step 13) and of the upgrade in RELEASE.md.
- **`php artisan backup:run`**, daily at 02:15 Malaysia time: a zip in `storage/app/backups` (outside `public/`) with
  the database (SQLite via `VACUUM INTO`; MySQL via `mysqldump` with the credentials in a temporary option file, or a
  message to use cPanel Backup when there is none), `storage/app/private` and the club logos. Keeps the newest 14
  (`--keep`; only its own zips, and nothing when the database copy failed, which also exits non-zero), never
  includes `.env` or `APP_KEY`, audited as `system.backup`. INSTALL.md now covers downloading
  backups off the server, keeping `APP_KEY` separately, and restoring.
- **Real thumbnails:** the thumbnail route returns a GD-downscaled, re-encoded copy (480 px; 1600 px with
  `?size=large` for the Verifications slip preview) with EXIF dropped (orientation applied to the small copy), never the
  original; an image that can't be decoded, is over 25 megapixels or wouldn't fit in memory gets 404.
- **Club deletion is refused** while the club has members, fees, gateway bills, expenses or documents ("This club has
  records. Deactivate it instead"); an empty club can still be deleted.
- **Inactive clubs and memberships** drop off the member home page and the admin home club list, and their payments
  list and fund page are refused (403), like pay-ahead already was.

### Payments (G1: money safety before go-live)
- **Lost callbacks are settled:** `payments:reconcile-open-bills` runs every 15 minutes (needs the cron line) and asks
  ToyyibPay (`getBillTransactions`) about open, unsuperseded single and "Bayar semua" bills opened 10 minutes to
  3 days ago; "Semak status bayaran" (the result page's status poll) asks the same for that bill, at most once every
  30 seconds. Both settle through the webhook's own code (`SettlementService`: amount checks, transaction dedupe,
  row locks, refund flags, receipt email) and audit `payment.reconciled`. Billplz bills are skipped (no get-bill call yet).
- **Webhooks fail closed** when neither a paid amount nor a bill amount is known (409, `payment.webhook_amount_unknown`).
- **Unrecorded bill codes:** a callback whose bill code is not on record but whose reference (with that reference's
  callback token) names a fee is never settled; it is flagged on that fee for the club's admins to check in the gateway.
- A reconcile that finds more than one successful payment on a bill flags the extra ones for a refund; an underpaid
  bill is flagged once, not on every run. Settlements started by a member's status check are audited as the system.
- `pay` and `pay-all` are throttled (10 per minute).

### Security (S2: sign-in and account hardening)
- **Sign-in limits:** POST /login has its own `login` limiter: 5 a minute per account (or unknown email) + IP and 30 a
  minute per IP, so a staff club on one office network can sign in together. The 2FA challenge and the installer
  have their own buckets (they shared one counter with sign-in). After 10 wrong passwords for one account from any IP,
  sign-in for it pauses for 15 minutes (never permanent; a password reset still works and ends the pause; an unknown
  email gets the same answer). The owner gets one email per pause. Spelling variants of an email that match the same
  account share one counter.
- **Temporary passwords are replaced at first sign-in:** accounts created by an admin (add member, CSV import, add
  administrator) must choose their own password on the new **/password/change** page before anything else. New
  column `users.must_change_password` (additive migration, existing users unaffected). The page is also open for a
  voluntary change.
- **Admins need 2FA:** a super admin or club admin without 2FA is sent to Security & 2FA until it is on
  (`REQUIRE_ADMIN_2FA`, default `true`). Members stay optional. Turning 2FA off now needs a current code or a recovery
  code as well as the password.
- **Passwords:** new passwords need 12+ characters and must not appear in a known data breach (Have I Been Pwned range
  API; it passes when the API can't be reached, 5 s timeout). No upper-case / symbol rules (NIST).
- **No account discovery:** POST /password/reset answers an unknown email like a bad link; the reset-link email is
  sent after the answer, so known emails are not slower.
- **Audit and emails:** wrong passwords, lockouts, account pauses, completed resets and password changes are in the
  audit log (never the password); the owner is emailed when their password is reset or changed.
- **Headers:** HSTS is configurable (`HSTS_MAX_AGE`, `HSTS_INCLUDE_SUBDOMAINS`, `HSTS_PRELOAD`; no `includeSubDomains`
  or `preload` by default), `X-XSS-Protection: 0`, `img-src` limited to the site and `data:`, and `public/.htaccess`
  adds `nosniff`, `X-Frame-Options` and `Referrer-Policy` to static files. "Keep me signed in" lasts 30 days
  (`AUTH_REMEMBER_MINUTES`) instead of about 400.

### Security (S1: hardening from the full audit)
- **Gateway callbacks:** absolute URLs come from `APP_URL` (host and scheme) in production and other `Host` headers are
  refused, so a forged `Host`/`X-Forwarded-Host` can't redirect callbacks or email links. The ToyyibPay callback
  token is now a per-bill HMAC of the bill reference (a leaked token works for that bill only), the webhook secret
  must be 32+ characters, and a paid callback is confirmed with ToyyibPay `getBillTransactions` before settling
  (`TOYYIBPAY_VERIFY_CALLBACK`, default on; unreachable API = not settled, non-200 so ToyyibPay retries).
- **Bills:** every bill is kept in the new `payment_bills` table; a paid superseded bill still settles when the
  amount is right and is flagged when it isn't. `pay` runs under a per-fee lock. Unmatched paid callbacks are
  audited (`payment.unmatched_webhook`); a second gateway payment on a fee already paid is flagged for a refund
  (`payment.gateway_paid_twice`); refund flags and unmatched/unconfirmed audits are written only after the gateway
  confirms the payment (once per bill, so a leaked token can't flood the log); a bill can't be opened while the
  webhook secret is unusable; a transaction id settles at most once (also after an admin revert);
  callbacks from another gateway are ignored; logs keep allow-listed fields only; webhooks are throttled (300/min per IP);
  Billplz uses a club's own signature key only when the club's Billplz credentials are complete.
- **Admin payments:** edits run under a row lock and are refused when the status changed since the form opened;
  leaving "paid" clears how it was settled; amount capped at RM100,000 with 2 decimals; admins can't mark their own
  fee paid, change its amount or discount, or delete it (super admin exempt). The overdue reminder no longer overwrites a fee paid meanwhile. Ledger sums in sen.
- **Roles:** only the super admin grants the club-admin role (member form, edit and CSV import); club admins can't
  change or remove a club admin. Grants are audited and emailed to the club's admins.
- **Bank / DuitNow details:** a change waits for a second club admin (or the super admin) to approve it on the
  billing page; members see the old details until then; all club admins are emailed on request and approval.
  Switching transfers off is immediate. Requester can cancel but not approve.
- **2FA:** setting it up needs the current password and is refused while 2FA is on; a TOTP code can't be reused
  (`two_factor_last_used_step`); the challenge locks for 15 minutes after 5 wrong codes per user.
- **Password reset:** same answer for known and unknown emails; reset, email and confirm POSTs throttled 5/min;
  a password change ends the user's other sessions (`auth.session`).
- **Install and deploy:** the installer needs `INSTALL_TOKEN` (disabled without it), rejects quotes and line breaks
  in the app name, writes production settings and blanks the token. The demo seeder only runs in local/testing and
  has no well-known password. `SESSION_SECURE_COOKIE` in `.env.example`; production `.env` block and PHP upload
  limits in `docs/RELEASE.md`.
- **Exports:** CSV cells starting with `= + - @` (or tab/CR) get a `'` prefix, plain numbers excepted; download names are slugged.
- **Super-admin user management:** `{admin}` routes bind admin users only; create/update/delete audited; the last
  super admin (and yourself) can't be demoted or removed; an admin with records is kept with a message.
- **Fixes:** fee-rate delete (always 403 before) and the fee-rate/discount redirects; ReviewPay shows transfer
  errors and the open-bill warning on the transfer option; the uploader refuses files over 10 MB on the device; a
  bilingual page for uploads over `post_max_size`.

### Payments (P2: manual DuitNow transfer with slip upload)
- Clubs can publish bank / DuitNow details (Billing settings). Members then choose "Pindahan bank / DuitNow"
  on Review & pay, transfer with the reference `YURAN-{id}` and upload 1–3 slips (stored encrypted, private).
- The fee waits as "Menunggu pengesahan" (`pending_verification`) until a club admin approves or rejects it on the
  new **Verifications** page (nav badge + dashboard panel). Approve marks it paid by transfer and emails the
  receipt; reject needs a reason, emails the member (BM/EN) and lets them send a new slip. All steps audited.
- Admins can't approve their own fee; online pay, manual mark-paid and status edits are refused while a slip is
  being checked; a gateway payment that still arrives is settled and flagged for a refund.
- Payments, ledger (screen/CSV/PDF) and statistics show "Awaiting verification" as its own status.

### Payments (P1: billing frequency and gateway fee)
- ToyyibPay `billChargeToCustomer` now follows the club's gateway-fee setting. "Club absorbs" (the default) keeps
  the previous value `1` until a sandbox run confirms how ToyyibPay reads each value; "member pays" (`0`) stays
  disabled for ToyyibPay until `TOYYIBPAY_CHARGE_VERIFIED=true`.

### Earlier hardening pass (before Option A)

Security and accessibility hardening pass. No public API changes.

#### Security
- Webhook handler is now atomic (lockForUpdate + transaction) and idempotent;
  fails closed if `TOYYIBPAY_WEBHOOK_SECRET` is unset.
- 2FA challenge POST is throttled (5 req/min/IP); audit-log distinguishes
  password verification from full login; recovery-code use is audited.
- Install wizard requires a strong password (12+ chars, mixed case, numbers,
  symbols), is atomic (transaction + atomic marker write), and refuses
  re-install once installed.
- Seeder refuses to run in production (default super-admin password is
  documented and would otherwise be silently provisioned).
- `MemberController` shallow routes now use `AuthorizesClubResource` and
  refuse cross-club access; pivot role no longer escalates `users.role`.
- CSV import capped at 1000 rows; formula characters (`=+@-`) stripped to
  defang downstream spreadsheet exports.
- CSP nonce now applied to dashboard / statistics charts (previously blocked
  silently); inline `onsubmit/onclick/onchange` handlers replaced with
  CSP-safe data attributes wired in the layouts.

#### DB / Infra
- Composite index on `audit_logs(auditable_type, auditable_id)`.
- `sessions.user_id` foreign key with cascade-on-delete (MySQL/Postgres).
- Trusted-proxies wired via `TRUSTED_PROXIES` env var.
- Ledger date range capped at 24 months to prevent OOM.
- Portable `whereBetween` month filter in PaymentController (was
  SQLite-only `strftime`).

#### Accessibility
- `aria-label` on all icon-only action buttons.
- `scope="col"` on every `<th>` across admin and member tables.
- `alt` text on every logo image.
- Status badges normalised to `-subtle` variants for WCAG AA contrast.

#### Tooling
- Removed unused Vite / Tailwind / SCSS scaffolding (Bootstrap is loaded
  via CDN). `composer setup` no longer runs `npm`. (Option A later brought
  back `package.json` with a real Vite + React build; see above.)
- Added `pint.json` so the Laravel preset is consistent across machines.
- `phpunit.xml` pins `APP_KEY` so encryption-dependent tests don't depend
  on the developer's local `.env`.

#### Tests
- 129 tests, 290 assertions (was 90 / 167). New suites: WebhookTest (8),
  TwoFactorTest (9), InstallationTest (6), ShallowResourceAuthorizationTest (10),
  SendOverdueRemindersTest (2). Existing suites extended with date-range
  cap, portable month filter, CSV row cap, and CSV formula-stripping tests.

---

## [v0.0.1] — 2026-03-23

(Published on GitHub as the `v1.0.0` release, which the version heading links to. Superseded: do not upgrade it in
place; see the upgrade note under v2.0.0.)

First public release of Club Portal — a self-hosted club membership management system built with Laravel 13.

### Features included

#### Multi-club administration
- Super admin can create, update, and delete clubs
- Per-club admins manage their own club in isolation
- Role-based access: `super_admin`, `admin`, `member`
- Optional TOTP two-factor authentication (via `pragmarx/google2fa`)

#### Member management
- Add members individually with automatic welcome email (temporary password)
- Bulk import members via CSV upload (with per-row error reporting)
- CSV template download
- Edit member pivot data (role, job level, active status)

#### Fee & payment management
- Per-club fee rates by job level (GM, AGM, Manager, Executive, Non-Exec)
- Generate monthly, quarterly, or yearly payment records per member
- Mark payments as paid with optional reference number
- Discount management (fixed or percentage) scoped per club
- ToyyibPay integration with per-club credentials and webhook handling
- Member portal: view own payment history, pay online

#### Expense tracking
- Record club expenses by category
- Receipt file upload (JPG, PNG, PDF)
- Per-club expense categories

#### Financial ledger
- Chronological ledger with running balance and opening balance support
- Date-range filtering with month-based validation
- Monthly summary breakdown (income vs expenses per month)
- Outstanding payments panel (all-time, not date-filtered)
- Export to CSV (UTF-8 BOM, Excel-ready)
- Export to PDF (A4 landscape, suitable for AGM / treasurer reports)

#### Audit trail
- Every create / update / delete action logged with old and new values
- Filterable per-club audit log and platform-wide log for super admin

#### Other
- Self-contained installation wizard (no manual `artisan` commands needed)
- Security headers middleware (CSP, X-Frame-Options, HSTS, etc.)
- Overdue payment reminder emails (schedulable)
- Platform-wide statistics page for super admin

### Automated test suite
- 90 tests, 167 assertions (all passing)
- Covers: auth/middleware, ledger business logic, CSV/PDF export, CRUD for all
  major controllers, CSV member import edge cases, cross-club authorization, and
  payment model behaviour

### Bug fixes included at release
- `ClubController`: undefined array key crash on optional ToyyibPay fields
- `MemberController`: shallow `update`/`destroy` routes could not resolve `$club`
  from URL; club is now derived from the authenticated admin's pivot relationship
- `ExpenseController` / `PaymentController` / `MemberController`: redirects used
  non-existent route names (`admin.expenses.index`, etc.)
- 20+ Blade views: nested resource routes were missing the `clubs.` prefix
- `Discount` model: missing `HasFactory` trait
- `DiscountFactory`: `valid_from` defaulted to `null` despite `NOT NULL` constraint

---

[Unreleased]: https://github.com/amirsubhi/clubcollection/compare/v2.0.1...master
[v2.0.1]: https://github.com/amirsubhi/clubcollection/releases/tag/v2.0.1
[v2.0.0]: https://github.com/amirsubhi/clubcollection/releases/tag/v2.0.0
[v0.0.1]: https://github.com/amirsubhi/clubcollection/releases/tag/v1.0.0
