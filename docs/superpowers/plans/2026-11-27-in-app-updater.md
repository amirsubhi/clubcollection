# In-app Updater Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the super admin see that a newer Club Portal release exists and install it from the admin with one button, safely, on cPanel shared hosting (target release v2.2.0).

**Architecture:** CI publishes a fixed-name `clubportal-manifest.json` and ships `release-files.json` inside the zip. Laravel checks the manifest (cached), shows a banner and an Updates page, runs pre-checks and, after re-confirmation, writes a job and hands over to a standalone, framework-free updater in `public/update/` that the browser drives step by step (download, backup, unpack, guard, swap, cache reset, migrate, health, finish) with a journal that can resume or roll back, including without a token after 10 idle minutes.

**Tech Stack:** PHP 8.4, Laravel 13 (Blade for super admin pages), PHPUnit (`php artisan test`), plain PHP + vanilla JS for the updater, bash + GitHub Actions for the release build.

**Spec:** `docs/superpowers/specs/2026-11-27-in-app-updater-design.md` (approved). Read it before any task.

## Global Constraints

- PHP `>=8.4.1`; Laravel 13; **no new Composer or npm dependencies**.
- No cron, no queue, no background work for the updater. Checks happen on the super admin's dashboard load (cached 1 hour, failures 15 minutes) or on "Check now".
- Never call `api.github.com`. Manifest URL: `https://github.com/amirsubhi/clubcollection/releases/latest/download/clubportal-manifest.json` (`UPDATE_CHANNEL=next` → `…/releases/download/next/clubportal-manifest.json`). Follow redirects; do not hard-code the CDN host.
- Updater code uses only pure PHP: `ZipArchive`, ext-curl, `rename`, `copy`, `unlink`, `opcache_reset` (optional). No `proc_open`/`exec`/`symlink`. Each HTTP step must finish well under 30 s.
- Never touched: `.env*`, `storage/` (except `storage/app/updater/` and `storage/framework/{down,maintenance.php,updating}`), `database/*.sqlite*`, the cPanel block `# php -- BEGIN cPanel-generated handler` … `# php -- END cPanel-generated handler` in `public/.htaccess`.
- `public/` is never renamed. `public/update/` and `public/update-guard.php` are replaced only in the finish step.
- `public/update-guard.php` and the guard lines in `public/index.php` / `artisan` must parse on PHP 5.6 (same rules as `bootstrap/preflight.php`). The guard lives in `public/`, not `bootstrap/`, because `bootstrap/` is swapped.
- Trust model: checksum only. `manifest.zip.url` must start with `https://github.com/amirsubhi/clubcollection/releases/download/`. `signature` must be `null` or absent (format 1).
- Re-confirmation: password, plus a current TOTP (`User::verifyTwoFactorCode`) when `hasEnabledTwoFactor()`; password only otherwise, with a warning line.
- Admin pages and updater pages are English only. Audit actions: `portal.update_started`, `portal.update_finished`, `portal.update_rolled_back`, `portal.update_failed_check`.
- Public repo: no LAN addresses, host names or home paths in code, docs, commits or PR text.
- Migrations stay additive; this feature adds **no migration** (all updater state is in files).
- **The spec's "User journeys" (J1–J9) and its §12 are requirements.** Screens use the journeys' plain words; no
  "swap", "migrate", "unpack", "opcache", "journal" or "maintenance mode" on any screen outside **Show details** and the
  copyable report. Pre-checks are pass or fail, never "unknown". Every failure line has a fix, and host-side fixes have
  a copyable host message.
