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

## A2 — Member pay flow on Inertia

**Built:** Member Home, Payments, Invoice/Receipt, new Review & pay page and status-aware PayResult (Berjaya / Dalam proses with polling / Bayaran belum diterima), all on Inertia with BM default. `pay` is POST-only and reuses an open bill only when its gateway and billed amount still match; changing an unpaid fee's amount clears its bill. Webhooks refuse to mark a fee paid when the paid amount is lower (audited as `payment.underpaid_webhook`). Single-payment routes require the owner, an active membership and an active club. Overdue reminder email links to the review page. Old member Blade views removed.

**Checker findings fixed:** underpayment through a reused bill (major), unverified "no money taken" wording on the failed page (major), overdue counts, inactive member/club access, return page without a bill, multi-club tab, `payment.bill_replaced` audit, `<html lang>`, 44px toggle, URL-encoding.

**Fable verdict:** GO.

**Follow-ups (non-blocking):**
- Lock `pay` per payment (`Cache::lock("pay:{id}")`, re-read the row inside) so two tabs or a retry can't create two bills.
- Payments list, generate-future and the dashboard club query should also respect `club_user.is_active` and `clubs.is_active`.
- Before go-live: webhooks fail closed when neither a paid amount nor `bill_amount` is available.
- Before go-live: when a bill code isn't found, fall back to `reference` (only with a paid amount present) so a member paying an old bill after an amount edit is matched or flagged in-app, not just logged.
- Audit a second successful webhook with a different transaction id on an already-paid fee (double payment → refund).
- Throttle `pay` and `generateFuture`; cap how far ahead `period_start` can go; unique index on (club, user, period_start).
- ToyyibPay sandbox: confirm the callback `amount` unit and whether customer-borne charges are included (alongside the `status` vs `status_id` check).
- Billplz callback for a replaced bill on a per-club-key club fails signature and is retried.
- Confirm with the committee that members should see club-wide fund totals.
- `bill_amount` was added to the existing migration because there is no live data; from v1.0 use additive migrations only.
