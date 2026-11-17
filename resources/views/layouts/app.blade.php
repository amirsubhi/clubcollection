<!DOCTYPE html>
{{--
    Classic (Blade + Bootstrap) admin pages. Same sidebar as the Inertia pages
    (App\Support\Admin\AdminNav), same Lavender tokens and saved theme
    (resources/css/bootstrap-theme.css re-skins Bootstrap from tokens.css).
--}}
@php
    $adminNav = \App\Support\Admin\AdminNav::forRequest(request());
    $classicBundled = \App\Support\FrontendAssets::classicBundled();
    $bsTheme = $classicBundled
        || (is_file($manifest = public_path('build/manifest.json'))
            && str_contains((string) file_get_contents($manifest), 'resources/css/bootstrap-theme.css'));
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- The saved theme (cp-theme, shared with the React pages) before first paint: data-theme drives the tokens, data-bs-theme drives Bootstrap. --}}
    <script nonce="{{ $cspNonce }}">
        (function () {
            var d = document.documentElement, t = null;
            try { t = localStorage.getItem('cp-theme'); } catch (e) {}
            var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
            function apply(pref) {
                if (pref === 'light' || pref === 'dark') d.setAttribute('data-theme', pref); else d.removeAttribute('data-theme');
                d.setAttribute('data-bs-theme', d.getAttribute('data-theme') || (mq && mq.matches ? 'dark' : 'light'));
                d.setAttribute('data-theme-pref', pref === 'light' || pref === 'dark' ? pref : 'system');
                if (window.cpThemeColor) window.cpThemeColor();
            }
            apply(t);
            if (mq && mq.addEventListener) mq.addEventListener('change', function () { apply(d.getAttribute('data-theme')); });
            window.cpSetTheme = function (pref) {
                try { localStorage.setItem('cp-theme', pref); } catch (e) {}
                apply(pref);
            };
        })();
    </script>
    @include('partials.pwa-head')
    <title>@yield('title', 'Admin') · Club Portal</title>
    {{-- Inline page scripts that need window.bootstrap or window.Chart start from here: the bundled copies are module scripts and run after them. --}}
    <script nonce="{{ $cspNonce }}">
        window.cpWhenReady = function (name, fn) {
            if (name === 'chart' ? window.Chart : window.bootstrap) fn();
            else document.addEventListener('cp:' + name + '-ready', fn, { once: true });
        };
    </script>
    @if ($classicBundled)
        {{-- Bootstrap, Bootstrap Icons and the self-hosted fonts, bundled by Vite (resources/css/classic.css, resources/js/classic.ts). --}}
        @include('partials.font-preload')
        @vite(['resources/css/classic.css', 'resources/css/bootstrap-theme.css', 'resources/js/classic.ts'])
    @else
        {{-- public/build is missing or predates the bundled Bootstrap: the pinned jsDelivr copies (the CSP allows jsDelivr only then). --}}
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.min.css" integrity="sha384-nWN3KqaaVcbEsCjLSV+2jMjh62Iny/+mN3srM8TZeM8N6aj7eDl5wwYggjAJ4/sJ" crossorigin="anonymous">
        @if ($bsTheme)
            @vite(['resources/css/bootstrap-theme.css'])
        @endif
    @endif
    {{-- Page structure only (Bootstrap variables), so the layout holds even when public/build is missing. --}}
    <style nonce="{{ $cspNonce }}">
        .admin-layout { display: flex; min-height: 100vh; }
        .admin-sidebar-col { flex-shrink: 0; }
        .admin-sidebar { --bs-offcanvas-width: 288px; --bs-offcanvas-bg: var(--bs-body-bg); }
        .sidebar-inner { display: flex; flex-direction: column; gap: 1.25rem; width: 100%; padding: 1rem 0.75rem; }
        .sidebar-brand { display: flex; align-items: center; gap: 0.625rem; min-height: 2.75rem; padding: 0 0.25rem; color: var(--bs-body-color); text-decoration: none; border-radius: 8px; }
        .sidebar-brand .logo { display: flex; align-items: center; justify-content: center; width: 2.25rem; height: 2.25rem; border-radius: 10px; background: var(--bs-primary); flex-shrink: 0; }
        .sidebar-brand .name { display: block; font-weight: 800; font-size: 0.9375rem; line-height: 1.2; }
        .sidebar-brand .sub { display: block; font-size: 0.8125rem; color: var(--bs-secondary-color); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 11.5rem; }
        .admin-nav { display: flex; flex-direction: column; gap: 1.25rem; }
        .admin-nav .group-title { padding: 0 0.625rem 0.375rem; font-size: 0.8125rem; font-weight: 600; color: var(--bs-secondary-color); overflow-wrap: anywhere; }
        .admin-nav ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 2px; }
        .admin-nav a { position: relative; display: flex; align-items: center; gap: 0.625rem; min-height: 2.5rem; padding: 0.375rem 0.625rem; border-radius: 8px; font-size: 0.875rem; font-weight: 500; color: var(--bs-body-color); text-decoration: none; }
        .admin-nav a:hover { background: var(--bs-tertiary-bg); }
        .admin-nav a[aria-current="page"] { background: var(--bs-primary-bg-subtle); color: var(--bs-primary-text-emphasis); font-weight: 600; }
        .admin-nav a[aria-current="page"]::before { content: ""; position: absolute; left: -0.75rem; top: 0.5rem; bottom: 0.5rem; width: 4px; border-radius: 0 4px 4px 0; background: var(--bs-primary); }
        .admin-nav .lucide { flex-shrink: 0; }
        {{-- Colours of the count come from bootstrap-theme.css (primary / primary-foreground tokens, 4.5:1+ in both themes). --}}
        .admin-nav .count { margin-left: auto; min-width: 1.375rem; height: 1.375rem; padding: 0 0.4rem; display: inline-flex; align-items: center; justify-content: center; border-radius: 999px; font-size: 0.75rem; font-weight: 700; }
        .lucide { display: inline-block; vertical-align: -0.2em; }
        .admin-main { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; }
        .admin-topbar { position: sticky; top: 0; z-index: 1020; display: flex; align-items: center; gap: 0.5rem; min-height: 3.5rem; padding: 0 max(16px, env(safe-area-inset-right, 0px)) 0 max(16px, env(safe-area-inset-left, 0px)); border-bottom: 1px solid var(--bs-border-color); background: var(--bs-body-bg); }
        .icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 2.75rem; height: 2.75rem; padding: 0; border: 0; border-radius: 10px; background: transparent; color: var(--bs-body-color); font-size: 1.25rem; }
        .icon-btn:hover { background: var(--bs-tertiary-bg); }
        .avatar { display: inline-flex; align-items: center; justify-content: center; width: 2.25rem; height: 2.25rem; border-radius: 50%; background: var(--bs-primary-bg-subtle); color: var(--bs-primary-text-emphasis); font-size: 0.8125rem; font-weight: 700; }
        .admin-content { width: 100%; max-width: 80rem; margin: 0 auto; padding: 1.5rem max(16px, env(safe-area-inset-right, 0px)) 3rem max(16px, env(safe-area-inset-left, 0px)); }
        .page-head { margin-bottom: 1.5rem; }
        .page-head h1 { margin: 0; font-size: clamp(1.375rem, 1.1rem + 1vw, 1.75rem); font-weight: 800; line-height: 1.2; overflow-wrap: anywhere; }
        .page-head .page-club { margin: 0 0 0.25rem; font-size: 0.875rem; color: var(--bs-secondary-color); }
        .date-echo { min-height: 1.25em; margin-top: 0.25rem; font-size: 0.8125rem; color: var(--bs-secondary-color); }
        @media (min-width: 992px) {
            /* The column stretches to the page height (so its colour never stops short); the menu inside stays in view. */
            .admin-sidebar-col { width: 16rem; border-right: 1px solid var(--bs-border-color); background: var(--bs-tertiary-bg); }
            .admin-sidebar-col > .admin-sidebar { position: sticky; top: 0; max-height: 100vh; overflow-y: auto; }
            .admin-sidebar .offcanvas-body { width: 100%; }
            .admin-content { padding-top: 2rem; }
        }
        /* .table-stack: below xl (the sidebar leaves too little room for all columns until then) each row becomes a card (label: value lines, actions underneath), so nothing scrolls sideways.
           Trade-off: display:block drops table semantics in Safari, so the markup carries explicit table/rowgroup/row/cell
           roles; the visible ::before labels get empty alt text ("/ ''") so screen readers hear the column header once, not twice.
           At xl+ the actions cell may wrap instead of pushing buttons off to the right. */
        @media (max-width: 1199.98px) {
            .table-stack thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
            .table-stack, .table-stack tbody, .table-stack tr, .table-stack td { display: block; width: 100%; }
            .table-stack tr { padding: .875rem 1rem; border-bottom: 1px solid var(--bs-border-color); }
            .table-stack tr:last-child { border-bottom: 0; }
            .table-stack > :not(caption) > * > * { border-bottom-width: 0; padding: .25rem 0; }
            .table-stack td { text-align: start !important; white-space: normal !important; min-width: 0 !important; overflow-wrap: anywhere; }
            .table-stack td[data-label] { display: flex; flex-wrap: wrap; gap: .25rem .75rem; align-items: baseline; }
            .table-stack td[data-label]::before { content: attr(data-label); content: attr(data-label) / ""; flex: 0 0 6.5rem; font-weight: 600; font-size: .875rem; color: var(--bs-secondary-color); }
            .table-stack td.stack-actions { padding-top: .625rem; }
            .table-stack td.stack-actions > div { display: flex !important; flex-wrap: wrap; }
            .table-stack td.stack-empty { text-align: center !important; }
        }
        @media (min-width: 1200px) {
            .table-stack td.stack-actions { white-space: normal !important; min-width: 9.5rem; }
            .table-stack td.stack-actions > div { flex-wrap: wrap; justify-content: flex-end; }
        }
    </style>
    @stack('styles')
