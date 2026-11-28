# In-app Updater Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the super admin see that a newer Club Portal release exists and install it from the admin with one button, safely, on cPanel shared hosting (target release v2.2.0).

**Architecture:** CI publishes a fixed-name `clubportal-manifest.json` and ships `release-files.json` inside the zip. Laravel checks the manifest (cached), shows a banner and an Updates page, runs pre-checks and, after re-confirmation, writes a job and hands over to a standalone, framework-free updater in `public/update/` that the browser drives step by step (download, backup, unpack, guard, swap, cache reset, migrate, health, finish) with a journal that can resume or roll back, including without a token after 10 idle minutes.

**Tech Stack:** PHP 8.4, Laravel 13 (Blade for super admin pages), PHPUnit (`php artisan test`), plain PHP + vanilla JS for the updater, bash + GitHub Actions for the release build.

**Spec:** `docs/superpowers/specs/2026-11-27-in-app-updater-design.md` (approved). Read it before any task.

## Global Constraints

- PHP `>=8.4.1`; Laravel 13; **no new Composer or npm dependencies**.
- No cron, no queue, no background work for the updater. Checks happen on the super admin's or a club admin's dashboard load (cached 1 hour, failures 15 minutes) or on "Check now".
- Never call `api.github.com`. Manifest URL: `https://github.com/amirsubhi/clubcollection/releases/latest/download/clubportal-manifest.json` (`UPDATE_CHANNEL=next` → `…/releases/download/next/clubportal-manifest.json`). Follow redirects; do not hard-code the CDN host.
- Updater code uses only pure PHP: `ZipArchive`, ext-curl, `rename`, `copy`, `unlink`, `opcache_reset` (optional). No `proc_open`/`exec`/`symlink`. Each HTTP step must finish well under 30 s.
- Never touched: `.env*`, `storage/` (except `storage/app/updater/` and `storage/framework/{down,maintenance.php,updating}`), `database/*.sqlite*`, the cPanel block `# php -- BEGIN cPanel-generated handler` … `# php -- END cPanel-generated handler` in `public/.htaccess`.
- `public/` is never renamed. `public/update/` and `public/update-guard.php` are replaced only in the finish step.
- `public/update-guard.php` and the guard lines in `public/index.php` / `artisan` must parse on PHP 5.6 (same rules as `bootstrap/preflight.php`). The guard lives in `public/`, not `bootstrap/`, because `bootstrap/` is swapped.
- Trust model: checksum only. `manifest.zip.url` must start with `https://github.com/amirsubhi/clubcollection/releases/download/`. `signature` must be `null` or absent (format 1).
- Re-confirmation: password, plus a current TOTP (`User::verifyTwoFactorCode`) when `hasEnabledTwoFactor()`; password only otherwise, with a warning line.
- Admin pages and updater pages are English only (the members' pause page, stage 2, is in the portal's default language). Audit actions: `portal.update_started`, `portal.update_finished`, `portal.update_rolled_back`, `portal.update_failed_check`.
- Public repo: no LAN addresses, host names or home paths in code, docs, commits or PR text.
- Migrations stay additive; this feature adds **no migration** (all updater state is in files).
- **The spec's "User journeys" (J1–J9) and its §12 are requirements.** Screens use the journeys' plain words; no
  "swap", "migrate", "unpack", "opcache", "journal" or "maintenance mode" on any screen outside **Show details** and the
  copyable report. Pre-checks are pass or fail, never "unknown". Every failure line has a fix, and host-side fixes have
  a copyable host message.
