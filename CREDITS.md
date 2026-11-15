# Third-Party Credits & Notices

This project uses third-party libraries and components. Their licenses and copyright notices are listed below.

---

## PHP / Composer Packages

### Laravel Framework
- **Package:** `laravel/framework`
- **License:** MIT
- **Copyright:** Copyright (c) Taylor Otwell
- **Source:** https://github.com/laravel/framework

### Laravel Tinker
- **Package:** `laravel/tinker`
- **License:** MIT
- **Copyright:** Copyright (c) Taylor Otwell
- **Source:** https://github.com/laravel/tinker

### Laravel UI
- **Package:** `laravel/ui`
- **License:** MIT
- **Copyright:** Copyright (c) Taylor Otwell
- **Source:** https://github.com/laravel/ui

### barryvdh/laravel-dompdf
- **Package:** `barryvdh/laravel-dompdf`
- **License:** MIT
- **Copyright:** Copyright (c) 2021 barryvdh
- **Source:** https://github.com/barryvdh/laravel-dompdf

### dompdf/dompdf
- **Package:** `dompdf/dompdf`
- **License:** GNU Lesser General Public License v2.1 (LGPL-2.1)
- **Source:** https://github.com/dompdf/dompdf

> This library is used as an unmodified, dynamically linked component via Composer.
> The LGPL-2.1 license text is available in `vendor/dompdf/dompdf/LICENSE.LGPL`.
> The full source code for dompdf is available at the link above.

### dompdf/php-font-lib
- **Package:** `dompdf/php-font-lib`
- **License:** GNU Lesser General Public License v2.1 or later (LGPL-2.1-or-later)
- **Source:** https://github.com/dompdf/php-font-lib

> This library is used as an unmodified, dynamically linked component via Composer.
> The LGPL-2.1 license text is available in `vendor/dompdf/php-font-lib/LICENSE`.
> The full source code is available at the link above.

### dompdf/php-svg-lib
- **Package:** `dompdf/php-svg-lib`
- **License:** GNU Lesser General Public License v3.0 or later (LGPL-3.0-or-later)
- **Source:** https://github.com/dompdf/php-svg-lib

> This library is used as an unmodified, dynamically linked component via Composer.
> The LGPL-3.0 license text is available in `vendor/dompdf/php-svg-lib/LICENSE`.
> The full source code is available at the link above.

### pragmarx/google2fa
- **Package:** `pragmarx/google2fa`
- **License:** MIT
- **Copyright:** Copyright 2014-2018 Phil, Antonio Carlos Ribeiro and All Contributors
- **Source:** https://github.com/antonioribeiro/google2fa

### bacon/bacon-qr-code
- **Package:** `bacon/bacon-qr-code`
- **License:** BSD 2-Clause ("Simplified BSD License")
- **Copyright:** Copyright (c) 2017-present, Ben Scholzen 'DASPRiD'
- **Source:** https://github.com/Bacon/BaconQrCode

### GuzzleHTTP
- **Package:** `guzzlehttp/guzzle`
- **License:** MIT
- **Copyright:** Copyright (c) 2011 Michael Dowling and contributors
- **Source:** https://github.com/guzzle/guzzle

### Inertia.js Laravel adapter
- **Package:** `inertiajs/inertia-laravel`
- **License:** MIT
- **Source:** https://github.com/inertiajs/inertia-laravel

---

## Frontend (npm, bundled into `public/build` by Vite)

| Package | License | Source |
|---|---|---|
| `react`, `react-dom` | MIT | https://github.com/facebook/react |
| `@inertiajs/react` | MIT | https://github.com/inertiajs/inertia |
| `tailwindcss` (build time) | MIT | https://github.com/tailwindlabs/tailwindcss |
| `recharts` | MIT | https://github.com/recharts/recharts |
| `@radix-ui/react-*` (checkbox, dialog, dropdown-menu, select, slot, tabs, tooltip) | MIT | https://github.com/radix-ui/primitives |
| `lucide-react` | ISC | https://github.com/lucide-icons/lucide |
| `clsx` | MIT | https://github.com/lukeed/clsx |
| `tailwind-merge` | MIT | https://github.com/dcastil/tailwind-merge |
| `class-variance-authority` | Apache-2.0 | https://github.com/joe-bell/cva |
| `get-nonce` | MIT | https://github.com/theKashey/get-nonce |
| `vite`, `laravel-vite-plugin`, `@vitejs/plugin-react`, `typescript`, `postcss`, `autoprefixer` (build time only) | MIT / Apache-2.0 (TypeScript) | https://vite.dev |

Their transitive dependencies are listed in `package-lock.json`; licence texts ship in `node_modules/*/LICENSE`.

### Fonts (Google Fonts)
- **Bricolage Grotesque, Plus Jakarta Sans, JetBrains Mono** — SIL Open Font License 1.1, loaded from fonts.googleapis.com.

---

## Frontend (CDN)

### Bootstrap
- **Version used:** 5.3
- **License:** MIT
- **Copyright:** Copyright (c) 2011–2024 The Bootstrap Authors
- **Source:** https://github.com/twbs/bootstrap

### Bootstrap Icons
- **Version used:** 1.x
- **License:** MIT
- **Copyright:** Copyright (c) 2019–2024 The Bootstrap Authors
- **Source:** https://github.com/twbs/icons

### Chart.js
- **Version used:** 4.4
- **License:** MIT
- **Copyright:** Copyright (c) 2014–2024 Chart.js Contributors
- **Source:** https://github.com/chartjs/Chart.js

---

## LGPL Compliance Notes

Three packages in this project (`dompdf/dompdf`, `dompdf/php-font-lib`, `dompdf/php-svg-lib`)
are licensed under the GNU Lesser General Public License (LGPL v2.1 or v3.0).

This project complies with the LGPL as follows:

1. **No modifications** — these libraries are used as-is with no modifications to their source code.
2. **Dynamic linking** — they are included as separate Composer packages, not compiled into the application binary.
3. **Source availability** — the complete and corresponding source code for each LGPL library is publicly available at the GitHub links listed above and can be obtained via `composer install`.
4. **License notices** — the full LGPL license texts ship with the libraries under `vendor/dompdf/*/LICENSE*` and are reproduced here by reference.

Users who wish to obtain the source code for any LGPL component may do so by running `composer install` from this repository or by visiting the respective GitHub links above.