</head>
<body>
<a href="#main" class="visually-hidden-focusable position-absolute top-0 start-0 m-2 btn btn-primary" style="z-index:2000">Skip to content</a>

<div class="admin-layout">
    <div class="admin-sidebar-col">
        <aside class="offcanvas-lg offcanvas-start admin-sidebar" id="adminSidebar" tabindex="-1" aria-label="Admin menu">
            <div class="offcanvas-header d-lg-none pb-0">
                <span class="fw-bold">Menu</span>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#adminSidebar" aria-label="Close menu"></button>
            </div>
            <div class="offcanvas-body p-0">
                <div class="sidebar-inner">
                    <a href="{{ $adminNav['homeHref'] }}" class="sidebar-brand">
                        <span class="logo" aria-hidden="true" style="color:#fff">
                            <svg viewBox="0 0 36 36" width="36" height="36">
                                <g stroke="currentColor" stroke-opacity="0.5" stroke-width="1.5">
                                    <line x1="10" y1="12" x2="24" y2="10" /><line x1="10" y1="12" x2="14" y2="25" />
                                    <line x1="24" y1="10" x2="26" y2="24" /><line x1="14" y1="25" x2="26" y2="24" />
                                </g>
                                <circle cx="10" cy="12" r="3" fill="currentColor" fill-opacity="0.8" />
                                <circle cx="24" cy="10" r="2.5" fill="currentColor" fill-opacity="0.8" />
                                <circle cx="14" cy="25" r="2.5" fill="currentColor" fill-opacity="0.8" />
                                <circle cx="26" cy="24" r="3.5" fill="currentColor" />
                            </svg>
                        </span>
                        <span class="min-w-0">
                            <span class="name">Club Portal</span>
                            @if ($adminNav['brandSubtitle'])
                                <span class="sub">{{ $adminNav['brandSubtitle'] }}</span>
                            @endif
                        </span>
                    </a>

                    <nav aria-label="Admin" class="admin-nav" data-testid="admin-nav">
                        @foreach ($adminNav['groups'] as $group)
                            <div>
                                <div class="group-title">{{ $group['title'] }}</div>
                                <ul>
                                    @foreach ($group['items'] as $item)
                                        <li>
                                            <a href="{{ $item['href'] }}" data-nav="{{ $item['key'] }}" @if ($item['key'] === $adminNav['active']) aria-current="page" @endif>
                                                {!! \App\Support\Admin\LucideIcons::svg($item['icon']) !!}
                                                <span>{{ $item['label'] }}</span>
                                                @if (! empty($item['badge']))
                                                    <span class="count" aria-label="{{ $item['badge'] }} waiting">{{ $item['badge'] }}</span>
                                                @endif
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </nav>
                </div>
            </div>
        </aside>
    </div>

    <div class="admin-main">
        <header class="admin-topbar">
            <button class="icon-btn d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#adminSidebar" aria-controls="adminSidebar" aria-label="Open menu">
                {!! \App\Support\Admin\LucideIcons::svg('menu', 20) !!}
            </button>
            <div class="flex-grow-1"></div>
            @stack('topbar-actions')

            <div class="dropdown">
                {{-- Same control as the React pages (AdminShell ThemeMenu): sun / moon / monitor, the current choice on the button. --}}
                <button class="icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Theme (System)" id="themeMenuBtn">
                    @foreach (['light' => 'sun', 'dark' => 'moon', 'system' => 'monitor'] as $pref => $icon)
                        <span class="theme-icon" data-theme-icon="{{ $pref }}" @if ($pref !== 'system') hidden @endif>{!! \App\Support\Admin\LucideIcons::svg($icon) !!}</span>
                    @endforeach
                </button>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="themeMenuBtn">
                    <li><h2 class="dropdown-header h6 m-0">Theme</h2></li>
                    @foreach (['light' => ['Light', 'sun'], 'dark' => ['Dark', 'moon'], 'system' => ['System', 'monitor']] as $pref => [$label, $icon])
                        <li>
                            <button type="button" class="dropdown-item d-flex align-items-center gap-2" data-theme-choice="{{ $pref }}" aria-pressed="false">
                                {!! \App\Support\Admin\LucideIcons::svg($icon, 16) !!}{{ $label }}
                                <span class="ms-auto theme-check">{!! \App\Support\Admin\LucideIcons::svg('check', 16) !!}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="dropdown">
                <button class="icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu">
                    <span class="avatar" aria-hidden="true">{{ $adminNav['user']['initials'] }}</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" style="min-width:15rem">
                    <li class="px-3 py-2">
                        <div class="fw-semibold text-truncate">{{ $adminNav['user']['name'] }}</div>
                        <div class="small text-muted">{{ $adminNav['user']['roleLabel'] }}</div>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item d-flex align-items-center gap-2" href="{{ $adminNav['memberHref'] }}">{!! \App\Support\Admin\LucideIcons::svg('user-round', 16) !!}Member view</a></li>
                    <li>
                        <a class="dropdown-item d-flex align-items-center gap-2" href="{{ route('profile.security') }}">
                            {!! \App\Support\Admin\LucideIcons::svg('security', 16) !!}Security &amp; 2FA
                            @if (auth()->user()->hasEnabledTwoFactor())
                                <span class="badge bg-success ms-1">On</span>
                            @endif
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form action="{{ $adminNav['logoutUrl'] }}" method="POST" class="m-0">
                            @csrf
                            <button type="submit" class="dropdown-item d-flex align-items-center gap-2">{!! \App\Support\Admin\LucideIcons::svg('log-out', 16) !!}Log out</button>
                        </form>
                    </li>
                </ul>
            </div>
        </header>

        <main id="main" class="admin-content" tabindex="-1">
            <div class="page-head">
                @hasSection('page-club')
                    <p class="page-club">@yield('page-club')</p>
                @endif
                <h1>@yield('page-title', 'Dashboard')</h1>
            </div>

            @if (session('success'))
                <div class="alert alert-success alert-dismissible fade show d-flex align-items-start gap-2" role="status">
                    <i class="bi bi-check-circle mt-1" aria-hidden="true"></i><div>{{ session('success') }}</div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
                </div>
            @endif
            @if (session('error'))
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-start gap-2" role="alert">
                    <i class="bi bi-exclamation-triangle mt-1" aria-hidden="true"></i><div>{{ session('error') }}</div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>

