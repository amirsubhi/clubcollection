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
- Not for members. Only the super admin can update; club admins see only a quiet note for a security release (J2).

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

## User journeys

The machinery below only matters if the people in these stories come out of it calm. Every screen, message and email
the updater shows must fit these journeys; where a journey and a later section disagree, the journey wins and the
section is fixed. (The people are invented. Research notes and sources: "Why these journeys" at the end.)

**The people.**
- **Aminah**, honorary secretary of a 120-member sports club and the portal's super admin. Not technical. Does most
  admin on her phone in the evening, sometimes on the office laptop at lunch. Signs in about twice a month.
- **Ravi**, the treasurer, a club admin (not super admin). Signs in every week to verify transfer slips.
- **Farid**, a member who pays his fee by FPX from his phone, often late at night.
- **Mei Ling**, a second super admin (the club chairperson), who rarely signs in.

**Rules every journey relies on.**
- **Plain words only on screen.** Steps are named for what they mean to Aminah, never "swap", "migrate", "unpack",
  "opcache", "journal" or "maintenance mode". Technical detail sits behind a **Show details** link and in the copyable
  report. The step list she sees:

  | Aminah sees | Steps (§5) |
  |---|---|
  | Checking your site is ready | 1 |
  | Downloading Club Portal v2.2.1 | 2 |
  | Saving a copy of your club's data | 3 |
  | Checking the new files | 4 |
  | Pausing the portal for members | 5 |
  | Switching to the new version | 6, 7 |
  | Updating your club's records to the new version | 8 |
  | Checking everything works | 9 |
  | Opening the portal again | 10 |

- **Always say how long and what members see.** Before, during and after: "usually about 2 minutes", "members see a
  'back in a few minutes' page".
- **Always say what to do next.** No screen ends on an error alone. Every failure screen says (1) whether the site is
  working, (2) whether anything was lost, (3) the one thing to do now, and offers **Copy report** (plain text with the
  versions, the step, the error and the host checks, for a developer or the host's support).
- **Never ask a question Aminah can't answer.** No "keep maintenance mode?", no choices about files.
- **The app is the record, email is a copy.** Every outcome (done, undone, failed) is shown in the app (J7) and
  kept on the Updates page; an email is sent as well only when the site has mail set up (`MAIL_MAILER=log`, the
  default, sends nothing).
- **The portal's own name.** Members' screens use the portal name from Portal settings, not "Club Portal".
- **No ticks she can't honestly give.** Where the engine needs something done by a person with cPanel or file
  access (a database backup on a host without `mysqldump`, a `manual_action` such as a new `.env` setting), the page
  shows a J3-style screen: what is needed, **Copy message** for the host or whoever installed the portal, and an
  "It's been done" tick for that person, worded so Aminah knows she isn't the one meant to tick it.
- **One source of truth.** The banner, the Updates page and the emails all read the same check and the same journal;
  the security banner disappears the moment the update finishes (Ghost's banner that stayed after updating made people
  think they were still exposed).

### J1. The ordinary update

Monday, 9 pm. Aminah signs in on her phone to approve a new member. Above her dashboard a calm blue strip says:
"**Club Portal v2.2.1 is available.** You're on v2.2.0. [See what's new]". She taps it.

The Updates page reads like a phone's update screen:

```
Club Portal v2.2.1                       released 3 Dec 2026
You're on v2.2.0

What's new
• Receipts now show the club's logo
• Fixed: overdue reminders sometimes went out twice

Updating takes about 2 minutes. During that time members
see a "back in a few minutes" page. Pick a quiet time,
such as late evening.

✓ Your site is ready to update        [Show checks]
✓ A copy of your club's data is saved first

[ Update to v2.2.1 ]
```

The release notes come from the manifest's `notes` and are written for clubs, not developers (release checklist:
"What's new" bullets in plain words, at most five, security fixes first).

She taps **Update to v2.2.1**, enters her password and the 6-digit code from her authenticator (§4), and the updater page opens:

```
Updating to v2.2.1 — about 2 minutes
Keep this page open and your screen on until it says Done.

✓ Checking your site is ready
✓ Downloading Club Portal v2.2.1
● Saving a copy of your club's data…
○ Checking the new files
○ Pausing the portal for members
…
```

Each step ticks within seconds. The page keeps her phone screen awake while it runs (Screen Wake Lock API where the
browser has it) and warns her if she tries to leave or close it. After about two minutes:

