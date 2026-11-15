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
