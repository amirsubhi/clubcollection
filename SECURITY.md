# Security policy

## Reporting a vulnerability

Please report security problems **privately**, not in a public issue, pull request or discussion.

Use GitHub's private vulnerability reporting: open the repository's **Security** tab and choose
**Report a vulnerability** (https://github.com/amirsubhi/clubcollection/security/advisories/new). Include:

- what is affected (page, route, file) and the version or commit;
- steps to reproduce, or a proof of concept;
- what an attacker could do with it.

Please allow reasonable time for a fix before you disclose the problem publicly. Tell us in the report if you would
like to be credited in the advisory.

Please don't test against a club's live site or real member data. Use a local install
([docs/INSTALL.md](docs/INSTALL.md#2-path-a-local-development)) with the demo seeder.

## Supported versions

`v2.1.0` is the current supported release. Security fixes go to the default branch, `master`, and ship in the next
release tag built from it. Older builds, including the March 2026 `v1.0.0` GitHub release (`v0.0.1` in the
changelog), are not patched.
Upgrade with the steps in [docs/RELEASE.md](docs/RELEASE.md).

| Version | Supported |
|---|---|
| `v2.1.0` (current release) | Yes |
| `v2.0.1`, `v2.0.0` | Upgrade to `v2.1.0` (one new migration; fixes five Symfony advisories) |
| `master` (fixes land here first) | Yes |
| `v1.0.0` (March 2026) and any other older build | No |

## Security model (summary)

- **Maker-checker where money moves:** Fund Usage approval and voiding, and changes to the club's bank / DuitNow
  details, need a second admin (the super admin can act as one). Any one club admin can verify a member's transfer
  slip, but not a slip for their own fee.
- **Own-fee rule:** a club admin can't approve their own slip, mark their own fee paid, change its amount or
  discount, or cancel or delete a payment on their own fee (the super admin is exempt). Only the super admin can grant the club-admin role.
- **Accounts:** TOTP 2FA with recovery codes, required for the super admin and club admins (`REQUIRE_ADMIN_2FA`,
  default on) and optional for members; codes can't be reused, the challenge locks after 5 wrong codes, and turning
  2FA off needs a code as well as the password. Sign-in is limited per account + IP (5 a minute) and per IP (30 a
  minute, enough for one office network); 10 wrong passwords for one account from any IP pause its sign-in for 15
  minutes (never permanent, a password reset still works) and email the owner; tune it with
  `LOGIN_ACCOUNT_MAX_FAILURES` (default 10), `LOGIN_ACCOUNT_DECAY_MINUTES` (15) and `LOGIN_ACCOUNT_BLOCK_MINUTES` (15). The 2FA challenge, password reset,
  the installer and uploads have their own limits.
- **Passwords:** a new password (password reset or change) needs 12+ characters and must not be in a known breach
  (Have I Been Pwned range API, only the first 5 characters of the SHA-1 leave the server; passes if the API is
  unreachable). The super admin can turn the breach check off on **Portal settings** (on by default; the change is
  audited), for a host that blocks outgoing connections. The installer's first super-admin password has its own
  rule: 12+ characters with upper- and lower-case letters, numbers and symbols, and always the breach check. Temporary passwords emailed by an admin must be
  replaced at first sign-in. The reset form never says whether an email has an account, and the reset email is sent
  after the answer. A password change or reset ends the user's other sessions and emails the owner. "Keep me signed
  in" lasts 30 days.
- **Private, encrypted files:** documents and slips are encrypted with `APP_KEY` on a private disk and served only to
  that club's admins (and a member's own slip); opening one is audited. Club gateway secrets, welfare personal
  details and 2FA secrets are encrypted columns.
- **Payment callbacks fail closed:** ToyyibPay callbacks need a per-bill HMAC token from a 32+ character secret and
  are confirmed with ToyyibPay's `getBillTransactions` before a fee is marked paid; Billplz callbacks are checked
  with the X-Signature. Callbacks are idempotent and throttled.
- **Web hardening:** CSP with nonces (images only from the site itself), HSTS (configurable, no preload by default),
  `nosniff` / `X-Frame-Options` / `Referrer-Policy` also on static files (`public/.htaccess`), CSRF protection, production URLs pinned to `APP_URL` (other hosts refused),
  CSV formula-injection protection on exports, an installer that needs a one-time `INSTALL_TOKEN`.
- **Audit log** of every write, with old and new values, user, IP and user agent, plus sign-in events (wrong
  passwords, lockouts, account pauses, resets and password changes; never the password).

## The in-app updater (v2.2.0 and later)

The super admin can update the portal from the Updates page. That means the portal downloads and runs code, so the
trust it relies on, and the pages that need no sign-in, are written down here.

**Trust model.** A site installs a release when it is the `latest` release of this repository on GitHub and its zip
matches the SHA-256 in that release's `clubportal-manifest.json`, fetched over HTTPS (the zip's address must be on
`github.com`, under this repository's releases). That is a **checksum only**: there is no signature yet (the manifest has a
`signature` field for a later release). Whoever controls the owner's GitHub account or the release workflow could ship code to
every site that presses **Update**. What reduces that risk is outside the code: the owner keeps two-factor sign-in on the
GitHub account, a tag ruleset lets only the owner create `v*` tags, and releases are created only by `.github/workflows/release.yml`.
The updater refuses zips with path traversal, absolute paths or symbolic links. Starting an update needs the super
admin's password again (and the 2FA code when 2FA is on) and is audited, as are its end and any rollback.

**Pages that need no sign-in** (they have to work while the portal's own code is being switched):

- `/update/` (the standalone updater). Its steps run only with the update's secret token cookie (`cp_update`, stored
  hashed, valid for the job's start up to 30 minutes after its last step; each step answer renews the cookie for that
  long). A visitor with no cookie can only trigger what the update had already started or a person already did: the
  **undo of an update that has been idle for 10 minutes** (it only ever puts the installed release back, and never
  installs anything), the **completion of an interrupted last step**, and **ending an update that was undone by hand**
  (no pause files left, 10 minutes idle: it only records the job as ended). The visitor also reads the screens in plain
  words: the result (what happened and what to do), "waiting to be finished from the command line" with the line to
  run, and "could not be undone by itself" with what members see and the steps to do by hand. **Only the browser
  holding the update's cookie** sees the report (versions, steps and error text, never `.env` values, tokens, cookies or
  database passwords; the app folder is shown as `<app>` and the folder that holds it as `<app-parent>`), the failing
  checks, the list of the site's own files that were kept, and the reason a blocked update gives (it names the backup
  file). It never serves a file.