```
Done. Club Portal is now v2.2.1.
Members can use the portal again.

What's new  • Receipts now show the club's logo  • …
A copy of your club's data from before the update
is kept on your site (with your other backups).

[ Back to the dashboard ]
```

If mail is set up she also gets an email "Club Portal updated to v2.2.1" with the same text. The blue strip is
gone from her dashboard.

### J2. A security fix

A security release is published. When Aminah next opens her dashboard the strip is red: "**Important security fix:
Club Portal v2.2.2.** Please update today. It takes about 2 minutes. [Update now]". The Updates page puts one plain line
on top of the notes: "This update fixes a security problem. Updating soon keeps your members' details safe." No CVE
numbers on the page (they are in the release notes on GitHub, linked as "Technical details").

Aminah only signs in twice a month, so a red strip only she can see may wait two weeks. Ravi, a club admin, signs in
weekly, so **club admins see a quiet note for security releases only** (owner's decision, 28 November 2026), on their
dashboard, with no button and no link to the Updates page:

```
A security update for <portal name> is waiting.
Your super admin (Aminah, Mei Ling) can install it.
```

It names the portal's super admins so Ravi knows whom to tell. Ordinary releases never show club admins anything.

### J3. "Your host doesn't allow this"

Aminah's club is on a host that has turned off the PHP zip extension. The Updates page still shows what's new, but the
button is replaced by:

```
✗ Your site can't update itself on this host yet.
  Your host has turned off a feature the updater needs (PHP zip).

  What to do: send this message to your hosting company's support.
  [ Copy message for my host ]

  Or ask whoever installed the portal to update it by hand
  (instructions: docs/RELEASE.md).
```

The copied message is written to a host's support desk: "Please enable the PHP zip extension (ZipArchive) for the
account that runs <domain>. It is needed by the Club Portal web updater." Every pre-check that can fail has such a
message. Checks are shown only as pass or fail, never "unknown" (Joomla's "No compatibility information" taught people
to ignore warnings). A check that cannot be decided counts as a pass with no line, or as a fail with a fix; the builder
picks per check, and documents the choice.

### J4. Her phone's screen goes off, or the signal drops

Halfway through, Aminah's phone locks (the wake lock is not supported on her old browser) and the page pauses.

- **She comes back within 10 minutes.** The page notices it is visible again and carries on by itself from the first
  unfinished step: "Welcome back, carrying on…". Members saw the "back in a few minutes" page a little longer; nothing
  else happened. If two tabs are open, the second says "This update is running in another tab" and only watches.
- **She presses Back or opens the dashboard in another tab** during the pause: her browser carries the maintenance
  bypass (set when the update starts), so she sees the admin, with a strip "Update in progress [Watch]".
- **She comes back after 10 minutes, or never.** The first web request after that (hers or a member's; not the
  scheduler, §12) undoes the update (§5 recovery): the portal is back on v2.2.0, members can use it, nothing was lost. When
  Aminah opens the updater page or her dashboard she sees: "The update to v2.2.1 didn't finish, because this page was
  closed or the phone's screen went off. Your portal was put back on v2.2.0 at 9:42 pm and is working normally. Nothing
  was lost. [Try again]". An email says the same, if mail is set up.
- **Her signal drops for a moment.** A step whose request fails is retried quietly up to 3 times over about 30 seconds
  ("Connection lost, retrying…") before it counts as failed.

### J5. Something goes wrong and the update undoes itself

At "Checking everything works", the new version's login page returns an error on this host. The updater puts the old
version back by itself and shows:

```
The update didn't work on your site, so we put it back.
Your portal is on v2.2.0 and working normally.
Nothing was lost.

What happened: the new version showed an error on this
host's settings.

What to do: nothing right now. Your portal is safe on
v2.2.0. You can try again after the next release.
If you'd like help, send the report to whoever installed
the portal, or to the Club Portal team.
[ Copy report ]  [ Report a problem ]  [ Back to the dashboard ]
```

If this was a security release, the red strip changes to "The security update couldn't be installed on this host.
Your portal still works. [What to do]", which leads to the same help and the J3 host message, so she is never left
with an alarm and no action. **Report a problem** opens the GitHub issue form with the report filled in; the page
never says "open an issue". An email says the same, if mail is set up. The Updates page shows "Last update attempt: 3 Dec, 9:31 pm, put back automatically [View
report]" and lets her try again later.

The one case where "nothing was lost" is not certain is a database change that failed half-way (§6). That screen says
so honestly, in plain words: "The portal is back on v2.2.0, but one change to your club's records didn't finish. Your
members can still use the portal. The update button is switched off until this is sorted. Send the report to whoever looks after the portal: they may
need to restore the copy of your data saved at 9:30 pm." That is the only journey that ends with someone else's help.