- Club admins see only the J2 security note (owner's decision, 28 November 2026). Members see nothing except the
  pause page.

## Stages

- **Stage 1 = Tasks 1–5** (owner's decision, 28 November 2026): release metadata, the check, pre-checks, the Updates
  page, the banner and the club admin note. Stage 1 ends with **no update button**: where the button will go, the
  page shows "To update, follow the upgrade steps" linking to `docs/RELEASE.md` §2 on GitHub. Stage 1 merges to
  master but is **not released on its own**: v2.2.0 needs the swap guard and `public/update/` (stage 2), because
  every later button update runs the installed release's updater and `min_from_version` is 2.2.0.
- **Stage 2 = Tasks 6–14** (revised 28 November 2026 against the spec's §12 and the journeys; see "Stage 2 rules").
  Stage 1 agents implement Tasks 1–5 only and must not build anything from Tasks 6–13.
- **Carried into stage 2 from the stage-1 reviews (28 November 2026):**
  - **No release ships stage 1 on its own** until the wording matches the manual path: the red banner says "It
    takes about 2 minutes. Update now" and the page says "Updating takes about 2 minutes", but in stage 1 the only path
    is the manual upgrade steps. Either reword for the manual path or ship with stage 2.
  - Button gate is `PreChecks::canStart($checks, $dbTicked, $manualTicked)` only; `allPass()` never enables it. Show
    checks still marks the two advice rows as "Failed", and the tight-disk warning's fix/host message is never shown:
    fix both when Task 6 adds the ticks.
  - `UpdaterHost::NEEDED_FUNCTIONS` is the single list of PHP functions the updater calls; extend it as Tasks 7–10 add
    calls. `updateRunning()` treats `finished`, `undone`, `failed`, `rolled_back` as terminal and an unreadable
    `job.json` as running; Task 6/9 must write exactly those statuses.
  - `paymentInProgress()` = an open gateway bill opened in the last 2 minutes (no `pay:*` lock detection); the saved
    callbacks of spec §12 cover the rest.
  - The rename probe stages in `dirname(base_path())`; a host whose open_basedir blocks it fails the check. Decide a
    fallback staging folder in Task 7 if needed. Sweep stray `.update-probe-*` folders in step 1.
  - Host messages contain absolute paths and the `open_basedir` value: the copyable report (§12) must replace the app
    path with `<app>`.
  - `opcache.restrict_api` is compared without `realpath` (fails safe). No stampede lock on the update check (bounded
    by the 15-minute failure cache).
  - The installed commit shows only in Technical details, which exists only when an update is available.
  - **release.yml:** the job-level `concurrency: release-next` covers the whole publish job, so a third tag queued
    behind two can cancel the middle tag's pending publish, and rc order isn't guaranteed. In Task 13, move the `next`
    step into its own job and skip it when the tag isn't the newest rc. The stale-asset loop hides a `gh` failure
    (harmless). Task 13 is the first real run of the `next` step.
  - `release-policy.json` floor is `2.2.0-rc.1` so rc → rc updates work; v2.2.0 keeps it.
  - Format checks on `version`/`tag`/`released_at`/`php_requirement` and broader Version/Manifest test coverage are
    still open (the build now validates its own manifest with `Manifest::fromJson`).

## Review Focus

- **A tab closed after the swap and never reopened:** after 10 idle minutes the next web request restores the previous release and lifts maintenance → test `RecoveryTest::test_stale_journal_after_swap_rolls_back_without_token` (Task 9).
- **GitHub returns an HTML rate-limit page, a 429 or times out:** the banner shows nothing, the Updates page says "GitHub did not answer, try again later", no exception → `UpdateCheckTest::test_non_json_and_429_are_cached_as_failure` (Task 3).
- **Disk full or a folder not writable during unpack:** the step fails before maintenance is on, the site keeps running the old release, staging is removed → `UnpackTest::test_write_failure_leaves_site_untouched` (Task 7).
- **A malicious or broken zip** (`../`, absolute path, symlink entry, file not in `release-files.json`, hash mismatch) is refused → `ZipSafetyTest` (Task 7).
- **Site-local files and a SQLite database** (`public/.user.ini`, `app/…/.user.ini`, `error_log`, `database/database.sqlite-wal`) survive the swap and are listed → `SwapTest::test_site_local_files_and_sqlite_survive` (Task 8).

---

### Task 1: Release metadata in the build (manifest, file list, policy, `next` channel)

**Files:**
- Create: `release-policy.json` (`{"min_from_version": "2.2.0", "manual_action": null}`)
- Create: `scripts/release-manifest.php` (CLI: `php scripts/release-manifest.php <version> <zip> <out.json>`), logic in `scripts/lib/release_manifest.php`
- Modify: `scripts/build-release.sh` (write `release-files.json` into the tree before zipping; call the manifest script; add `docs/superpowers` to `REMOVE_PATHS`)
- Modify: `.github/workflows/release.yml` (attach `clubportal-manifest.json`; for a tag containing `-`, also replace the assets of the pre-release `next`)
- Test: `tests/Unit/ReleaseManifestScriptTest.php`

**Interfaces:**
- Produces: `release_changelog_section(string $changelog, string $version): ?string`, `release_manifest(string $version, string $zipPath, string $changelog, array $policy, string $releasedAt): array` (keys exactly as the spec's §1 JSON, `format` = 1, `signature` = null, `security` = section contains `### Security`, `last_security_version` = the newest version ≤ this one whose section has `### Security` (scan all sections; null
  if none), `summary` = first line of the section's `> **Upgrade:**` note without the markup, `whats_new` = the bullet lines of
  the section's `### What's new` subsection as plain strings, 1–5 of them; `release_manifest` throws when the
  subsection is missing, empty or has more than 5 bullets).
- Produces: `release-files.json` inside the zip: `{"format":1,"version":"x.y.z","files":{"<relative path>":"<sha256>",…}}`, sorted by path, excluding itself.

- Modify: `CHANGELOG.md`: add a `### What's new` subsection (club-facing bullets) to `## [Unreleased]`. A pre-release
  version (`2.2.0-rc.1`, `2.2.0-dev.1`) with no section of its own uses the `[Unreleased]` section.

- [ ] **Step 1: Write the failing tests** — `test_prerelease_falls_back_to_unreleased`, `test_section_is_extracted_for_the_version` (CHANGELOG fixture with `## [v2.2.1] — …` and `## [v2.2.0] — …` → only 2.2.1's lines), `test_security_flag_follows_the_heading`, `test_manifest_fields_and_zip_hash` (temp zip; `sha256` equals `hash_file('sha256')`, `size` equals filesize, `zip.url` = `https://github.com/amirsubhi/clubcollection/releases/download/v2.2.1/clubportal-2.2.1.zip`, `min_from_version` from the policy array), `test_missing_section_is_an_error` (returns null → CLI exits 1), `test_last_security_version_scans_older_sections` (2.2.3 plain, 2.2.2 security → `"2.2.2"`), `test_whats_new_bullets` (3 bullets → 3 plain strings,
  Markdown `**`/backticks stripped), `test_whats_new_missing_or_over_five_fails_the_build`.
- [ ] **Step 2: Run** `php artisan test --filter=ReleaseManifestScriptTest` → FAIL (functions undefined).
- [ ] **Step 3: Implement** the two functions (plain PHP, `require`d by the test) and the CLI wrapper.
- [ ] **Step 4: Run** the filter → PASS.
- [ ] **Step 5: Wire the build:** in `build-release.sh`, after the "Removing dev-only files" step, generate `release-files.json` (php one-liner over the tree, `hash_file('sha256')`), then after zipping run the manifest script into `dist/clubportal-manifest.json`. In `release.yml`, add `dist/clubportal-manifest.json` to the artifact and the release files; add a step `if: contains(github.ref_name, '-')` that uses `gh release upload next … --clobber` (creating `next` as a pre-release first if missing).
- [ ] **Step 6: Verify locally** (on a PHP 8.4 machine): `scripts/build-release.sh 2.2.0-dev.1 HEAD` → `dist/clubportal-manifest.json` exists, `unzip -l` shows `clubportal-2.2.0-dev.1/release-files.json`, and no `docs/superpowers`.
- [ ] **Step 7: Commit** `Release build: manifest, release-files.json, release policy, next channel`.

### Task 2: Version and Manifest value objects

**Files:**
- Create: `app/Support/Updates/Version.php`, `app/Support/Updates/Manifest.php`, `app/Support/Updates/InvalidManifest.php` (extends `RuntimeException`)
- Test: `tests/Unit/Updates/VersionTest.php`, `tests/Unit/Updates/ManifestTest.php`

**Interfaces:**
- Produces: `Version::compare(string $a, string $b): int` (semver incl. pre-release: `2.2.0-rc.1 < 2.2.0`; `dev` is lower than everything), `Version::isRelease(string $v): bool` (false for `dev`).
- Produces: `Manifest::fromJson(string $json): Manifest` throws `InvalidManifest`; public readonly `string $version, $tag, $zipName, $zipUrl, $zipSha256, $phpRequirement, $minFromVersion, $releasedAt, $notesMarkdown, $notesUrl`, `int $zipSize`, `bool $security`, `?string $summary, $manualAction`, `?string $lastSecurityVersion`, `array $whatsNew` (list of 1–5 strings, each ≤ 200 chars); `Manifest::toArray(): array`.

- [ ] **Step 1: Failing tests** — Version: `['2.1.0','2.2.0',-1]`, `['2.2.0-rc.1','2.2.0',-1]`, `['2.2.0-rc.2','2.2.0-rc.10',-1]`, `['dev','0.0.1',-1]`, equal → 0. Manifest: valid fixture parses; each of these throws: not JSON, `app` ≠ `clubportal`, `format` ≠ 1, `signature` not null, `zip.url` on another host or repo, `sha256` not 64 hex, missing `version`, JSON over 256 KB, `whats_new` missing / empty / over 5 / not strings. `Manifest::isSecurityFor(string $installed): bool` = installed <
  `lastSecurityVersion` (test: installed 2.2.1, latest 2.2.3, last security 2.2.2 → true; installed 2.2.2 → false).
- [ ] **Step 2: Run** `php artisan test --filter='VersionTest|ManifestTest'` → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `Updates: version compare and manifest validation`.

### Task 3: UpdateCheck service and config

**Files:**
- Create: `config/updates.php` (`channel` = `env('UPDATE_CHANNEL','stable')`, `repo` = `amirsubhi/clubcollection`, `cache_seconds` = 3600, `failure_cache_seconds` = 900, `timeout` = 5)
- Create: `app/Support/Updates/UpdateCheck.php`, `app/Support/Updates/UpdateStatus.php`
- Modify: `.env.example` (commented `# UPDATE_CHANNEL=stable`)
- Test: `tests/Feature/Updates/UpdateCheckTest.php`

**Interfaces:**
- Consumes: `Manifest`, `Version`, `ReleaseInfo::version(base_path('release.json'))`.
- Produces: `UpdateCheck::status(bool $fresh = false): UpdateStatus`; `UpdateStatus` readonly: `string $installed`, `?Manifest $latest`, `bool $available` (latest > installed), `?string $error` (plain sentence), `CarbonImmutable $checkedAt`; `UpdateCheck::manifestUrl(): string`. Cache key `updates.status.<channel>`.

- [ ] **Step 1: Failing tests** (`Http::fake`): newer manifest → `available` true; same version → false; `dev` install → `available` false and `error` "This copy was not installed from a release zip: update it with git."; second call within the hour makes no request (`Http::assertSentCount(1)`); `fresh: true` re-fetches; **`test_non_json_and_429_are_cached_as_failure`**: HTML body, 429, connection exception → `error` "GitHub did not answer, try again later." and no request for 15 minutes; `next` channel uses the `/releases/download/next/` URL.
- [ ] **Step 2: Run** `--filter=UpdateCheckTest` → FAIL.
- [ ] **Step 3: Implement** (Laravel `Http::timeout(5)->withOptions(['allow_redirects'=>true])`; size cap by `Content-Length` and body length).
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `Updates: check the release manifest with caching`.

### Task 4: Pre-checks

**Files:**
- Create: `app/Support/Updates/PreChecks.php`, `app/Support/Updates/PreCheck.php`, `app/Support/Updates/UpdaterHost.php` (probe seam, real implementation of host facts)
- Test: `tests/Unit/Updates/PreChecksTest.php`

**Interfaces:**
- Consumes: `Manifest`, `Version`, `BackupRun::canStartProcesses()`.
- Produces: `PreCheck` readonly `string $key, bool $ok, string $message, ?string $fix, ?string $hostMessage, bool $blocking`
  (`message` and `fix` are the plain lines Aminah reads, J3; `hostMessage` is the copyable text for the hosting
  company's support, set for every check the host can fix: `php`, `extensions`, `functions`, `opcache`, `writable`,
  `rename`, `disk`); `PreChecks::run(Manifest $m, bool $full = false): array<PreCheck>`; `PreChecks::allPass(array $checks): bool`; `PreChecks::needsDatabaseBackupTick(): bool`. `UpdaterHost` methods (all overridable in tests via `PreChecks::$host`): `phpVersion(), extensionLoaded(string), disabledFunctions(): array, opcacheValidatesTimestamps(): bool, opcacheResetAllowed(): bool, freeBytes(string $path): float, unwritablePaths(string $base, array $relativePaths): array, canCreateAndRename(string $parent): bool, pendingMigrations(): int, paymentInProgress(): bool, updateRunning(): bool`.
- `disk` also warns (non-blocking) when the account's file count looks close to a quota, best effort.
- Keys (stable, used by the UI): `version_from`, `version_newer`, `php`, `extensions`, `functions`, `opcache`, `writable`, `rename`, `disk`, `no_update_running`, `no_payment`, `migrations`, `backup_database`, `manual_action`.

- [ ] **Step 1: Failing tests** — one test per key with the host fake set to fail it, asserting `ok` false and the exact `message` text (from the spec §3 wording, e.g. opcache: "Your host keeps an old copy of the portal's code in memory and doesn't let the portal refresh it, so the portal can't update itself here.", with the technical terms only in `hostMessage`); installed below `min_from_version` → message points to `docs/RELEASE.md`; `full: false` skips `writable` and `rename` (the expensive ones); `backup_database` is non-blocking but `needsDatabaseBackupTick()` true when `mysqldump` can't run on MariaDB/MySQL;
  `test_host_messages`: each host-fixable failure has a `hostMessage` naming the missing feature and the site's
  domain (e.g. extensions: "Please enable the PHP zip extension (ZipArchive) for the account that runs example.org. It is
  needed by the Club Portal web updater."); `test_no_jargon`: no `message`/`fix` contains `opcache`, `migrat`, `swap`,
  `journal`, `maintenance mode` (the `hostMessage` may); `test_undecidable_check_is_never_unknown`: a host fact that
  throws yields pass-with-no-line or fail-with-fix, never a third state.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (`paymentInProgress`: any `pay:*` cache lock held or a `payment_bills` row created in the last 2 minutes with status open — builder confirms column names in `app/Models/PaymentBill.php`). **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `Updates: pre-checks with plain-language fixes`.

### Task 5: Updates page, status endpoint, dashboard banner

**Files:**
- Create: `app/Http/Controllers/Admin/UpdatesController.php`, `resources/views/admin/updates/index.blade.php`, `resources/views/admin/updates/_banner.blade.php`
- Modify: `routes/web.php` (super admin group: `GET updates` → `index` `admin.updates.index`; `POST updates/check` → `check` `admin.updates.check`, `throttle:10,1,updates.check|`; in the group every admin can reach (club admins and super admins): `GET updates/status`
  → `status` `admin.updates.status`, which shapes its answer by role), the club admin dashboard view rendered by
  `Admin\DashboardController@index` (note container; builder checks whether it is Blade or Inertia), `resources/views/home.blade.php` (include the banner only for `super_admin`), the super admin menu partial (link "Updates")
- Test: `tests/Feature/Admin/UpdatesPageTest.php`

**Interfaces:**
- Consumes: `UpdateCheck::status()`, `PreChecks::run($m, false)`.
- Produces: `status` JSON for a super admin `{installed, available, latest: {version, security, summary, released_at}|null, error}`;
  for a club admin only `{security_waiting: bool, super_admins: [names]}` (J2; no versions, notes or host details);
  never 500 (catch-all → `available:false` / `security_waiting:false`).
- The Updates page follows the J1 sketch: version and release date, "You're on vX", **What's new** (`whatsNew`
  bullets), "Updating takes about 2 minutes. During that time members see a 'back in a few minutes' page. Pick a quiet
  time, such as late evening.", one summary line for the checks ("Your site is ready to update" or the J3 block with
  **Copy message for my host**) with the individual rows under **Show checks**, the security line from J2 when
  `security`, and `notes_markdown` under a **Technical details** link (escaped text). A `dev` install shows the git
  line and no button.
- Banner (J1/J2): blue "**Club Portal vX is available.** You're on vY. [See what's new]"; red "**Important security
  fix: Club Portal vX.** Please update today. It takes about 2 minutes. [Update now]".
- Club admin note (J2): on the club admin dashboard, filled from the status endpoint after load, only when
  `security_waiting`: "A security update for <portal name> is waiting. Your super admin (<names>) can install it." No
  link, no button.

- [ ] **Step 1: Failing tests** — member gets 403 on all three routes; club admin gets 403 on `index` and `check`
  and the limited JSON on `status` (`security_waiting` true only for a newer security release; no `installed` or
  `latest` keys); the club admin dashboard has the note container and no banner; super admin sees installed and latest versions, notes rendered as escaped text (a `<script>` in `notes_markdown` appears escaped), pre-check rows, and, in stage 1, no update button: when all blocking checks pass, the line "To update, follow the upgrade steps" with
  the `docs/RELEASE.md` §2 link (`test_stage_one_has_no_button_and_links_the_manual_steps`); a security banner when the
  installed version is below `last_security_version` even though the latest release itself is not a security release; `status` JSON shape; banner markup present on `/home` for super admin only; the page shows the `whatsNew` bullets, "about 2 minutes" and
  the members line; a failing host check shows the summary line and the copyable host message, rows only under
  **Show checks**; the rendered page contains none of the jargon words from Global Constraints outside the
  Technical details block; `check` bypasses the cache (`Http::assertSentCount(2)`).
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (banner: a `<div hidden>` filled by a small inline script that `fetch`es `admin.updates.status` after load; red when `latest->isSecurityFor(installed)`; the JSON's `latest.security` and the club admin's `security_waiting`
  use the same rule, never the raw `security` field). **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `Updates: page, status endpoint and dashboard banner`.

### Stage 2 rules (revised 28 November 2026, against the journeys and spec §12)

These bind every stage-2 task, on top of Global Constraints. Where an older line in a stage-2 task disagrees, this
section wins.

- **Files, fixed names** (all under `storage/app/updater/`, the "updater dir"): `job.json`, `journal.json`, `lock`
  (the one `flock` file), `pause.html` (members' pause page), `outcome.json` (written by code without Laravel, consumed
  once by Laravel), `history/<YmdHis>-<from>-<to>.json`, `blocked` (a failed migration switches the button off),
  `webhooks/` (saved payment callbacks), `clubportal-<v>.zip`. Marker: `storage/framework/updating` (the swap guard).
- **Statuses, exact strings.** `job.json` `status`: `ready`, `running`, `finished`, `undone`, `failed`,
  `rolled_back` (the last four are `UpdaterHost::TERMINAL_JOB_STATUSES`). `outcome.json` / history `outcome`:
  `finished`, `rolled_back` (a step from the switch on failed and the files were put back), `undone` (abandoned and
  undone by recovery, J4), `failed` (failed before the pause; nothing changed), `blocked` (a migration failed part-way,
  J5 last case). `journal.json` `recovering: true` once recovery starts; no step runs after that.
- **Steps, fixed order and screen names** (journal keys → what Aminah sees):
  `prechecks` "Checking your site is ready"; `download` "Downloading Club Portal v<to>"; `backup` "Saving a copy of
  your club's data"; `unpack` "Checking the new files"; `maintenance` "Pausing the portal for members"; `swap` and
  `opcache` together "Switching to the new version"; `migrate` "Updating your club's records to the new version";
  `health` "Checking everything works"; `finish` "Opening the portal again".
- **Locking.** Every step, slice and recovery takes `flock(LOCK_EX | LOCK_NB)` on `lock`; a request that can't get it
  answers `{status:"busy"}` and the page only watches ("This update is running in another tab"). Each finished step or
  slice sets `last_step_at`, which is the 10-minute clock.
- **Recovery runs from web requests only:** the guard in `artisan` prints one line and exits 0, nothing else.
- **No Laravel, no database, no mail** in `public/update/` and the guard, except the steps that boot Laravel
  in-process on purpose (`backup` on the old code; `migrate` and the boot part of `health` on the new code). Outcomes
  reach the audit log, history, notices and email through `outcome.json` (Task 10).
- **Report text** (Copy report, history, email): versions, PHP version, failing pre-checks, the step, the error and the
  step's last log lines; the app path replaced by `<app>`; never `.env` values, tokens, cookies or database
  credentials. One builder function: `ClubPortalUpdater\Report::text(array $history, string $appPath): string`.
- **Members' pause page** (J6, Malay approved by the owner 28 November 2026; copy it exactly). Rendered in step
  `backup` from the lang files (new keys `updater.pause_title` = ":portal sedang dikemas kini" / ":portal is being
  updated", `updater.pause_back`, `updater.pause_paid`, `updater.pause_form`) in the portal's default language, with the
  portal name from Portal settings; inline CSS, no external files, `<meta http-equiv="refresh" content="30;url=/">`.
  Served with 503 and `Retry-After: 120` by the guard and by Laravel's `maintenance.php` (the `down` file's
  `template`). When `pause.html` is missing the guard serves a built-in bilingual fallback with the same headers.
- **Admin bypass.** Step `maintenance` writes Laravel's `down` file with a random `secret` and `except: ["webhook/*"]`,
  and sets Laravel's maintenance bypass cookie (`laravel_maintenance`, same format as
  `Illuminate\Foundation\Http\MaintenanceModeBypassCookie`, read from vendor before the switch) on the updating admin's
  browser. While the guard file exists the guard answers before Laravel, so the bypass only matters once the guard is
  gone (from `opcache` until `finish`).
- **Saved payment callbacks.** While the guard file exists, a `POST` to `/webhook/toyyibpay` or `/webhook/billplz` is
  saved as `webhooks/<microtime>-<random>.json` (`{path, body, headers: {content-type, x-signature,
  x-toyyibpay-*, x-billplz-*}, at}`; body at most 64 KB; at most 200 files, then refused with 503 unsaved) and answered
  503. Laravel replays them (Task 10).
- **`TERMINAL_JOB_STATUSES` and `NEEDED_FUNCTIONS`** in `UpdaterHost` stay the single lists: extend
  `NEEDED_FUNCTIONS` with every PHP function the updater calls (curl_*, flock, hash_file, ZipArchive is an extension).
- **Tests need PHP 8.4 with vendor installed** (the full suite, plus the MariaDB run CLAUDE.MD describes for
  anything touching the database or migrations).
- **Rulings made in the revision** (record in the ledger; the owner can overturn):
  - The fresh-request "new version is served" check (LiteSpeed workers, §9) is not automated: Task 14 checks it by
    hand on the test host. Cost if wrong: a LiteSpeed host might serve old bytecode until its workers recycle.
  - J7's Dismiss is per admin and stored in the history file (`dismissed_by: [user ids]`), because no migration is
    allowed.
  - Inertia: a 503 during a member's page visit sends the browser to `/` (a normal GET), which shows the pause page
    full-screen; never re-posts a form.

### Task 6: Starting an update (re-confirmation, job, ticks, button) and database-only backup

**Files:**
- Create: `app/Support/Updates/UpdateJob.php`, `app/Support/Updates/UpdaterFiles.php` (paths of the fixed files above;
  `dir()`, `jobPath()`, `journalPath()`, `historyDir()`, `blockedPath()`, `guardPath()`)
- Modify: `UpdatesController` (`POST updates/start` → `start`, `admin.updates.start`, `throttle:5,1,updates.start|`),
  the Updates view (button replaces the stage-1 manual link; password field; TOTP field when 2FA is on; warning line
  when off; the J3-style "It's been done" screens for `backup_database` and `manual_action`; J8 line), `PreChecks`
  (the two advice rows render as advice, not "Failed"; the tight-disk warning shows its fix and host message),
  `UpdaterHost::updateRunning()` reads through `UpdaterFiles`, `app/Console/Commands/BackupRun.php`
  (`--database-only`)
- Test: `tests/Feature/Admin/UpdateStartTest.php`, `tests/Feature/BackupRunTest.php` (new cases),
  `tests/Feature/Admin/UpdatesPageTest.php` (update the stage-1 manual-link test)

**Interfaces:**
- Produces: `UpdateJob::create(Manifest $m, User $u, bool $dbBackupTicked, bool $manualTicked): string` returns the
  plain token (32 random bytes, hex) and writes `job.json` atomically (temp + rename):
  `{manifest, token_hash (sha256 hex), user_id, user_name, created_at, last_step_at, expires_at (+30 min from
  last_step_at), status:"ready", db_backup_ticked, manual_ticked, from_version, to_version, timezone, locale,
  portal_name}` (timezone = `config('app.timezone')`, locale = the portal's default language, portal_name from Portal
  settings). `UpdateJob::current(): ?array` (null when absent; an unreadable file counts as running).
- Cookie `cp_update` = token, path `/update/`, HttpOnly, SameSite=Strict, `Secure` only on https, lifetime 60 min.
- `start` refuses (back with a plain error, no job) when: password wrong (audit `portal.update_failed_check`); 2FA on
  and the code is missing, wrong or replayed; a blocking pre-check fails at submit (`PreChecks::run($m, true)`); a job
  is running; `blocked` exists; a needed tick is missing (`PreChecks::canStart`). Success: audit `portal.update_started`
  `{from, to}`, redirect to `/update/`.
- The tick screens (J3 style, spec rule "No ticks she can't honestly give"): what is needed, **Copy message** for the
  host or installer, and a tick "It's been done (ticked by the person who did it)".
- J8: while a job is running, the page shows "<user_name> is updating the portal to v<to> (started <time in job
  timezone>)" and no button.
