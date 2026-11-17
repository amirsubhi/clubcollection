{{-- PWA (Option A, step A4): installable "Add to Home Screen"; public/sw.js caches static assets only. --}}
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#5B4FCF" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#1E1F23" media="(prefers-color-scheme: dark)">
{{-- A forced theme (data-theme) wins over the system one for the browser bar too; the setters call cpThemeColor() again. Colours mirror lib/theme.tsx. --}}
<script nonce="{{ $cspNonce ?? '' }}">
    (function () {
        var c = { light: '#5B4FCF', dark: '#1E1F23' };
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
<meta name="apple-mobile-web-app-title" content="Club Portal">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