### J6. Farid pays during the update

Farid finishes paying on his bank's FPX page at 9:31 pm, just as the portal pauses. His bank sends him back to the
portal and he sees, in the portal's default language (Bahasa Melayu unless the super admin changed it):

```
<Nama portal> sedang dikemas kini
Kembali dalam beberapa minit. Halaman ini akan dimuat semula sendiri.

Jika anda baru membuat bayaran, bayaran anda selamat.
Resit akan dipaparkan sebaik sahaja portal dibuka semula.

Jika anda sedang menghantar borang atau slip pindahan,
sila hantar semula apabila kami kembali.
```

(English: "<Portal name> is being updated. Back in a few minutes; this page reloads by itself. If you've just paid, your
payment is safe. Your receipt will show as soon as the portal is open again. If you were sending a form or a transfer
slip, please send it again when we're back." The Malay wording was approved by the owner on 28 November 2026.) The page is static, has a 503 status with `Retry-After: 120`, and reloads itself every 30 seconds by going to the
portal's home page (never by re-sending a form). A member in the middle of a form, or a slip upload, when the pause
starts gets this page instead of a result (hence its last line). Inside the member app (Inertia), a 503 during a page visit shows this page full-screen, not an
error box.

Behind the scenes his payment must not be lost: the gateway's confirmation (`/webhook/toyyibpay`, `/webhook/billplz`)
may arrive while the code is being switched. ToyyibPay payments are re-checked by the existing scheduled job, but
Billplz has only the callback. **My call:** while the swap guard is up, a POST to `/webhook/*` is saved as-is (body
and headers) to `storage/app/updater/webhooks/` and answered 503; the final step replays the saved callbacks through
the normal webhook handler (signatures are checked as usual), and the rollback path does the same on the old code.
The pre-check that no bill was opened in the last 2 minutes stays.

### J7. Finding out the next morning

Aminah closed her laptop mid-update and went to bed. At 7 am Ravi signs in to verify slips: the portal works (it was
put back at 9:42 pm). Ravi sees nothing about it (club admins see only the J2 security note). Aminah has an email from
last night if mail is set up, and her dashboard (and Mei Ling's) says, until each of them dismisses it or an
update succeeds: "Last night's update to v2.2.1 didn't finish and was
undone at 9:42 pm. Your portal has been working normally since. [Try again] [Dismiss]".

### J8. Two super admins

Mei Ling signs in while Aminah's update is in its first steps, before the pause. Her Updates page shows "Aminah is updating the portal to v2.2.1
(started 9:30 pm)" and no button. During the pause she can't sign in at all (she sees the members' page, like everyone without the bypass). If the
update was abandoned, Mei Ling sees the J4 message, not a stuck state.

### J9. Not now

Aminah sees the strip at lunch at the office and doesn't want to pause the portal while members are paying. She does
nothing: the strip stays, and nothing happens until she presses the button. (No "remind me later" in this version;
the strip itself is the reminder.)

### Decisions from the owner (28 November 2026)

1. A security fix that waits for a rare sign-in (J2): club admins see the quiet note (option a).
2. The Malay pause-page text in J6: approved as written.

### Why these journeys (research, 28 November 2026)

- Closing the tab is the most common cause of WordPress's stuck "Briefly unavailable for scheduled maintenance", and
  the fix is FTP: dreamhost.com/blog/how-to-fix-wordpress-error-unavailable-scheduled-maintenance; core even left
  maintenance on after a failed auto-update until 2024: core.trac.wordpress.org/ticket/61459.
- WordPress ignores a maintenance file older than 10 minutes: developer.wordpress.org/reference/functions/wp_is_maintenance_mode.
- Nextcloud's "Step N is currently in process" threads come from new users who can't find the hidden `.step` file:
  help.nextcloud.com/t/90535; its backup step times out on shared hosts: help.nextcloud.com/t/175806.
- Matomo one-click updates that silently stay on the old version: issues.matomo.org/14177.
- Ghost's critical-security banner that stayed after updating: forum.ghost.org/t/23673.
- Named steps instead of a spinner for waits over 10 seconds: nngroup.com/articles/progress-indicators.
- WordPress emails the admin after automatic updates and rollbacks (core.trac.wordpress.org/changeset/25837,
  make.wordpress.org/core/2024/04/19/merge-proposal-rollback-auto-update); those emails often land in spam, so the
  same message is also shown in the app.
- Maintenance pages: 503 with `Retry-After`, static, inline CSS: yoast.com/http-503-site-maintenance-seo.

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
  "min_from_version": "2.2.0-rc.1",
  "security": true,
  "last_security_version": "2.2.1",
  "summary": "Security release: …",
  "manual_action": null,
  "notes_markdown": "…the CHANGELOG section for this version…",
  "notes_url": "https://github.com/amirsubhi/clubcollection/releases/tag/v2.2.1",
  "whats_new": ["Receipts now show the club's logo", "Fixed: overdue reminders sometimes went out twice"],
  "signature": null
}
```

- `security` is true when the version's CHANGELOG section has a `### Security` heading. `last_security_version` is
  the newest version, up to and including this one, whose CHANGELOG section has a `### Security` heading (null if
  none). **A site treats the update as a security fix when its installed version is below `last_security_version`**,
  not when `security` is true: a site on v2.2.1 offered v2.2.3 still gets the red banner and the club admin note if
  v2.2.2 was a security release. `summary` is the first line of
  the section's upgrade note. `notes_markdown` is that CHANGELOG section (shown as plain text / safe Markdown, never
  raw HTML).