- `backup:run --database-only`: same zip naming and `--keep` pruning, database only, no file folders.

- [ ] **Step 1: Failing tests** — every refusal above; success sets the cookie with path `/update/`, writes the job
  with the hashed token (plain token never on disk), audits from/to and redirects to `/update/`; advice rows are not
  labelled "Failed"; tight-disk shows its fix; J8 line and no button while a job runs; `blocked` hides the button with
  the J5 line; `--database-only` zip has the database dump (or SQLite copy) and no `storage/app/private` entries.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `Updates: re-confirmed start, update job and database-only backup`.

### Task 7: Updater library — lock, journal, download, zip safety, unpack

**Files:**
- Create: `public/update/lib/load.php` (requires the lib files; namespace `ClubPortalUpdater`, no Composer, PHP 8.4),
  `public/update/lib/{UpdaterError,Files,Lock,Journal,Download,ZipSafety,Unpack,Report}.php`,
  `public/update/.htaccess` (deny everything under `lib/`)
- Test: `tests/Unit/Updater/{Lock,Journal,Download,ZipSafety,Unpack,Report}Test.php` (require `public/update/lib/load.php`; temp dirs)

**Interfaces:**
- `Files::forApp(string $app)`: the same fixed paths as `App\Support\Updates\UpdaterFiles`, derived from the updater's
  own location (`dirname(__DIR__, 3)` from `lib/`), never from request input.
