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

Only the default branch, `master`, is supported: security fixes go there and into the next release tag built from
it. Older builds, including the March 2026 `v1.0.0` GitHub release (`v0.0.1` in the changelog), are not patched.
Upgrade with the steps in [docs/RELEASE.md](docs/RELEASE.md).

| Version | Supported |
|---|---|
| `master` | Yes |
| `v1.0.0` (March 2026) and any other older build | No |

## Security model (summary)

- **Maker-checker where money moves:** Fund Usage approval and voiding, and changes to the club's bank / DuitNow
  details, need a second admin (the super admin can act as one). Any one club admin can verify a member's transfer
  slip, but not a slip for their own fee.
- **Own-fee rule:** a club admin can't approve their own slip, mark their own fee paid, or change its amount or
  discount (the super admin is exempt). Only the super admin can grant the club-admin role.
- **Accounts:** optional TOTP 2FA with recovery codes (codes can't be reused; the challenge locks after 5 wrong codes);
  rate limits on login, 2FA, password reset, the installer and uploads; a password change ends the user's other sessions.
- **Private, encrypted files:** documents and slips are encrypted with `APP_KEY` on a private disk and served only to
  that club's admins (and a member's own slip); opening one is audited. Club gateway secrets, welfare personal
  details and 2FA secrets are encrypted columns.
- **Payment callbacks fail closed:** ToyyibPay callbacks need a per-bill HMAC token from a 32+ character secret and
  are confirmed with ToyyibPay's `getBillTransactions` before a fee is marked paid; Billplz callbacks are checked
  with the X-Signature. Callbacks are idempotent and throttled.
- **Web hardening:** CSP with nonces, CSRF protection, production URLs pinned to `APP_URL` (other hosts refused),
  CSV formula-injection protection on exports, an installer that needs a one-time `INSTALL_TOKEN`.
- **Audit log** of every write, with old and new values, user, IP and user agent.

## Your responsibilities when you deploy it

The software can't secure the server it runs on. Before going live:

- HTTPS only, `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`;
- the web root is the app's `public/` folder, so `.env`, `storage/` and `vendor/` are not reachable;
- `APP_KEY` backed up off-server (without it stored documents can't be read), with regular backups of the database
  and `storage/app/private`;
- strong, random `TOYYIBPAY_WEBHOOK_SECRET` (32+ characters) and the Billplz X-Signature key if you use Billplz;
- `TRUSTED_PROXIES` set if your host puts a proxy in front of PHP;
- 2FA turned on for the super admin and club admins, and PHP and the app kept up to date.

The full steps are in [docs/INSTALL.md](docs/INSTALL.md), and the pre-launch list is in
[docs/option-a-report.md](docs/option-a-report.md#before-go-live-checklist). The software is provided as is,
without warranty (see the [licence](LICENSE) and the disclaimer in the [README](README.md#disclaimer)).