- `signature` is always `null` for now (room for signing later).
- `min_from_version` and `manual_action` come from a small file kept in the repository, `release-policy.json`
  (`{"min_from_version": "2.2.0-rc.1", "manual_action": null}`; rc.1 so release candidates can update each other), which the release prep commit updates when a release needs
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
  and "security fix" when `security`), linking to the Updates page. Errors show nothing. Member pages never check. A club admin's
  dashboard requests the same endpoint (same cached result) and shows only the J2 note, and only when the latest
  release is a security release newer than the installed one. For a club admin the endpoint returns only `{security_waiting: bool,
  super_admins: [names]}` (no versions, notes or host details).
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
  button is off. Aminah reads "Your host keeps an old copy of the portal's code in memory and doesn't let the portal
  refresh it, so the portal can't update itself here." with the fix "Send the message below to your host, or ask whoever
  installed the portal to update it by hand."; the host message names `opcache.validate_timestamps` and
  `opcache_reset`. Otherwise the updater knows how long to wait (step 7).
- 2FA (owner's decision): a super admin with 2FA must enter a current code; one without 2FA (possible only when
  `REQUIRE_ADMIN_2FA=false`) confirms with the password alone, and the page shows a warning line recommending 2FA.
- Free disk space ≥ 3× the unpacked size; a warning when the account's file count looks close to a quota (best effort).
- Outbound HTTPS to GitHub works (the manifest fetch already proved it).
- No update already running (journal), and no gateway payment being settled (no `pay:*` lock held and no bill opened in
  the last 2 minutes; exact test left to the builder).
- Database: the current schema is fully migrated (`migrate:status` has nothing pending before the update). Aminah
  reads "A previous update to your club's records hasn't finished." with the fix "Ask whoever installed the portal to
  finish it (the technical details are in the report).".
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
  cannot sign in while the site is down. So any visit to `/update/`, and any web request that sees the
  swap guard (step 5), checks the journal: if no step has run for 10 minutes, the update is abandoned and is undone
  without a token: before the swap, the job is dropped and maintenance lifted; from the swap on, the file rollback
  runs (it only restores the previous release, so it is safe unauthenticated). The result is audited and shown the
  next time the super admin opens the Updates page.