- `Lock::acquire(string $updaterDir): ?Lock` (non-blocking; null when held), `->release()`.
- `Journal::open(string $dir): Journal`; `->steps()` (`[name => ['state'=>'done|running|failed','started_at'=>int,
  'finished_at'=>?int,'detail'=>mixed,'log'=>string[]]]`), `->begin($step)`, `->finish($step, $detail = null)`,
  `->fail($step, $message)`, `->set($k, $v)`, `->get($k)`, `->touch()` (sets `last_step_at`), `->idleSeconds()`,
  `->isSwapStarted()`, `->isRecovering()`, `->markRecovering()`, `->firstUnfinished(): ?string`; `begin` refuses when
  recovering or when an earlier step is not done; a step `running` for over 120 s counts as interrupted and may be
  begun again. Every write is temp file + `rename`.
- `Download::slice(string $url, string $dest, int $maxSeconds = 20): array{done:bool, bytes:int}` (curl, https only,
  follow at most 3 redirects, `Range` resume from the current size, checks every `fwrite`); `Download::verify(string
  $file, int $size, string $sha256): bool`.
- `ZipSafety::check(\ZipArchive $z, string $topFolder): void` throws `UpdaterError` on `..`, absolute path, a
  backslash, symlink (external attributes `0120000`), entry outside `<topFolder>/`, or a file not in the zip's
  `release-files.json`.