- Club admins see only the J2 security note (owner's decision, 28 November 2026). Members see nothing except the
  pause page.

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
- Produces: `release_changelog_section(string $changelog, string $version): ?string`, `release_manifest(string $version, string $zipPath, string $changelog, array $policy, string $releasedAt): array` (keys exactly as the spec's §1 JSON, `format` = 1, `signature` = null, `security` = section contains `### Security`, `summary` = first line of the section's `> **Upgrade:**` note without the markup, `whats_new` = the bullet lines of
  the section's `### What's new` subsection as plain strings, 1–5 of them; `release_manifest` throws when the
  subsection is missing, empty or has more than 5 bullets).
- Produces: `release-files.json` inside the zip: `{"format":1,"version":"x.y.z","files":{"<relative path>":"<sha256>",…}}`, sorted by path, excluding itself.

- Modify: `CHANGELOG.md`: add a `### What's new` subsection (club-facing bullets) to `## [Unreleased]`. A pre-release
  version (`2.2.0-rc.1`, `2.2.0-dev.1`) with no section of its own uses the `[Unreleased]` section.

- [ ] **Step 1: Write the failing tests** — `test_prerelease_falls_back_to_unreleased`, `test_section_is_extracted_for_the_version` (CHANGELOG fixture with `## [v2.2.1] — …` and `## [v2.2.0] — …` → only 2.2.1's lines), `test_security_flag_follows_the_heading`, `test_manifest_fields_and_zip_hash` (temp zip; `sha256` equals `hash_file('sha256')`, `size` equals filesize, `zip.url` = `https://github.com/amirsubhi/clubcollection/releases/download/v2.2.1/clubportal-2.2.1.zip`, `min_from_version` from the policy array), `test_missing_section_is_an_error` (returns null → CLI exits 1), `test_whats_new_bullets` (3 bullets → 3 plain strings,
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
- Produces: `Manifest::fromJson(string $json): Manifest` throws `InvalidManifest`; public readonly `string $version, $tag, $zipName, $zipUrl, $zipSha256, $phpRequirement, $minFromVersion, $releasedAt, $notesMarkdown, $notesUrl`, `int $zipSize`, `bool $security`, `?string $summary, $manualAction`, `array $whatsNew` (list of 1–5 strings, each ≤ 200 chars); `Manifest::toArray(): array`.

- [ ] **Step 1: Failing tests** — Version: `['2.1.0','2.2.0',-1]`, `['2.2.0-rc.1','2.2.0',-1]`, `['2.2.0-rc.2','2.2.0-rc.10',-1]`, `['dev','0.0.1',-1]`, equal → 0. Manifest: valid fixture parses; each of these throws: not JSON, `app` ≠ `clubportal`, `format` ≠ 1, `signature` not null, `zip.url` on another host or repo, `sha256` not 64 hex, missing `version`, JSON over 256 KB, `whats_new` missing / empty / over 5 / not strings.
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

- [ ] **Step 1: Failing tests** — one test per key with the host fake set to fail it, asserting `ok` false and the exact `message` text (from the spec §3 wording, e.g. opcache: "Your host caches PHP code and doesn't let the app clear it: update by hand, or ask the host to allow opcache_reset."); installed below `min_from_version` → message points to `docs/RELEASE.md`; `full: false` skips `writable` and `rename` (the expensive ones); `backup_database` is non-blocking but `needsDatabaseBackupTick()` true when `mysqldump` can't run on MariaDB/MySQL;
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
  `latest` keys); the club admin dashboard has the note container and no banner; super admin sees installed and latest versions, notes rendered as escaped text (a `<script>` in `notes_markdown` appears escaped), pre-check rows, and the **Update to v2.2.1** button only when all blocking checks pass; `status` JSON shape; banner markup present on `/home` for super admin only; the page shows the `whatsNew` bullets, "about 2 minutes" and
  the members line; a failing host check shows the summary line and the copyable host message, rows only under
  **Show checks**; the rendered page contains none of the jargon words from Global Constraints outside the
  Technical details block; `check` bypasses the cache (`Http::assertSentCount(2)`).
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (banner: a `<div hidden>` filled by a small inline script that `fetch`es `admin.updates.status` after load; red + "security fix" when `security`). **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `Updates: page, status endpoint and dashboard banner`.

### Task 6: Starting an update (re-confirmation, job, token cookie) and database-only backup

**Files:**
- Create: `app/Support/Updates/UpdateJob.php`
- Modify: `UpdatesController` (`POST updates/start` → `start`, `admin.updates.start`, `throttle:5,1,updates.start|`), the Updates view (password field, TOTP field when 2FA is on, warning line when off, ticks for `backup_database` and `manual_action`), `app/Console/Commands/BackupRun.php` (`--database-only`)
- Test: `tests/Feature/Admin/UpdateStartTest.php`, `tests/Feature/BackupRunTest.php` (new cases)

**Interfaces:**
- Produces: `UpdateJob::create(Manifest $m, User $u, bool $dbBackupTicked): string` (returns the plain token; writes `storage/app/updater/job.json` with `{manifest, token_hash (sha256), user_id, created_at, last_step_at, expires_at (+30 min), status:"ready", db_backup_ticked}` atomically); `UpdateJob::dir(): string` = `storage_path('app/updater')`; cookie name `cp_update`, path `/update/`, HttpOnly, SameSite=Strict, `Secure` only when the request is https.
- `backup:run --database-only`: same zip naming, database only, no file folders, still prunes with `--keep`.

- [ ] **Step 1: Failing tests** — wrong password → back with error, no job, audit `portal.update_failed_check`; 2FA on + missing/wrong/replayed code → refused; 2FA off + right password → job created, redirect to `/update/`, cookie set with path `/update/`, audit `portal.update_started` with from/to; blocking pre-check failing at submit → refused; job already running → refused; `backup_database` tick required when `needsDatabaseBackupTick()`; BackupRun `--database-only` zip contains `database/database.sql` (or the SQLite copy) and no `storage/app/private` entries.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `Updates: re-confirmed start, update job and database-only backup`.

### Task 7: Updater library — journal, download, zip safety, unpack

**Files:**
- Create: `public/update/lib/Journal.php`, `public/update/lib/Download.php`, `public/update/lib/ZipSafety.php`, `public/update/lib/Unpack.php`, `public/update/lib/load.php` (requires the lib files; namespace `ClubPortalUpdater`, no Composer)
- Create: `public/update/.htaccess` (deny everything under `lib/`)
- Test: `tests/Unit/Updater/JournalTest.php`, `DownloadTest.php`, `ZipSafetyTest.php`, `UnpackTest.php` (require `public/update/lib/load.php`; temp dirs)

**Interfaces:**
- Produces: `Journal::open(string $dir): Journal`; `->steps(): array` (`[name => ['state'=>'done|running|failed','started_at'=>int,'detail'=>mixed]]`), `->begin(string $step)`, `->finish(string $step, mixed $detail = null)`, `->fail(string $step, string $message)`, `->set(string $key, mixed $v)`, `->get(string $key)`, `->idleSeconds(): int`, `->isSwapStarted(): bool`; every write is temp file + `rename`.
- Step names (fixed order): `prechecks, download, backup, unpack, maintenance, swap, opcache, migrate, health, finish`.
- `Download::slice(string $url, string $dest, int $maxSeconds = 20): array{done:bool, bytes:int}` (curl, follow redirects, `Range` resume from current size); `Download::verify(string $file, int $size, string $sha256): bool`.
- `ZipSafety::check(\ZipArchive $z, string $topFolder): void` throws `UpdaterError` on `..`, absolute path, symlink (external attributes `0120000`), entry outside `<topFolder>/`.
- `Unpack::slice(string $zip, string $staging, int $from, int $count): int` (returns next index or -1 when done); `Unpack::verify(string $staging): array` (paths whose hash ≠ `release-files.json`, or listed but missing).

- [ ] **Step 1: Failing tests** — journal round-trip and atomic write (no `.tmp` left), `idleSeconds` from `last_step_at`; download resume (local HTTP server via `php -S` in the test, or a `file://` seam) and hash mismatch → false; ZipSafety each bad case; unpack in slices equals a full extract; verify flags a tampered file; **`test_write_failure_leaves_site_untouched`** (staging parent not writable → `UpdaterError`, app folder unchanged, no staging dir left).
- [ ] **Step 2: Run** `--filter=Updater` → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `Updater: journal, download, zip safety and unpack`.

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

### Task 9: Swap guard, maintenance files and token-free recovery

**Files:**
- Create: `public/update-guard.php` (old-PHP syntax), `public/update/lib/Maintenance.php`, `public/update/lib/Recovery.php`
- Modify: `public/index.php` (first statements, before `bootstrap/preflight.php`: `require __DIR__.'/update-guard.php';` and the 503 send) and `artisan` (first statements, before the autoloader: `require __DIR__.'/public/update-guard.php';`), the PHP 5.6 / 7.4 parse list in `.github/workflows/tests.yml` (`old-php-parse`) and `PreflightTest`'s token scan
- Test: `tests/Unit/Updater/MaintenanceTest.php`, `RecoveryTest.php`, `tests/Feature/UpdateGuardTest.php`

**Interfaces:**
- Produces: `public/update-guard.php` defines `clubportal_update_guard($basePath)`: returns null when `storage/framework/updating` is absent; otherwise, if the journal is idle ≥ 600 s, requires `public/update/lib/load.php` and runs `Recovery::run($basePath)` then returns null (request continues normally); else returns `array('status'=>503,'body'=>…)` ("Updating Club Portal, back in a few minutes", `Retry-After: 60`). In `artisan`, a non-null result prints one line and `exit(0)`.
- `Maintenance::on(string $app, string $secret)` writes `storage/framework/down` (Laravel JSON incl. a pre-rendered template), `storage/framework/maintenance.php` (copied from Laravel's stub in `vendor/laravel/framework/src/Illuminate/Foundation/Console/stubs/maintenance-mode.stub`, read **before** the swap) and `storage/framework/updating`; `Maintenance::off(string $app)` removes all three.
- `Recovery::run(string $app): string` — before the swap: drop job, `Maintenance::off`; from the swap on: `Swap::rollback`, opcache reset (if allowed), `Maintenance::off`; writes `storage/app/updater/last-result.json` (`{outcome:"rolled_back"|"abandoned", reason, at}`) which the Updates page shows and audits on next load.

- [ ] **Step 1: Failing tests** — guard: no marker → null; marker + fresh journal → 503 array; **`test_stale_journal_after_swap_rolls_back_without_token`** (marker + journal swap-started + `last_step_at` 11 min ago → old tree restored, marker gone, `last-result.json` outcome `rolled_back`); stale before swap → maintenance lifted, job removed; `artisan` with the marker prints the line and exits 0 (run `php artisan list` against a temp copy is too heavy: test the guard function and assert the two `require` lines are the first statements via token scan, like `PreflightTest`); `public/update-guard.php` passes the old-PHP token scan.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `Updater: swap guard, maintenance files and token-free recovery`.

### Task 10: Updater page and step runner (cache reset, migrate, health, finish)

**Files:**
- Create: `public/update/index.php` (page + `POST ?step=` JSON endpoint), `public/update/lib/Steps.php`, `public/update/lib/Auth.php`
- Test: `tests/Unit/Updater/StepsTest.php`, `AuthTest.php`

**Interfaces:**
- Consumes: Tasks 6–9.
- Produces: `Auth::check(string $updaterDir, ?string $cookie): bool` (hash compare, expiry, status not finished); `Steps::run(string $name, Context $c): array{step:string,status:'done'|'more'|'failed',next:?string,message:string}`; Context holds app path, journal, job. Step behaviour exactly as the spec §5 steps 1–10, with these fixed choices: download/unpack return `more` until done; `backup` and `migrate` boot Laravel in-process (`require vendor/autoload.php; $app = require bootstrap/app.php; $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class); $kernel->call(...)`) — `backup` before the swap (old code), `migrate` after `opcache` (new code), then `optimize:clear` and `optimize`; `opcache` returns `{wait: revalidate_freq+2}` when the reset is not allowed and the page waits that long; `health` is answered by the browser (it fetches `/up` and `/login` with the bypass cookie and POSTs the result as `?step=health`), plus an in-process boot check; `finish` = boot Laravel once more and create the `AuditLog` row `portal.update_finished`, maintenance off, move the new `public/update/` and `public/update-guard.php` into place, delete the zip and staging, delete any `.clubportal-old-*` folder older than this update's.
- Page behaviour: lists the 10 steps; runs them in order; a non-JSON reply or HTTP error → one retry, then (from `swap` on) POST `?step=rollback` and show "Update failed, your site is back on v<old>" with the reason; reload reads the journal and shows **Continue** / **Roll back**; without a valid cookie: "No update in progress" + link to `/admin/updates` (and the guard's recovery covers abandoned runs).

- [ ] **Step 1: Failing tests** — Auth: wrong token, expired, finished → false; Steps on a fixture app (fake Laravel boot via an injectable `Context::$boot` closure): order enforced (`swap` before `unpack` done → failed); a `migrate` failure → file rollback and the message tells the admin to restore the database from the step 3 backup (spec §6); a health failure → rollback; finish removes staging and the zip and moves `public/update` last.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `Updater: browser-driven steps with health check and rollback`.

### Task 11: `php artisan app:update`

**Files:**
- Create: `app/Console/Commands/AppUpdate.php` (`app:update {--check} {--yes}`)
- Test: `tests/Feature/AppUpdateCommandTest.php`

**Interfaces:**
- Consumes: `UpdateCheck`, `PreChecks::run($m, true)`, `UpdateJob`, the updater lib via `public/update/lib/load.php`.
- Behaviour: `--check` prints installed / latest / available and exits 0; otherwise confirms (unless `--yes`), runs steps `prechecks…swap` in-process, then migrate + optimize in a child `php artisan` **only if** `BackupRun::canStartProcesses()`, else prints `Run: php artisan migrate --force && php artisan optimize && php artisan up` and leaves maintenance on; prints that the website's PHP cache is not cleared by the CLI. Refuses while a browser update is running (journal).

- [ ] **Step 1: Failing tests** — `--check` output with `Http::fake`; refuses when a job is running; `--yes` path on a fixture runs prechecks→swap and stops with the manual line when processes are disabled (seam).
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `Updates: app:update command`.

### Task 12: Docs, changelog and project notes

**Files:**
- Modify: `docs/RELEASE.md` (§2 starts with "Update from the admin"; zip steps stay as the manual path and for sites before v2.2.0; release publishing: check the manifest asset; before publishing, update the previous release to the candidate through the `next` channel), `docs/USER-GUIDE.md` (Updates page), `docs/INSTALL.md` (zip + curl extensions, writable app folder, disk headroom, nginx location for `/update/`), `CHANGELOG.md` (Unreleased: Added / Upgrade note "first release with the button; reach it by hand"), `CLAUDE.MD` (updater layout, guard rule, release-policy.json, never edit a shipped migration), `SECURITY.md` (trust model: checksum only; owner keeps GitHub 2FA and a tag ruleset)
- Test: none new; run the full suite.

- [ ] **Step 1: Write the docs.**
- [ ] **Step 2: Run** `php artisan test` (SQLite) and the MariaDB run (CLAUDE.MD) → all pass; `npm run typecheck && npm run build` → pass.
- [ ] **Step 3: Commit** `Docs: in-app updater`.

### Task 13: End-to-end proof on a real install (before v2.2.0 is tagged)

Not code: a recorded test run. Pushing pre-release tags publishes them on GitHub, so **ask the owner before pushing any `-rc` tag**.

- [ ] **Step 1:** Tag `v2.2.0-rc.1` (owner's go-ahead), install it by hand on the shared test install (MariaDB) and on a SQLite copy, set `UPDATE_CHANNEL=next`.
- [ ] **Step 2:** Tag `v2.2.0-rc.2` (any harmless change, plus one additive test migration on a throwaway branch build only if needed) and update rc.1 → rc.2 with the button. Expected: all steps green, `app:doctor` Version 2.2.0-rc.2, audit rows.
- [ ] **Step 3:** Forced failures, each followed by a check that the site serves the previous version and `app:doctor` passes: tab closed at every step and resumed; tab closed > 10 minutes (token-free recovery); `schedule:run` fired mid-swap (exits at once); corrupt download (sha mismatch); disk full during unpack (small loop-mounted quota or a full temp dir); a failing migration (rollback + database-restore message); health check 500 (rollback); `opcache_reset` blocked (`opcache.restrict_api`) with `validate_timestamps=1` (waits) and with `=0` (button off).
- [ ] **Step 4:** Write the results in the step log (`docs/option-a-report.md`, no environment details) and open the PR for the owner.
