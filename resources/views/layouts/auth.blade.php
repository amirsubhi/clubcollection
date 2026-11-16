<!DOCTYPE html>
{{-- Sign-in pages (login, two-factor challenge, password reset). Language from AuthPageLocale: BM by default. --}}
@php($lang = ($authLang ?? 'bm') === 'en' ? 'en' : 'bm')
<html lang="{{ $lang === 'en' ? 'en' : 'ms' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    {{-- Same saved theme as the app (cp-theme), applied before first paint; a chosen language is shared with the member app (cp-lang). --}}
    <script nonce="{{ $cspNonce ?? '' }}">
        (function () {
            try {
                var t = localStorage.getItem('cp-theme');
                if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
                @if ($authLangChosen ?? false)
                localStorage.setItem('cp-lang', @json($lang));
                @endif
            } catch (e) {}
        })();
    </script>
    @include('partials.pwa-head')
    <title>@yield('title') · {{ config('app.name') }}</title>
    @if (is_file(public_path('build/manifest.json')) || is_file(public_path('hot')))
        @include('partials.font-preload')
        @vite(['resources/css/app.css'])
    @else
        {{-- public/build is missing (npm run build not uploaded): a bare-bones look so signing in still works. --}}
        <style nonce="{{ $cspNonce ?? '' }}">
            body { margin: 0; font: 16px/1.5 system-ui, sans-serif; background: #F6F5FB; color: #1E1B3A; }
            body > div { max-width: 420px; margin: 0 auto; padding: 16px; }
            header { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; }
            header svg, .sr-only { display: none; }
            nav a { margin-left: 8px; color: #5B4FCF; font-weight: 700; }
            .auth-card { background: #fff; border: 1px solid #DEDCEC; border-radius: 16px; padding: 24px; margin: 32px 0; }
            label, input:not([type=checkbox]), .auth-btn { display: block; width: 100%; box-sizing: border-box; }
            input:not([type=checkbox]) { min-height: 48px; padding: 8px 12px; margin: 6px 0 16px; border: 1px solid #8A87A6; border-radius: 12px; font: inherit; }
            .auth-btn { min-height: 48px; border: 0; border-radius: 999px; background: #5B4FCF; color: #fff; font: inherit; font-weight: 700; }
            .auth-link { color: #5B4FCF; background: none; border: 0; font: inherit; }
            .auth-alert { padding: 12px; border-radius: 12px; margin-bottom: 16px; background: #FDE4E8; color: #99243B; }
            .auth-alert svg { display: none; }
            [hidden] { display: none !important; }
        </style>
    @endif
</head>
<body class="auth-page">
    <div class="safe-x mx-auto flex min-h-[100dvh] w-full max-w-[452px] flex-col">
        <header class="flex flex-wrap items-center justify-between gap-3 gap-y-2 pt-5">
            <div class="flex min-w-0 flex-[1_1_8rem] items-center gap-2.5">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[13px] bg-primary" aria-hidden="true">
                    <svg viewBox="0 0 36 36" class="h-8 w-8">
                        <g stroke="rgb(var(--primary-foreground))" stroke-opacity="0.5" stroke-width="1.5">
                            <line x1="10" y1="12" x2="24" y2="10" /><line x1="10" y1="12" x2="14" y2="25" />
                            <line x1="24" y1="10" x2="26" y2="24" /><line x1="14" y1="25" x2="26" y2="24" />
                        </g>
                        <circle cx="10" cy="12" r="3" fill="rgb(var(--primary-foreground))" fill-opacity="0.8" />
                        <circle cx="24" cy="10" r="2.5" fill="rgb(var(--primary-foreground))" fill-opacity="0.8" />
                        <circle cx="14" cy="25" r="2.5" fill="rgb(var(--primary-foreground))" fill-opacity="0.8" />
                        <circle cx="26" cy="24" r="3.5" fill="rgb(var(--primary-foreground))" />
                    </svg>
                </span>
                <span class="min-w-0 break-words font-display text-lg font-bold leading-tight tracking-tight" data-testid="auth-brand">{{ config('app.name') }}</span>
            </div>
            <nav aria-label="Bahasa / Language" class="inline-flex shrink-0 rounded-full border bg-card p-0.5 text-sm font-bold">
                @foreach (['bm' => 'BM', 'en' => 'EN'] as $code => $label)
                    <a href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}"
                       hreflang="{{ $code === 'en' ? 'en' : 'ms' }}"
                       lang="{{ $code === 'en' ? 'en' : 'ms' }}"
                       @if ($lang === $code) aria-current="true" @endif
                       class="auth-lang {{ $lang === $code ? 'is-active' : '' }}">{{ $label }}<span class="sr-only"> – {{ $code === 'en' ? 'English' : 'Bahasa Melayu' }}</span></a>
                @endforeach
            </nav>
        </header>

        <main class="flex flex-1 flex-col justify-center py-8">
            <div class="auth-card">
                <h1 class="font-display text-[1.75rem] font-extrabold leading-tight tracking-tight">@yield('title')</h1>
                @hasSection('auth-subtitle')
                    <p class="mt-1.5 text-base text-muted-foreground">@yield('auth-subtitle')</p>
                @endif
                <div class="mt-6">
                    @yield('content')
                </div>
            </div>
        </main>

        <footer class="pb-6 text-center text-sm leading-relaxed text-muted-foreground" data-testid="auth-footer">
            {{ __('For club members only') }} · {{ __('Need help? Contact your club administrator') }}
        </footer>
    </div>
</body>
</html>