- `Unpack::slice(string $zip, string $staging, int $from, int $count): int` (next index, or -1 when done; every write
  checked, a failed write throws and removes the staging folder); `Unpack::verify(string $staging): array` (paths whose
  hash ≠ `release-files.json`, or listed but missing).
- Staging folder `<app>/../.clubportal-update-<v>/`; when the parent can't be written (open_basedir), fall back to
  `storage/app/updater/staging-<v>/` only if it is on the same filesystem as the app (compare `stat()['dev']`),
  else fail with the J3 host message. Step `prechecks` sweeps stray `.update-probe-*` folders.
- `Report::text(array $history, string $appPath): string` (Stage 2 rules).

- [ ] **Step 1: Failing tests** — lock held → second acquire null; journal round-trip, atomic write (no `.tmp` left),
  idle clock, order refusal, 120 s interrupted rule, recovering refusal; download resume via a `php -S` server started
  in the test, and a size/hash mismatch → false; each ZipSafety bad case; unpack in slices equals a full extract;
  verify flags a tampered file; **`test_write_failure_leaves_site_untouched`**; report replaces the app path with
  `<app>` and contains no `APP_KEY`, `DB_PASSWORD` or token text from a fixture.
- [ ] **Step 2: Run** `--filter=Updater` → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `Updater: lock, journal, download, zip safety and unpack`.