@unless ($classicBundled)
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
@endunless
<script nonce="{{ $cspNonce }}">
// Theme menu: same choices and storage as the React pages (cp-theme).
(function () {
    const d = document.documentElement;
    const btn = document.getElementById('themeMenuBtn');
    function sync() {
        const pref = d.getAttribute('data-theme-pref') || 'system';
        document.querySelectorAll('[data-theme-choice]').forEach(el => {
            const on = el.dataset.themeChoice === pref;
            el.setAttribute('aria-pressed', on ? 'true' : 'false');
            el.querySelector('.theme-check').style.visibility = on ? 'visible' : 'hidden';
        });
        document.querySelectorAll('[data-theme-icon]').forEach(el => { el.hidden = el.dataset.themeIcon !== pref; });
        btn?.setAttribute('aria-label', 'Theme (' + pref.charAt(0).toUpperCase() + pref.slice(1) + ')');
    }
    document.querySelectorAll('[data-theme-choice]').forEach(el => {
        el.addEventListener('click', () => { window.cpSetTheme(el.dataset.themeChoice); sync(); });
    });
    sync();
})();

// Date fields: write the picked date out ("16 Nov 2026") under the field. The browser's own date
// control shows day/month or month/day depending on its language; this removes any doubt.
document.querySelectorAll('input[type="date"]').forEach(input => {
    const echo = document.createElement('div');
    echo.className = 'date-echo';
    echo.setAttribute('aria-hidden', 'true');
    echo.dataset.dateEcho = input.id || input.name;
    const show = () => {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(input.value);
        echo.textContent = m ? new Date(+m[1], +m[2] - 1, +m[3]).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '\u00a0';
    };
    input.insertAdjacentElement('afterend', echo);
    input.addEventListener('input', show);
    input.addEventListener('change', show);
    show();
});

// A form came back with errors: move focus to the first invalid field (its message is right under it).
(function () {
    const first = document.querySelector('#main :is(input, select, textarea).is-invalid:not([type="hidden"]), #main .is-invalid :is(input, select, textarea):not([type="hidden"]), #main [aria-invalid="true"]');
    if (!first || typeof first.focus !== 'function') return;
    // Inside a closed dialog (e.g. "Record payment…"), the page that opens it handles focus.
    if (first.closest('.modal:not(.show)')) return;
    first.focus({ preventScroll: true });
    first.scrollIntoView({ block: 'center' });
})();

// CSP-safe replacements for inline event handlers.
// Forms with data-confirm prompt before submission.
document.querySelectorAll('form[data-confirm]').forEach(form => {
    form.addEventListener('submit', (e) => {
        if (!window.confirm(form.dataset.confirm)) e.preventDefault();
    });
});
// Inputs / selects that submit their form on change.
document.querySelectorAll('[data-submit-on-change]').forEach(el => {
    el.addEventListener('change', () => el.form?.submit());
});
// Buttons that trigger window.print().
document.querySelectorAll('[data-print]').forEach(btn => {
    btn.addEventListener('click', () => window.print());
});
</script>
@stack('scripts')
</body>
</html>