- **A failed step is any reply that is not the expected JSON** (HTTP 500, a host error page, a timeout): the page
  retries the step up to 3 times over about 30 seconds (a phone on mobile data, J4), then, from the swap on, rolls
  back. When the page becomes visible again after being hidden, it continues by itself (J4).
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
- **Host facts (research, 28 November 2026; used by the stage-1 pre-checks):**
  - `disk_free_space()` reports the whole filesystem, not the cPanel account's quota, and PHP has no usable way to read
    the quota or the file-count (inode) limit. A low reading is a real fail; a high one proves nothing, so check every
    write's return value during staging, where a full quota shows up.
  - OPcache settings are read with `ini_get` (never restricted). `opcache_get_status()` and `opcache_reset()` are both
    blocked by `opcache.restrict_api`. Under LiteSpeed LSAPI a reset may not reach every worker (unverified): after
    the swap, confirm with a fresh HTTP request that the new version is served.
  - On PHP 8, `function_exists()` is the reliable test for disabled functions; `ini_get('disable_functions')` only
    explains a failure.
  - `is_writable()` is mostly right under CageFS and PHP-FPM but can be fooled by immutable files, open_basedir and a
    full quota: a real write/rename/delete probe is the reliable test. open_basedir is unset by default on
    EasyApache 4; a site limited to its document root or home folder would block staging in `<app>/..` (how common
    that is could not be found: unverified). Task 7 decides a fallback staging folder.
  - `proc_open`/`exec` are often disabled on shared hosts, so `mysqldump` from web PHP is frequently unavailable
    (BackupRun already falls back; the pre-check asks for a person's backup then).

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

### 12. What the journeys need from the engine (journey review, 28 November 2026)

- **Outcomes from code without Laravel.** The standalone updater and the token-free recovery in the swap guard have
  no database or mailer. They write `storage/app/updater/outcome.json`; the next request that boots Laravel (any
  user) moves it into the audit log and the history (below), raises the dashboard notice for every super admin, and
  sends the email **synchronously**, best effort (never queued: with `QUEUE_CONNECTION=database` there may be no
  worker).
- **History.** `storage/app/updater/history/<time>-<from>-<to>.json`: outcome, steps, timings, reason, report text.
  Step 10 does not delete it; the Updates page shows the last attempt and **View report**. A failed migration writes
  a `blocked` flag that switches the button off until a super admin confirms the database was restored or checked,
  because the old code cannot see the new migrations as pending (§3).
- **Report.** Versions, PHP version, failing pre-checks, the step, the error message and the step's last log lines,
  with the app path shown as `<app>`. Never `.env` values, tokens, cookies or database credentials.
- **Saved payment callbacks (J6).**
  - The v2.2.0 guard itself saves them, because every later update runs the installed guard: a POST to
    `/webhook/toyyibpay` or `/webhook/billplz` (body plus the headers the handler needs) is written to
    `storage/app/updater/webhooks/` (at most 200 files of 64 KB) and answered 503.
  - Laravel's `down` file lists `webhook/*` in `except` until step 10, so callbacks between the guard going and
    maintenance ending reach the new code directly.
  - Replay runs on the first booted request after the guard is gone, on whichever code is installed (new after
    success, old after a rollback), through the normal handler with its signature checks, skipping the throttle and
    relying on the handler's "already processed" check.
- **The members' pause page (J6).** In step 3 (booted), read the default language, the portal name and
  `APP_TIMEZONE`, store them in the job, and render one static HTML file from the lang files. The guard and
  `maintenance.php` both serve it with 503, `Retry-After: 120` and a 30-second refresh to `/`. This replaces the
  English text in §5 step 5. All times on updater screens and in history use the job's timezone.
- **The admin's own bypass.** Step 5 gives the updating super admin's browser Laravel's maintenance bypass cookie.
- **Locking.** One `flock` on `storage/app/updater/lock`, shared by every step, slice and the recovery. Each slice
  refreshes the 10-minute clock. Recovery takes the lock and marks the journal `recovering` first; steps refuse to run
  once it is set. A second tab sees the lock and only watches.
- **Recovery runs from web requests only.** The guard in `artisan` just exits: the CLI cannot clear the website's PHP
  cache (§7). (This replaces "or `artisan` run" in §5.)
- **Updater page JavaScript.** Screen Wake Lock while running (https only; otherwise the "keep your screen on" line is
  bold), a `beforeunload` warning, continue on `visibilitychange` back to visible after re-reading the journal, and 3
  retries over about 30 seconds.
- **"What's new" for clubs (J1).** The manifest gains `whats_new: [string]` (at most 5 plain lines) from a
  `### What's new` subsection of the version's CHANGELOG section; the manifest script fails the release build when it
  is missing or longer. `notes_markdown` stays, shown as "Technical details".
- **What is kept.** The database backup follows `backup:run`'s keep-the-last-14 rule; the old files folder is removed
  by the next update's step 1 (no cron, so nothing happens at 7 days).
- **Old member tabs after an update.** Check that the Inertia asset version changes with the build, so an old tab
  reloads instead of asking for `/build` files that were moved away.

## Left to the builder

- UI wording beyond what the User journeys fix (English only on admin pages, per project rule; the members'
  maintenance page is in the portal's default language), and layout within the journeys' sketches.
- Slice sizes for unpack and download; timeouts.
- How "a payment is being settled" is detected (existing `pay:*` cache locks, open bills in the last minutes).
- Whether `release-files.json` hashes are also shown by `app:doctor` as a "modified files" row (nice to have).
- Exact list of root files and how `public/` files are enumerated (from `release-files.json`).