### Task 8: Updater library — swap and rollback

**Files:**
- Create: `public/update/lib/Swap.php`, `public/update/lib/Htaccess.php`
- Test: `tests/Unit/Updater/SwapTest.php`, `HtaccessTest.php`

**Interfaces:**
- Consumes: `Journal`, both `release-files.json` (old from the app, new from staging).
- Produces: `Swap::plan(string $app, string $staging, array $oldFiles, array $newFiles): array<Move>` (top-level items except `public`, `storage`, `database`, `.env*`; inside `database/` only `migrations`, `seeders`, `factories`; `public/build` as a folder; other `public/*` files one by one; never `public/update`); `Swap::run(Journal $j, array $plan, string $oldDir): void` (records each move before doing it; re-runnable); `Swap::rollback(Journal $j): void` (reverses recorded moves in reverse order; idempotent); `Swap::siteLocalFiles(string $app, array $oldFiles, array $plan): array` (copied into the new folders, returned for the result page); `Htaccess::carryCpanelBlock(string $old, string $new): string`.
- Old folder: `<app>/../.clubportal-old-<old version>/`; staging: `<app>/../.clubportal-update-<new version>/`.

- [ ] **Step 1: Failing tests** — fixture old/new trees: after `run` the app equals the new tree plus kept paths; a file only in the old release ends up in the old folder; **`test_site_local_files_and_sqlite_survive`** (`public/.user.ini`, `app/Http/.user.ini`, `error_log`, `database/database.sqlite` and `-wal` unchanged and listed except the database); `.env` and `storage/` untouched; kill simulation: stop after move N (throw from a test hook), then `run` again completes, and `rollback` from any N restores the old tree byte-for-byte; htaccess block carried and not duplicated when already present.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `Updater: folder swap with journaled rollback`.

**Stage 2 addition:** `Swap::run` and
`Swap::rollback` call `Journal::touch()` after every move; `rollback` also restores `public/.htaccess` and removes
`bootstrap/cache/*.php` so the old code never loads the new cached config.

### Task 9: Swap guard, maintenance files, saved callbacks and web-only recovery

**Files:**
- Create: `public/update-guard.php` (PHP 5.6 syntax), `public/update/lib/{Maintenance,Recovery,Outcome}.php`
- Modify: `public/index.php` (first statements, before `bootstrap/preflight.php`: `require __DIR__.'/update-guard.php';`
  then `clubportal_update_guard_web(dirname(__DIR__));`), `artisan` (first statements, before the autoloader:
  `require __DIR__.'/public/update-guard.php'; clubportal_update_guard_cli(__DIR__);`), the PHP 5.6 / 7.4 parse list in
  `.github/workflows/tests.yml` (`old-php-parse`), `PreflightTest`'s token scan
- Test: `tests/Unit/Updater/{Maintenance,Recovery,Outcome}Test.php`, `tests/Feature/UpdateGuardTest.php`

**Interfaces:**
- `clubportal_update_guard_decide($basePath, $method, $path)` (pure, testable): returns `null` (no guard file, or
  recovery ran and the request continues), `array('action'=>'save_webhook')`, or `array('action'=>'pause')`.
  `clubportal_update_guard_web($basePath)` acts on it: `pause` → 503, `Retry-After: 120`, `pause.html` (or the
  built-in fallback) and `exit`; `save_webhook` → save per Stage 2 rules, 503, `exit`; idle ≥ 600 s and the updater
  lib present → `require public/update/lib/load.php; ClubPortalUpdater\Recovery::run($basePath)` under the lock, then
  continue. `clubportal_update_guard_cli($basePath)`: guard file present → print "Club Portal is being updated; try
  again in a few minutes." and `exit(0)`; never runs recovery.
