# Credits and third-party notices

Club Portal is built on open-source software and published research. This file lists the direct dependencies with
their licences and copyright holders, the design and code it learned from, and the notices their licences ask for.

- **Every shipped package** (direct and indirect, with versions): [docs/THIRD-PARTY-LICENSES.md](docs/THIRD-PARTY-LICENSES.md).
  It is generated from `composer.lock` and `package-lock.json`; regenerate it after any dependency change with
  `npm run licenses` (or `node scripts/licenses.mjs`) and commit the result.
- **Full licence texts** of every shipped package are collected by the same script into the
  [licence texts section](docs/THIRD-PARTY-LICENSES.md#licence-texts) and into [`public/licenses.txt`](public/licenses.txt),
  which is committed and uploaded with every release, so a deployed site serves them at `/licenses.txt`.

---

## PHP (Composer, `composer.json`)

Installed into `vendor/` by `composer install --no-dev`. Versions are the ones in `composer.lock`.

| Package | Version | Licence | Copyright | Source |
|---|---|---|---|---|
| `laravel/framework` | 13.1.1 | MIT | Taylor Otwell | https://github.com/laravel/framework |
| `laravel/tinker` | 3.0.0 | MIT | Taylor Otwell | https://github.com/laravel/tinker |
| `laravel/ui` | 4.6.3 | MIT | Taylor Otwell | https://github.com/laravel/ui |
| `inertiajs/inertia-laravel` | 2.0.28 | MIT | Jonathan Reinink | https://github.com/inertiajs/inertia-laravel |
| `barryvdh/laravel-dompdf` | 3.1.2 | MIT | 2021 barryvdh (Barry vd. Heuvel) | https://github.com/barryvdh/laravel-dompdf |
| `pragmarx/google2fa` | 8.0.3 | MIT | 2014-2018 Phil, Antonio Carlos Ribeiro and all contributors | https://github.com/antonioribeiro/google2fa |
| `bacon/bacon-qr-code` | 3.0.4 | BSD-2-Clause | 2017-present Ben Scholzen 'DASPRiD' | https://github.com/Bacon/BaconQrCode |

Notable indirect PHP packages:

| Package | Version | Licence | Why it is here | Source |
|---|---|---|---|---|
| `dompdf/dompdf` | 3.1.5 | LGPL-2.1 | PDF engine behind `barryvdh/laravel-dompdf` (ledger PDF) | https://github.com/dompdf/dompdf |
| `dompdf/php-font-lib` | 1.0.2 | LGPL-2.1-or-later | used by dompdf | https://github.com/dompdf/php-font-lib |
| `dompdf/php-svg-lib` | 1.0.2 | LGPL-3.0-or-later | used by dompdf | https://github.com/dompdf/php-svg-lib |
| `guzzlehttp/guzzle` | 7.10.0 | MIT (2011 Michael Dowling and other contributors) | Laravel's HTTP client, used to call ToyyibPay and Billplz | https://github.com/guzzle/guzzle |

The ledger PDF uses the **DejaVu Sans** and **DejaVu Sans Mono** fonts that ship inside dompdf
(`vendor/dompdf/dompdf/lib/fonts/`). They are free fonts under the DejaVu fonts licence (based on the Bitstream Vera
licence): https://dejavu-fonts.github.io/License.html

### LGPL notes

`dompdf/dompdf`, `dompdf/php-font-lib` and `dompdf/php-svg-lib` are licensed under the GNU Lesser General Public
License (v2.1, v2.1 or later, and v3.0 or later). This project complies as follows:

1. **No modifications:** the libraries are used as published, with no changes to their source code.
2. **Separate packages:** they are installed by Composer as separate packages in `vendor/` and loaded at run time,
   not merged into this project's code.
3. **Source available:** the complete source of each library is public at the links above, and `composer install`
   fetches exactly the versions in `composer.lock`.
4. **Licence texts:** the full LGPL texts ship with the libraries (`vendor/dompdf/dompdf/LICENSE.LGPL`,
   `vendor/dompdf/php-font-lib/LICENSE`, `vendor/dompdf/php-svg-lib/LICENSE`).

Anyone can replace these libraries with a modified version by changing `composer.json` / `composer.lock`.

---

## JavaScript (npm, `package.json`)

### Bundled into `public/build` by Vite

Versions are the ones in `package-lock.json`.

| Package | Version | Licence | Copyright | Source |
|---|---|---|---|---|
| `react`, `react-dom` | 18.3.1 | MIT | Facebook, Inc. and its affiliates | https://github.com/facebook/react |
| `@inertiajs/react` | 2.3.29 | MIT | Jonathan Reinink | https://github.com/inertiajs/inertia |
| `@radix-ui/react-checkbox`, `-dialog`, `-dropdown-menu`, `-select`, `-slot`, `-tabs`, `-tooltip` | 1.x / 2.x | MIT | 2022 WorkOS | https://github.com/radix-ui/primitives |
| `recharts` | 2.15.4 | MIT | 2015-present recharts | https://github.com/recharts/recharts |
| `lucide-react` | 1.73.0 | ISC (icons derived from Feather: MIT, 2013-present Cole Bemis) | Lucide Icons and Contributors | https://github.com/lucide-icons/lucide |
| `class-variance-authority` | 0.7.1 | Apache-2.0 | 2022 Joe Bell | https://github.com/joe-bell/cva |
| `clsx` | 2.1.1 | MIT | Luke Edwards | https://github.com/lukeed/clsx |
| `tailwind-merge` | 3.7.0 | MIT | 2021 Dany Castillo | https://github.com/dcastil/tailwind-merge |
| `get-nonce` | 1.0.1 | MIT | 2020 Anton Korzunov | https://github.com/theKashey/get-nonce |

`class-variance-authority` is under the Apache License 2.0; its full text, with all the others, is in
`public/licenses.txt` (served at `/licenses.txt`).

`@inertiajs/core` (pulled in by `@inertiajs/react`) bundles the page-load progress bar from
**NProgress** (MIT, (c) 2013, 2014 Rico Sta. Cruz, https://github.com/rstacruz/nprogress).

### Build tools (not shipped)

Used only to build `public/build` on a developer machine or CI; none of them runs on the server.

| Package | Licence | Copyright | Source |
|---|---|---|---|
| `vite` | MIT | 2019-present VoidZero Inc. and Vite contributors | https://github.com/vitejs/vite |
| `@vitejs/plugin-react` | MIT | 2019-present Yuxi (Evan) You and Vite contributors | https://github.com/vitejs/vite-plugin-react |
| `laravel-vite-plugin` | MIT | Taylor Otwell | https://github.com/laravel/vite-plugin |
| `tailwindcss` | MIT | Tailwind Labs, Inc. | https://github.com/tailwindlabs/tailwindcss |
| `postcss`, `autoprefixer` | MIT | 2013 Andrey Sitnik | https://github.com/postcss/postcss |
| `typescript` | Apache-2.0 | Microsoft Corporation | https://github.com/microsoft/TypeScript |
| `@types/node`, `@types/react`, `@types/react-dom` | MIT | Microsoft Corporation | https://github.com/DefinitelyTyped/DefinitelyTyped |

Tailwind CSS does write some of its own CSS into the built stylesheet: its base styles ("Preflight"), which are
built on **modern-normalize** (MIT, Sindre Sorhus, https://github.com/sindresorhus/modern-normalize). Both are MIT.

### UI mockup (`mockup/package.json`)

The design mockup in [`mockup/`](mockup/README.md) uses the same React, Radix UI, Recharts, Lucide, cva, clsx,
tailwind-merge and build packages as above (same licences), plus `vite-plugin-singlefile` (MIT, 2021-present
Richard S. Tallent, II, https://github.com/richardtallent/vite-plugin-singlefile) to build it as one HTML file. The
mockup is not part of the app or its release.

---

## Loaded from CDNs and font services

The remaining Blade pages (login, installer and the classic admin pages) load these from jsDelivr. Versions are the
ones pinned in `resources/views/layouts/*.blade.php`, `resources/views/install/index.blade.php` and the two Chart.js pages.

| Library | Version | Licence | Copyright | Source |
|---|---|---|---|---|
| Bootstrap | 5.3.3 | MIT | 2011-2024 The Bootstrap Authors | https://github.com/twbs/bootstrap |
| Bootstrap Icons | 1.11.3 | MIT | 2019-2024 The Bootstrap Authors | https://github.com/twbs/icons (page-body icons on the classic Blade pages only; the Blade sidebar and top bar use Lucide) |
| Chart.js | 4.4.4 | MIT | 2014-2024 Chart.js Contributors | https://github.com/chartjs/Chart.js |

Chart.js draws the charts on the classic admin dashboard and the super admin's statistics page.

**Fonts:** the React pages (`resources/views/app.blade.php`) load **Bricolage Grotesque**, **Plus Jakarta Sans** and
**JetBrains Mono** from Google Fonts. All three are under the SIL Open Font License 1.1
(https://openfontlicense.org). The Blade pages use Bootstrap's system font stack and load no text web fonts (only the Bootstrap Icons icon font).

---

## Design and code acknowledgements

- **[shadcn/ui](https://github.com/shadcn-ui/ui)** (MIT, 2023 shadcn): the UI primitives in
  `resources/js/components/ui/` (button, card, dialog, select, tabs, chart and the rest) are written in the shadcn/ui
  style: Radix UI primitives styled with Tailwind, with variants built with `class-variance-authority` and a `cn()`
  helper (`clsx` + `tailwind-merge`). The chart wrapper around Recharts follows shadcn/ui's chart pattern.
- **[satnaing/shadcn-admin](https://github.com/satnaing/shadcn-admin)** (MIT): the admin shell's layout pattern
  (collapsible sidebar, top bar, theme menu, data table with faceted filters) was modelled on it in the design mockup
  and carried into the app (`resources/js/components/app/AdminShell.tsx` for the shell,
  `resources/js/Pages/Admin/FundUsage/Index.tsx` for the faceted filters).
- **[Inertia.js](https://inertiajs.com)** (MIT): the React pages are served by Laravel controllers through Inertia's
  Laravel adapter and React client, so the app keeps Laravel's routing, auth and validation with no separate API.
- **[Lucide](https://lucide.dev)** (ISC): the icons on the React pages, and the sidebar, top bar and menu icons on the
  classic Blade pages (inline SVG paths copied from lucide-react 1.73.0 into `app/Support/Admin/LucideIcons.php`; licence
  text in [`docs/THIRD-PARTY-LICENSES.md`](docs/THIRD-PARTY-LICENSES.md)).
- **[Bootstrap](https://getbootstrap.com)** and **[Bootstrap Icons](https://icons.getbootstrap.com)** (MIT): the
  remaining Blade pages (Bootstrap Icons only for icons inside the page body).

### UX research

The member and admin redesign applied published usability guidance from the Nielsen Norman Group, the Baymard
Institute, the W3C Web Content Accessibility Guidelines (WCAG 2.2), the GOV.UK Design System, Material Design,
Apple's Human Interface Guidelines and others. Every rule and its source is listed in
[mockup/RESEARCH.md](mockup/RESEARCH.md).

---

## Trademarks

ToyyibPay, Billplz, FPX, DuitNow and PayNet, Laravel, Inertia, React, Tailwind CSS, Bootstrap, Google Fonts, cPanel,
GitHub and the other product names mentioned in this project are trademarks or registered trademarks of their
respective owners (FPX and DuitNow belong to Payments Network Malaysia Sdn Bhd, PayNet). They are used only to
describe what the software works with. This project is not affiliated with, sponsored by or endorsed by any of them.
