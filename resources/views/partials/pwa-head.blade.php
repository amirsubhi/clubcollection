{{-- PWA (Option A, step A4; settings phase 2): installable "Add to Home Screen"; public/sw.js caches static assets only.
     The manifest is the routed /app.webmanifest (portal name, brand colour, icons made from the portal logo);
     public/manifest.webmanifest stays as a static fallback. Every value here falls back to the defaults when the
     settings can't be read (before install, database down). --}}
@php
    $pwaBar = ['light' => \App\Support\Portal\BrandPalette::hex(\App\Support\Portal\BrandPalette::current(), 'light'), 'dark' => \App\Support\Portal\BrandPalette::SURFACES['dark']['background']];
    $pwaIcons = \App\Support\Portal\AppIcons::current();
@endphp
<link rel="manifest" href="/app.webmanifest">
<meta name="theme-color" content="{{ $pwaBar['light'] }}" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="{{ $pwaBar['dark'] }}" media="(prefers-color-scheme: dark)">
{{-- A forced theme (data-theme) wins over the system one for the browser bar too; the setters (and lib/theme.tsx) call cpThemeColor() again. --}}
<script nonce="{{ $cspNonce ?? '' }}">
    (function () {
        var c = @json($pwaBar);
        window.cpThemeColor = function () {
            var t = document.documentElement.getAttribute('data-theme');
            document.querySelectorAll('meta[name="theme-color"]').forEach(function (m) {
                var own = (m.getAttribute('media') || '').indexOf('dark') > -1 ? 'dark' : 'light';
                m.setAttribute('content', c[t === 'light' || t === 'dark' ? t : own]);
            });
        };
        window.cpThemeColor();
    })();
</script>
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="{{ \App\Support\Portal\PortalSettings::name() }}">
@if ($pwaIcons)
<link rel="apple-touch-icon" href="{{ $pwaIcons['apple-touch-icon'] }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ $pwaIcons['favicon-32'] }}">
<link rel="icon" type="image/png" sizes="48x48" href="{{ $pwaIcons['favicon-48'] }}">
@else
<link rel="apple-touch-icon" href="{{ \App\Support\Portal\AppIcons::BUILT_IN_APPLE }}">
@endif