- `Maintenance::on(string $app, string $secret, string $pauseHtml)` writes `storage/framework/down` (Laravel's JSON:
  `except: ["webhook/*"]`, `secret`, `status: 503`, `retry: 120`, `template: $pauseHtml`), `storage/framework/maintenance.php`
  (from Laravel's `maintenance-mode.stub`, read before the switch) and the guard file; `Maintenance::liftGuard(string
  $app)` removes only the guard file; `Maintenance::off(string $app)` removes all three.
  `Maintenance::bypassCookie(string $secret): array{name, value, expires}`.
- `Recovery::run(string $app): string` (outcome): marks the journal `recovering` first; before the switch: job status
  `undone`, `Maintenance::off`; from the switch on: `Swap::rollback`, `opcache_reset` when allowed,
  `Maintenance::off`, job status `undone`; writes `outcome.json` via `Outcome::write`.
- `Outcome::write(string $updaterDir, array $o)`: `{outcome, from, to, step, reason, at, started_at, report}` (report
  from `Report::text`), temp + rename; also writes the history file.

- [ ] **Step 1: Failing tests** — decide: no guard file → null; guard + GET → pause; guard + POST `/webhook/billplz`
  → save_webhook; guard + POST elsewhere → pause; save caps (65 KB body refused, 201st file refused, only the listed
  headers kept, file name never from input); pause headers 503 + `Retry-After: 120` and the fallback when
  `pause.html` is missing; **`test_stale_journal_after_swap_rolls_back_without_token`** (guard + journal switch
  started + `last_step_at` 11 min ago → old tree restored, guard, down and maintenance.php gone, outcome `undone`,
  history written); stale before the switch → maintenance lifted, job `undone`; recovery while another request holds
  the lock → nothing happens; the CLI guard prints and exits and never touches files; the `down` file has
  `except: ["webhook/*"]` and the bypass cookie verifies with Laravel's `MaintenanceModeBypassCookie::isValid`; the
  token scan: the two `require` lines are the first statements of `public/index.php` and `artisan`, and
  `public/update-guard.php` passes the old-PHP scan.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `Updater: swap guard, pause page, saved callbacks and web-only recovery`.

### Task 10: Laravel side of outcomes — history, notices, email, replay, pause page, member 503

**Files:**
- Create: `app/Support/Updates/UpdateOutcomes.php`, `app/Support/Updates/PausePage.php`,
  `app/Http/Middleware/ConsumeUpdaterOutcome.php` (appended to the `web` group; cheap: two `file_exists` calls when
  there is nothing to do), `app/Mail/PortalUpdateResult.php` (never `ShouldQueue`), `lang/{ms,en}/updater.php`
- Modify: the Updates page (last attempt line with time in the job timezone + **View report**; the `blocked` J5
  screen with "The database was restored or checked" confirm button (`POST updates/unblock`, super admin, re-confirm
  password); J7 notice on the super admin dashboard with **Try again** / **Dismiss** (`POST updates/dismiss`)), the
  banner (security release + last outcome not `finished` → the J5 red-strip variant "The security update couldn't be
  installed on this host. Your portal still works. [What to do]"; "Update in progress [Watch]" strip while a job
  runs), the member app's Inertia setup (`router.on('invalid')`: a 503 → `window.location.assign('/')`)
- Test: `tests/Feature/Updates/{UpdateOutcomes,PausePage,WebhookReplay}Test.php`, `UpdatesPageTest` (new cases)

**Interfaces:**
- `UpdateOutcomes::consume(): void` — when `outcome.json` exists and no guard file: take it (rename to a temp name
  first, so two requests never both consume), write the audit row (`portal.update_finished` /
  `portal.update_rolled_back` / `portal.update_failed_check`, with from, to, step, reason), make sure the history file
  exists, write `blocked` when outcome is `blocked`, send `PortalUpdateResult` to every super admin synchronously
  inside try/catch (skipped silently when sending throws), and replay saved webhooks.
- Webhook replay: for each file in `webhooks/` (oldest first, at most 50 per request), build the Request from the
  saved path, body and headers and call `WebhookController@toyyibpay|billplz` through the container (so signature
  checks and the "already processed" check run; the route throttle is skipped). Delete the file on a 2xx; after 3
  failed tries move it to `webhooks/failed/` and log a warning. Runs whenever `webhooks/` has files and there is no
  guard file, even without an outcome.
- `PausePage::render(string $locale, string $portalName): string` (Stage 2 rules); used by the `backup` step (Task 11)
  through the booted app.
- Email text = the screen text of the outcome (J1 done, J4 undone, J5 rolled back, J5 blocked) plus the report.

- [ ] **Step 1: Failing tests** — consume once (second request finds nothing), audit row per outcome, history kept,
  `blocked` written and the button off with the J5 screen, unblock needs the password and removes `blocked`, J7
  notice shown to every super admin until each dismisses it (and gone after a later `finished`), never to club admins;
  mail sent synchronously and a throwing mailer doesn't break the request; replay calls the handler with the saved body
  (a Billplz fixture with a valid signature settles the payment; a replayed duplicate does not settle twice; a bad
  signature is not settled and the file ends in `failed/` after 3 tries); `PausePage` Malay text exactly as J6 with
  the portal name, 30 s refresh to `/`, no external URLs; the security red-strip failure variant; the Watch strip.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS; `npm run typecheck && npm run build`
  (on the VM) → pass.
- [ ] **Step 5: Commit** `Updates: outcomes, history, notices, email, saved callback replay and pause page`.

### Task 11: Updater page and step runner

**Files:**
- Create: `public/update/index.php` (page + `POST ?step=` JSON endpoint), `public/update/lib/{Auth,Context,Steps}.php`,
  `public/update/app.js` and `app.css` are inline in the page (no build step, no external files)
- Test: `tests/Unit/Updater/{Steps,Auth}Test.php`, `tests/Feature/UpdaterPageTest.php` (runs `public/update/index.php`
  in a subprocess or via an include seam)

**Interfaces:**
- `Auth::check(string $updaterDir, ?string $cookie): bool` (`hash_equals` on sha256, expiry from `last_step_at`, job
  status `ready` or `running`).
- `Steps::run(string $name, Context $c): array{step, status:'done'|'more'|'failed'|'busy', next:?string, message,
  wait?:int}`; `Context` holds app path, journal, job and an injectable `$boot` closure (Laravel boot seam for tests).
- Step behaviour = spec §5 steps 1–10 with Stage 2 rules: `prechecks` re-runs the checks through the booted old app
  (`PreChecks::run($m, true)`), sweeps probes and old `.clubportal-old-*` folders; `download`/`unpack` return `more`
  until done; `backup` boots the old app, runs `backup:run --database-only` (or records "database not in backup" when
  the tick covered it) and writes `pause.html` with `PausePage::render`; `maintenance` writes the down files and sets
  the bypass cookie; `swap` per Task 8; `opcache` resets or returns `wait: revalidate_freq + 2`; `migrate` boots the
  new code, runs `migrate --force`, `optimize:clear`, `optimize` (a migrate failure → files rolled back, outcome
  `blocked`); `health` gets the browser's `/up` and `/login` results (200 and markers) plus an in-process boot of the
  new app; a failure → rollback, outcome `rolled_back`; `finish` = `Maintenance::off`, move the new `public/update/` and
  `public/update-guard.php` into place, delete the zip and staging, outcome `finished`, job `finished`. A step before
  the switch that fails → outcome `failed`, job `failed`, maintenance off. Every response sets `last_step_at`.
- Page (spec §12 and J1, J4, J5, J8): the 9 screen steps with ticks; "Keep this page open and your screen on until it
  says Done."; Screen Wake Lock on https (otherwise that line is bold); `beforeunload` warning while running; on
  `visibilitychange` back to visible it re-reads the journal and carries on ("Welcome back, carrying on…"); a request
  that fails is retried 3 times over about 30 seconds ("Connection lost, retrying…"), then, from the switch on, POSTs
  `?step=rollback`; `busy` → "This update is running in another tab" and it only polls; result screens exactly as J1
  (Done), J4 (undone), J5 (rolled back, and the blocked case), each with **Copy report**, **Report a problem** (GitHub
  new-issue URL with the report in the body, trimmed to fit) and **Back to the dashboard**; all times in the job
  timezone. Without a valid cookie: if `outcome.json` or the latest history says what happened, show that screen;
  otherwise "No update in progress" + link to `/admin/updates`. Visiting `/update/` runs the same idle-recovery check
  as the guard.
- Jargon rule: none of the forbidden words outside **Show details** and the report.

- [ ] **Step 1: Failing tests** — Auth: wrong token, expired, finished → false; order enforced; a step while
  recovering → refused; busy when the lock is held; migrate failure → files rolled back, outcome `blocked`, message
  tells the admin to restore the database from the step 3 backup; health failure → rollback, outcome `rolled_back`;
  pre-switch failure → outcome `failed`, maintenance off; finish removes staging and the zip and moves
  `public/update` last; the page HTML has the 9 names, the wake-lock/visibility/retry code, no jargon outside details,
  and no external URLs except the GitHub issue link.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `Updater: browser-driven steps, result screens and report`.

### Task 12: `php artisan app:update`

**Files:**
- Create: `app/Console/Commands/AppUpdate.php` (`app:update {--check} {--yes}`)
- Test: `tests/Feature/AppUpdateCommandTest.php`

**Interfaces:**
- Consumes: `UpdateCheck`, `PreChecks::run($m, true)`, `UpdateJob`, the updater lib via `public/update/lib/load.php`.
- Behaviour: `--check` prints installed / latest / available and exits 0; otherwise confirms (unless `--yes`), runs steps `prechecks…swap` in-process, then migrate + optimize in a child `php artisan` **only if** `BackupRun::canStartProcesses()`, else prints `Run: php artisan migrate --force && php artisan optimize && php artisan up` and leaves maintenance on; prints that the website's PHP cache is not cleared by the CLI. Refuses while a browser update is running (journal).

- [ ] **Step 1: Failing tests** — `--check` output with `Http::fake`; refuses when a job is running; `--yes` path on a fixture runs prechecks→swap and stops with the manual line when processes are disabled (seam).
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `Updates: app:update command`.

**Stage 2 addition:** it takes the same `flock` (refuses with a plain line when held), uses
`Steps::run` for `prechecks` … `swap`, writes `outcome.json` like the page, and its output uses the plain step names.

### Task 13: Docs, changelog and project notes

**Files:**
- Modify: `docs/RELEASE.md` (§2 starts with "Update from the admin"; zip steps stay as the manual path and for sites before v2.2.0; release publishing: check the manifest asset; before publishing, update the previous release to the candidate through the `next` channel), `docs/USER-GUIDE.md` (Updates page), `docs/INSTALL.md` (zip + curl extensions, writable app folder, disk headroom, nginx location for `/update/`), `CHANGELOG.md` (Unreleased: Added / Upgrade note "first release with the button; reach it by hand"), `CLAUDE.MD` (updater layout, guard rule, release-policy.json, never edit a shipped migration), `SECURITY.md` (trust model: checksum only; owner keeps GitHub 2FA and a tag ruleset)
- Test: none new; run the full suite.

- [ ] **Step 1: Write the docs.**
- [ ] **Step 2: Run** `php artisan test` (SQLite) and the MariaDB run (CLAUDE.MD) → all pass; `npm run typecheck && npm run build` → pass.
- [ ] **Step 3: Commit** `Docs: in-app updater`.

**Stage 2 addition:** `CHANGELOG.md` `[Unreleased]` `### What's new` bullets for the button (plain words,
at most 5 in total); `docs/USER-GUIDE.md` covers the result screens, Copy report, the blocked case and what members
see; `SECURITY.md` lists the unauthenticated surface (`/update/`, the guard, saved callbacks) and its limits.

