# In-app updater: design

Status: approved by the owner (27 November 2026). Target release: v2.2.0.

## Why

Club Portal runs on cPanel shared hosting and is looked after by club treasurers and secretaries, not developers.
Today an upgrade is the manual procedure in `docs/RELEASE.md` §2: upload the zip, unzip in the cPanel Terminal, put
the site in maintenance mode, back up, `cp -a` the release over the site, keep the `.htaccess` PHP lines, delete
removed files, migrate, re-cache, bring the site back and run `app:doctor`. Most owners will not do this, so sites
stay on old releases, including ones with known security holes (v2.1.0 was itself a security release).

The goal: the super admin sees that a new release exists, reads what changed, and installs it by pressing a button,
safely, without a terminal.

## Goals

1. **Check for updates.** The super admin's dashboard shows a banner when a newer release exists (highlighted when it
   is a security release). A **Check for updates** page shows the installed version, the latest version, its release
   notes and whether this site can install it.
2. **Update now.** The super admin chooses to install it. The update runs in short steps driven by their open browser
   page, with a short maintenance window, an automatic file rollback on failure, and a clear result.
3. **Command-line fallback.** `php artisan app:update` runs the same engine, for whoever supports a site.

## Non-goals

- **No cron and no background work.** Nothing is checked, downloaded or installed unless a super admin opens their
  dashboard or presses a button. (The existing scheduler jobs are unchanged; the updater does not use them.)
- **No automatic installs.** Every update is a person's decision.
- **No release signing in this version.** The owner chose to trust GitHub's SHA-256 checksum alone. The manifest keeps
  room for a signature so signing can be added later (see Security).
- **No automatic database rollback.** See Rollback.
- **No downgrades**, and no skipping the manual path for sites older than v2.2.0 (they upgrade to v2.2.0 by hand once).
- Not for club admins or members: only the super admin sees any of it.

## Anti-goals (would make this a failure even if it "works")