- **The swap guard** (`public/update-guard.php`, run first by `public/index.php` and `artisan`). While an update is
  switching files it answers every visitor with the pause page (503). It also saves a POST to `/webhook/toyyibpay` or
  `/webhook/billplz` to `storage/app/updater/webhooks/` so a payment confirmation isn't lost: **at most 200 callbacks,
  each at most 64 KB**, in a folder outside the web root, under a name the server chooses (never one from the request).
  Saved callbacks are replayed after the update through the normal handler, so the ToyyibPay token check and the Billplz
  signature check still apply and a forged one is refused as usual. Limits: it only runs while an update is in progress; when
  200 are waiting further callbacks get the 503 and are not kept (ToyyibPay payments are re-checked by the
  scheduler anyway; Billplz retries by its own policy). The guard is plain old-PHP code that reads only fixed paths.

The updater needs the portal at the domain root, keeps its state in `storage/app/updater/` (outside the web root) and
never touches `.env`, `storage/` or the uploaded files.

## Your responsibilities when you deploy it

The software can't secure the server it runs on. Before going live:

- HTTPS only, `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`;
- the web root is the app's `public/` folder, so `.env`, `storage/` and `vendor/` are not reachable;
- `APP_KEY` backed up off-server (without it stored documents can't be read), with regular backups of the database
  and `storage/app/private`;
- strong, random `TOYYIBPAY_WEBHOOK_SECRET` (32+ characters) and the Billplz X-Signature key if you use Billplz;
- `TRUSTED_PROXIES` **empty** unless a proxy or Cloudflare really sits in front of PHP (then list only its addresses):
  a wrong value lets anyone fake their IP and dodge the sign-in limits;
- HSTS: keep `HSTS_INCLUDE_SUBDOMAINS=false` unless every subdomain is HTTPS, and `HSTS_PRELOAD=false` unless you own
  the whole domain and mean to submit it to the preload list;
- `REQUIRE_ADMIN_2FA=true` (the default), so every admin turns on 2FA at their next sign-in;
- the post-install checks in [docs/INSTALL.md](docs/INSTALL.md#5-after-the-install) (`.env`, `vendor/`, logs and
  `.git` not reachable, `APP_DEBUG=false`, `expose_php` off);
- PHP and the app kept up to date.

The full steps are in [docs/INSTALL.md](docs/INSTALL.md), and the pre-launch list is in
[docs/option-a-report.md](docs/option-a-report.md#before-go-live-checklist). The software is provided as is,
without warranty (see the [licence](LICENSE) and the disclaimer in the [README](README.md#disclaimer)).