### Task 14: End-to-end proof on a real install (before v2.2.0 is tagged)

Not code: a recorded test run. Pushing pre-release tags publishes them on GitHub, so **ask the owner before pushing any `-rc` tag**.

- [ ] **Step 1:** Tag `v2.2.0-rc.1` (owner's go-ahead), install it by hand on the shared test install (MariaDB) and on a SQLite copy, set `UPDATE_CHANNEL=next`.
- [ ] **Step 2:** Tag `v2.2.0-rc.2` (any harmless change, plus one additive test migration on a throwaway branch build only if needed) and update rc.1 → rc.2 with the button. Expected: all steps green, `app:doctor` Version 2.2.0-rc.2, audit rows.
- [ ] **Step 3:** Forced failures, each followed by a check that the site serves the previous version and `app:doctor` passes: tab closed at every step and resumed; tab closed > 10 minutes (token-free recovery); `schedule:run` fired mid-swap (exits at once); corrupt download (sha mismatch); disk full during unpack (small loop-mounted quota or a full temp dir); a failing migration (rollback + database-restore message); health check 500 (rollback); `opcache_reset` blocked (`opcache.restrict_api`) with `validate_timestamps=1` (waits) and with `=0` (button off).
- [ ] **Step 4:** Write the results in the step log (`docs/option-a-report.md`, no environment details) and open the PR for the owner.

**Stage 2 addition:** Farid's journey (a Billplz sandbox
callback sent while the guard is up is saved and settled after the update, on success and after a rollback); a member
tab open during the update reloads cleanly; two tabs (the second only watches); the LiteSpeed "new version served"
check by hand; the release.yml `next` job split from the carried-over list.
