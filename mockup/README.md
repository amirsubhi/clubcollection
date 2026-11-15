# Club Portal design mockup

A clickable, front-end-only mockup of the member and admin screens, made before the React pages were built. It was
used to settle the look (light and dark themes), the BM/EN wording and the flows, and its components and design
tokens were later moved into the app (`resources/js/components/`).

- **Sample data only.** Every name, amount, bank detail and document in it is made up (`src/data/`). It has no
  backend, no login and sends nothing anywhere; it only remembers display preferences (theme, language, phone
  frame, sidebar) in the browser's local storage.
- **Not part of the app.** Nothing in `mockup/` is used by Laravel or shipped in a release. It has its own
  `package.json` and `node_modules`.
- **Research.** The usability rules it applies, with their sources, are in [RESEARCH.md](RESEARCH.md).

## Screens

Open `#overview` for the list. They include member home, invoice, review & pay, the three payment results, payment
history, receipt, the fund (transparency) tab and account; the admin dashboard, Fund Usage list, new expense and
welfare forms, record detail and the phone approval view; and an AGM report preview. Each screen has its own URL
hash, e.g. `#member-home` or `#fund-usage` (see `src/routes.ts`).

## Run or build it

Needs Node 22 and npm. From this folder:

```bash
npm ci
npm run dev            # http://localhost:5173
npm run build          # type-check, then build one self-contained file: dist/index.html
npm run publish-file   # after build: dist/club-portal-mockup.html, a body-only version for hosts that add their own <head>
```

The build inlines all scripts and styles into one HTML file (`vite-plugin-singlefile`), so it can be opened
directly or shared as a single file. It still loads its fonts (Bricolage Grotesque, Plus Jakarta Sans,
JetBrains Mono) from Google Fonts. `dist/` and `node_modules/` are git-ignored.

Licences of the packages it uses are in [CREDITS.md](../CREDITS.md#ui-mockup-mockuppackagejson).