- A half-updated site: some new files, some old, after a closed tab, timeout or full disk.
- A site that stays in maintenance mode with no way back for a non-technical admin (Nextcloud's "Step N is currently in
  process, delete the `.step` file" trap).
- Any slowdown or error on normal pages because GitHub is slow, rate-limited or unreachable.
- Touching `.env`, `storage/` (uploads, backups, `.installed`), a SQLite database (`database/*.sqlite*`) or the cPanel
  PHP-handler lines in `public/.htaccess`.
- Updating while a gateway payment is being settled.

## Constraints (from research, 27 November 2026)

- **Shared hosting kills long requests** and may disable `set_time_limit`, `proc_open`, `exec`, `symlink`. The updater
  uses only pure PHP: `ZipArchive`, `curl` (via ext-curl, not shell), `rename`, `copy`, `unlink`. No child processes,
  no symlinks. Each step must finish well inside 30 seconds on a slow host.
- **The process that copies the code must not be the old app.** A running Laravel process that replaces its own
  classes loads a mix of old and new code (Matomo's "class not found" bugs). Steps that need the framework after the
  swap (migrations) run in a fresh request that boots the new code.
- **OPcache:** the website's PHP keeps old bytecode until it notices the change. Only a request served by the website's
  own PHP can reset it (`opcache_reset()` from the CLI does not reach the web pool); `opcache.restrict_api` may block it.
  The swap puts new files at the **same paths**, so a reset that cannot happen is handled explicitly (see step 7).
- **The document root is `public/`** and must never disappear: never rename `public/` itself.
- **GitHub rate limits:** the unauthenticated REST API allows 60 requests an hour per IP, shared by every site on the
  same host. The updater never calls `api.github.com`; it fetches a fixed-name release asset through
  `https://github.com/amirsubhi/clubcollection/releases/latest/download/clubportal-manifest.json` (a web redirect to
  the asset CDN; follow redirects, do not hard-code the CDN host). Handle 429 / 403 by showing "try again later".
- **Inode and disk quotas** (e.g. 150k soft / 250k hard files on some Malaysian hosts): the release is about 8,800
  files and 22 MB zipped. An update briefly needs the staged copy plus the old copy plus the zip and a backup.
- **`mysqldump` is often unavailable** (`BackupRun` already falls back to a files-only backup and says so).
- **Migrations are additive** (rule in `docs/RELEASE.md` §2): the previous release's code keeps working on the new
  schema. This is what makes a files-only rollback safe, and it must be enforced (see Testing).

## Approaches considered

| | Approach | Trade-off | Decision |
|---|---|---|---|
| A | The scheduler (cron) runs `app:update` after the button is pressed | Cron is every 5–15 minutes on many hosts, runs a different PHP binary / ini than the website, and the post-swap steps would wait for the next cron tick (30–45 minutes down) | Rejected |
| B | A small standalone updater page, driven step by step by the super admin's open browser page | The admin keeps the page open for a few minutes; a closed tab is handled by a resumable journal | **Chosen** |
| C | B plus cron finishing an abandoned update | Brings back A's PHP/interval problems and doubles the code to test | Rejected |

The owner also decided: no cron at all; the check happens when the super admin loads their dashboard (and on demand);
checksum only, no signing.

## Design

### 1. The release manifest (built by CI)

`scripts/build-release.sh` / `.github/workflows/release.yml` publish one more asset on every release,
`clubportal-manifest.json` (fixed name, so `releases/latest/download/` always points at the newest):

```json
{
  "format": 1,
  "app": "clubportal",
  "version": "2.2.1",
  "tag": "v2.2.1",
  "released_at": "2026-12-10T03:00:00Z",
  "zip": { "name": "clubportal-2.2.1.zip", "url": "https://github.com/amirsubhi/clubcollection/releases/download/v2.2.1/clubportal-2.2.1.zip", "size": 23068672, "sha256": "…" },
  "php_requirement": ">=8.4.1",
  "min_from_version": "2.2.0",
  "security": true,
  "summary": "Security release: …",
  "manual_action": null,
  "notes_markdown": "…the CHANGELOG section for this version…",
  "notes_url": "https://github.com/amirsubhi/clubcollection/releases/tag/v2.2.1",
  "signature": null
}
```

- `security` is true when the version's CHANGELOG section has a `### Security` heading; `summary` is the first line of
  the section's upgrade note. `notes_markdown` is that CHANGELOG section (shown as plain text / safe Markdown, never
  raw HTML).
- `signature` is always `null` for now (room for signing later).
- `min_from_version` and `manual_action` come from a small file kept in the repository, `release-policy.json`
  (`{"min_from_version": "2.2.0", "manual_action": null}`), which the release prep commit updates when a release needs
  it. `manual_action` is a short text for a release that needs something the updater must not do (e.g. a new `.env`
  setting): the Updates page shows it and the button needs a tick "I have done this". A release whose upgrade cannot
  be done in place sets `min_from_version` to itself, so the button is never offered for it.
- A site below `min_from_version` is told to update by hand with `docs/RELEASE.md` §2 (only `latest` is offered;
  stepping through older releases is out of scope).
- **Testing channel:** besides `latest`, the release workflow keeps a pre-release tagged `next` whose assets are the
  newest release candidate's. `UPDATE_CHANNEL=next` in `.env` (default `stable`) makes a site read
  `…/releases/download/next/clubportal-manifest.json` instead. Same repository and same checks, so no new trust; it
  exists so every release can be installed with the button on a test site before it is published.
- Inside the zip, a new `release-files.json` lists every file in the release with its SHA-256 (used to verify the
  extract, to find files a release removed, and by `app:doctor` later). `release.json` stays as it is.
- The manifest's own integrity rests on HTTPS to GitHub; the zip's on the manifest's `sha256`.

### 2. Checking for updates

- `App\Support\Updates\UpdateCheck` fetches and validates the manifest (Laravel Http client, 5 s timeout, follow
  redirects, size cap ~256 KB, JSON schema check, `app == "clubportal"`), compares `version` with
  `ReleaseInfo::version()` (semver), and caches the result for **1 hour** (also caches failures for 15 minutes so a
  GitHub outage is not retried on every load).
- **Dashboard banner:** the super admin's landing page renders normally; the page then requests
  `GET /admin/updates/status` (JSON). If a newer version exists, a banner shows "Club Portal v2.2.1 is available" (red
  and "security fix" when `security`), linking to the Updates page. Errors show nothing. Member and club-admin pages
  never check.
- **Updates page** (`/admin/updates`, super admin only, in the admin menu): installed version (and commit), latest
  version, release date, release notes, and a **Check now** button that bypasses the cache. Then the **pre-checks**
  (below) and, when they pass, **Update to v2.2.1**.
- A git checkout (version `dev`) shows "This copy was not installed from a release zip: update it with git" and no
  button.

### 3. Pre-checks (before the button is enabled)

Run by the website's PHP when the Updates page loads (cheap ones) and again at step 1 of the update (all):

- Installed version ≥ `min_from_version`; new version > installed (never downgrade or reinstall).
- PHP satisfies `php_requirement`; `ZipArchive` and `curl` available; `disable_functions` does not include anything the
  updater calls (`rename`, `copy`, `unlink`, `opcache_reset` is optional).
- Every file and folder the update replaces is writable by this PHP user (stat the ~8,800 files; owner = current
  user), and a test folder can be created and renamed next to the app folder.
- OPcache: if `opcache.validate_timestamps` is off and `opcache_reset()` is not allowed for this script, the
  button is off ("Your host caches PHP code and doesn't let the app clear it: update by hand, or ask the host to allow
  opcache_reset"). Otherwise the updater knows how long to wait (step 7).
- 2FA (owner's decision): a super admin with 2FA must enter a current code; one without 2FA (possible only when
  `REQUIRE_ADMIN_2FA=false`) confirms with the password alone, and the page shows a warning line recommending 2FA.
- Free disk space ≥ 3× the unpacked size; a warning when the account's file count looks close to a quota (best effort).
- Outbound HTTPS to GitHub works (the manifest fetch already proved it).
- No update already running (journal), and no gateway payment being settled (no `pay:*` lock held and no bill opened in
  the last 2 minutes; exact test left to the builder).
- Database: the current schema is fully migrated (`migrate:status` has nothing pending before the update).
- Backups: whether `backup:run` can include the database on this host. If not, the page says so and requires a tick:
  "I have taken a cPanel backup of the database" before the button works.

Each failed check shows one plain-language line and its fix (e.g. "Your host has turned off ZipArchive. Ask them to
enable the PHP zip extension.").

### 4. Starting an update (Laravel side)

- **Update to vX** asks for the super admin's password, plus a current 2FA code when they have 2FA set up
  (re-confirmation even if recently signed in; password only otherwise, the owner's choice), then:
  - writes the job: `storage/app/updater/job.json` with the target manifest (as fetched now), a random token (stored
    hashed), the admin's user id, expiry (30 minutes from the last step), and status `ready`;
  - audits `portal.update_started` (from, to);
  - sets an `HttpOnly`, `Secure`, `SameSite=Strict` cookie with the token, path `/update/`;
  - redirects to `/update/` (the standalone updater page).

### 5. The standalone updater (`public/update/index.php` + `public/update/steps.php`)

- Plain PHP 8.4, no Composer autoloader, no Laravel, no database access except in the migrate step. It reads the job
  and journal from `storage/app/updater/` (path found from its own location).
- Every request checks the token cookie against the job's hash and the expiry. Without a valid job it shows "No update
  in progress" and a link back to the admin. It can never start an update by itself.
- The page is static HTML + a little JavaScript (no build step) that calls `POST /update/?step=<n>` for each step in
  turn and shows the list with ticks, the current step and any error. Each POST does one step (or one slice of a long
  step) and returns JSON `{step, status, next, message}`.
- **Which updater runs:** the installed release's updater runs every step of its update, rollback included. The new
  release's `public/update/` is staged with everything else but moved into place only in step 10 (or never, on a
  rollback). So the journal and step protocol are private to one version and need no compatibility between releases;
  the release check is that the previous version's updater can install the new release (see Testing).
- **Recovery never needs the token.** A token can expire or its cookie vanish with the browser, and a super admin
  cannot sign in while the site is down. So any visit to `/update/`, and any request or `artisan` run that sees the
  swap guard (step 5), checks the journal: if no step has run for 10 minutes, the update is abandoned and is undone
  without a token: before the swap, the job is dropped and maintenance lifted; from the swap on, the file rollback
  runs (it only restores the previous release, so it is safe unauthenticated). The result is audited and shown the
  next time the super admin opens the Updates page.
- **A failed step is any reply that is not the expected JSON** (HTTP 500, a host error page, a timeout): the page
  retries the step once, then, from the swap on, rolls back.
- **The journal** (`storage/app/updater/journal.json`, written atomically by temp-file + rename after every step):
  steps done, the current step with `started_at`, paths of the staged and old folders, the backup file, and
  `maintenance: true/false`. Reloading the page reads it and offers **Continue** (from the first unfinished step) or,
  once files have been swapped, **Roll back**. A step left "in progress" for more than 2 minutes is treated as
  interrupted and re-run (every step is written to be re-runnable).

Steps:

1. **Pre-checks** (all of §3 again).
2. **Download** the zip to `storage/app/updater/clubportal-<v>.zip` with curl (stream to disk, follow redirects,
   resumable with `Range` across requests if a slice times out), then verify size and SHA-256 against the manifest.
3. **Backup of the database only:** a new `backup:run --database-only` mode (the update never touches uploaded files,
   and zipping them all could pass the request time limit). This step boots the *current* app in this fresh request;
   nothing has changed yet, so that is safe. When the host has no `mysqldump`, the pre-check tick ("I have taken a
   cPanel backup of the database") already covers it and this step records "database not in backup".
4. **Unpack** to a staging folder next to the app, `<app>/../.clubportal-update-<v>/` (same filesystem), in slices of
   N entries per request; refuse entries with `..`, absolute paths or symlinks. Then verify every file against
   `release-files.json`.
5. **Maintenance on, with a swap guard:**
   - Write Laravel's `storage/framework/down` file and `storage/framework/maintenance.php` directly (same format as
     `php artisan down` with a pre-rendered 503 page "Updating Club Portal, back in a few minutes").
   - Also write `storage/framework/updating` (the **swap guard**). `public/index.php` and `artisan` check this file in
     their **first lines, before requiring anything from `bootstrap/` or `vendor/`** (those folders are renamed during
     the swap, and `public/index.php` today requires `bootstrap/preflight.php` before its maintenance check). While it
     exists, a web request gets the plain 503 page (or triggers the abandoned-update recovery above) and `artisan`
     (e.g. the every-minute `schedule:run`) exits at once with a message. These lines stay parseable by old PHP like
     the preflight guard. They ship in v2.2.0, so they are already in place when the first button update runs.
   - The updater's own folder `public/update/` is not affected: it boots neither Laravel nor the guard.
6. **Swap:**
   - The replaced set is every top-level item of the release **except** `public/`, `storage/`, `database/` and
     `.env*`: e.g. `app`, `bootstrap`, `config`, `lang`, `resources`, `routes`, `vendor`, `docs`, root files
     (`artisan`, `composer.*`, `release.json`, `release-files.json`, …). In `database/`, only `migrations/`,
     `seeders/` and `factories/` are swapped; a SQLite database file (`database/*.sqlite*`) is never moved. Each is moved aside with `rename()` to
     `<app>/../.clubportal-old-<old version>/` and the staged one renamed into place. `bootstrap/cache/*.php` is not
     carried over.
   - Inside `public/` (the document root stays put): `public/build/` is swapped the same way; other files in `public/`
     (`index.php`, `sw.js`, icons, `licenses.txt`, …) are copied over one by one after the old ones are saved to the
     old folder. **`public/update/` is not replaced here** (step 10 does it).
   - `public/.htaccess`: the new file is written, then any block starting `# php -- BEGIN cPanel-generated handler`
     (through its END line) from the old file is put back.
   - Files the old release had (old `release-files.json`) that the new one does not are moved to the old folder, so
     they are gone from the site but restorable.
   - **Site-local files** inside a replaced folder (anything not listed in the old `release-files.json`, e.g. a
     `.user.ini`, a host's `error_log`) are copied into the new folder, and the result page lists them. Release files
     always win over a local file with the same path.
   - The journal records each move as it happens, so an interrupted swap is either completed or reversed exactly.
7. **Clear the PHP cache:** `opcache_reset()` in this (website) request. If it is not allowed (but timestamps are
   validated, per the pre-check), the page waits `opcache.revalidate_freq` + 2 seconds before the next step, so no
   request after this point can see old bytecode.
8. **Migrate:** a fresh request boots the **new** code and runs `Artisan::call('migrate', ['--force' => true])`, then
   `optimize:clear` and `optimize`. The output is stored in the journal.
9. **Health check:** the browser requests `/up` and the login page with the maintenance bypass cookie and checks for
   HTTP 200 and the expected markers; the updater also boots the app once and runs the `app:doctor` checks that do not
   need the network. Failure here → automatic **Roll back**.
10. **Maintenance off**, move the new `public/update/` into place, audit `portal.update_finished` (from, to, duration, whether the database was in the backup),
    delete the zip and the staging folder, keep the old folder until the next update (or 7 days; deleted by the next
    update's step 1).

### 6. Rollback

- **Files:** reverse the swap from the journal (rename the old folders back, restore saved `public/` files and
  `.htaccess`, move removed files back), then reset OPcache, clear caches, maintenance off, audit
  `portal.update_rolled_back` with the reason. Available automatically on a failed step 6–9, and by the **Roll back**
  button while an interrupted update is shown.
- **Database:** not rolled back. New migrations are additive, so the previous release runs on the new schema. If a
  migration fails part-way on MariaDB/MySQL (DDL is not transactional), the page says so, keeps the site in the old
  code, and tells the admin how to restore the database from the step 3 backup or their cPanel backup
  (`docs/INSTALL.md` Restore). This is the only manual path, and it is reached only when a migration fails.
- After a successful update, the old release is kept for 7 days, but there is **no "downgrade" button** in this
  version (downgrading after new data is written to new columns is not safe to automate).

### 7. `php artisan app:update`

The same steps (same step classes, called in one process except that migrate/optimize happen after the swap in a new
`php artisan` process **when `proc_open` is allowed**, otherwise it stops after the swap and tells the operator to run
`php artisan migrate --force && php artisan optimize`). Options: `--check` (print latest version), `--yes` (no
prompt). Takes the same job lock, so the browser and CLI can never run at once. The CLI cannot reset the website's
OPcache; it says so.

### 8. Security

- Only the super admin, re-confirming with the password (and a 2FA code when 2FA is set up), can start an update; the standalone updater only executes
  a job the app created, for its token holder, within 30 minutes of the last step.
- **Trust model (owner's decision):** a release is trusted if it is the `latest` release on the GitHub repository and
  its zip matches the manifest's SHA-256 over HTTPS. Someone who controls the GitHub account or the release workflow
  could push code to every site that presses Update. Mitigations to put in place (outside the code): 2FA on the owner's
  GitHub account, a tag protection rule / ruleset so only the owner can create `v*` tags, and release creation only
  from `release.yml`. The manifest's `signature` field and `format` version leave room to require a signature in a later
  release (sites would then refuse unsigned manifests).
- Zip handling refuses path traversal, absolute paths and symlinks; the manifest's `zip.url` must be on `github.com`
  under this repository's releases path.
- `storage/app/updater/` is outside the web root; `public/update/` serves no files other than its page and
  `index.php`.
- Every start, finish, rollback and failed pre-check after pressing the button is audited.

### 9. Hosting notes

- `public/update/index.php` is a second PHP entry point. On Apache/cPanel (the target) it runs as is; nginx setups that
  only pass `index.php` at the root to PHP need a location for `/update/` (document in `docs/INSTALL.md`). The token
  cookie is `Secure` only when the site is on https, so a plain-http test install still works.
- `docs/superpowers/` (design notes) is dropped from the release zip by `scripts/build-release.sh`.

### 10. Testing

- Unit tests: semver compare, manifest validation (bad JSON, wrong app, downgrade, oversize), zip path checks,
  `.htaccess` block carry-over, journal resume decisions.
- Feature tests: the status endpoint (cached, error → nothing), the Updates page and pre-check messages, the
  start-update re-confirmation, the standalone updater refusing without a valid token.
- An end-to-end test on a real install (MariaDB, and one SQLite install): v2.2.0 → a test v2.2.1-rc through the
  `next` channel, plus forced failures: closed tab at every step and resume, a tab closed for over 10 minutes (the
  token-free recovery), the cron `schedule:run` firing mid-swap, a corrupt download, disk full during unpack, a failing migration,
  a health check that returns 500 (rollback), OPcache reset blocked.
- **Release checklist addition:** every release must be tested by updating the previous release to it with the
  button before it is published (WordPress 4.9.3's broken updater stranded every site), and every new migration must be
  run against the previous release's code (additive rule).
- A test that fails when a migration drops or renames a column without being marked as a breaking change (exact rule
  left to the builder).

### 11. Docs

- `docs/RELEASE.md`: "Update from the admin" becomes the first way to upgrade; the zip steps stay as the manual path
  and for sites before v2.2.0. Publishing a release now includes checking the manifest asset.
- `docs/USER-GUIDE.md`: the super admin's Updates page.
- `docs/INSTALL.md`: the hosting needs (zip and curl extensions, writable app folder, disk headroom).

## Left to the builder

- Exact UI wording (English only on admin pages, per project rule), layout of the Updates page and the updater page.
- Slice sizes for unpack and download; timeouts.
- How "a payment is being settled" is detected (existing `pay:*` cache locks, open bills in the last minutes).
- Whether `release-files.json` hashes are also shown by `app:doctor` as a "modified files" row (nice to have).
- Exact list of root files and how `public/` files are enumerated (from `release-files.json`).
