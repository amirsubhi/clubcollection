<!DOCTYPE html>
{{-- Sign-in pages (login, two-factor challenge, password reset). Language from AuthPageLocale: BM by default. --}}
@php($lang = \App\Support\UiLang::normalise($authLang ?? null) ?? \App\Support\Portal\PortalSettings::defaultLocale())
<html lang="{{ $lang }}">
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
                localStorage.setItem('cp-lang', @json(\App\Support\UiLang::stored($lang)));
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
        @php($brandHex = \App\Support\Portal\BrandPalette::hex(\App\Support\Portal\BrandPalette::current(), 'light'))
        <style nonce="{{ $cspNonce ?? '' }}">
            body { margin: 0; font: 16px/1.5 system-ui, sans-serif; background: #F6F5FB; color: #1E1B3A; }
            body > div { max-width: 420px; margin: 0 auto; padding: 16px; }
            header { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; }
            header svg, .sr-only { display: none; }
            header img { width: 40px; height: 40px; object-fit: contain; vertical-align: middle; margin-right: 8px; }
            nav a { margin-left: 8px; color: {{ $brandHex }}; font-weight: 700; }
            .auth-card { background: #fff; border: 1px solid #DEDCEC; border-radius: 16px; padding: 24px; margin: 32px 0; }
            label, input:not([type=checkbox]), .auth-btn { display: block; width: 100%; box-sizing: border-box; }
            input:not([type=checkbox]) { min-height: 48px; padding: 8px 12px; margin: 6px 0 16px; border: 1px solid #8A87A6; border-radius: 12px; font: inherit; }
            .auth-btn { min-height: 48px; border: 0; border-radius: 999px; background: {{ $brandHex }}; color: #fff; font: inherit; font-weight: 700; }
            .auth-link { color: {{ $brandHex }}; background: none; border: 0; font: inherit; }
            summary.auth-link { display: list-item; }
            .auth-alert { padding: 12px; border-radius: 12px; margin-bottom: 16px; background: #FDE4E8; color: #99243B; }
            .auth-alert svg { display: none; }
            [hidden] { display: none !important; }
        </style>
    @endif
    @include('partials.brand-style')
</head>
<body class="auth-page">
    <div class="safe-x mx-auto flex min-h-[100dvh] w-full max-w-[452px] flex-col">
        <header class="flex flex-wrap items-center justify-between gap-3 gap-y-2 pt-5">
            {{-- With exactly one active club its name and logo head the page (AppServiceProvider: authBrand), the
                 portal name under it; otherwise the portal's own name and logo (Portal settings). The logo sits on a
                 white tile so a dark or transparent logo stays visible in dark mode. --}}
            @php($brand = $authBrand ?? ['kind' => 'portal', 'name' => config('app.name'), 'logoUrl' => null, 'portal' => config('app.name')])
            @php($brandLogo = $brand['logoUrl'] ?: ($brand['kind'] === 'club' ? \App\Support\Portal\PortalSettings::logoUrl() : null))
            <div class="flex min-w-0 flex-[1_1_8rem] items-center gap-2.5" data-brand-kind="{{ $brand['kind'] }}">
                @if ($brandLogo)
                <span class="auth-logo flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-[13px] border bg-white p-0.5">
                    <img src="{{ $brandLogo }}" alt="{{ $brand['logoUrl'] ? $brand['name'] : $brand['portal'] }}" class="max-h-full max-w-full object-contain" width="40" height="40" data-testid="auth-logo">
                </span>
                @else
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
                @endif
                <span class="min-w-0">
                    <span class="block break-words font-display text-lg font-bold leading-tight tracking-tight" data-testid="auth-brand">{{ $brand['name'] }}</span>
                    @if ($brand['kind'] === 'club' && $brand['portal'] !== $brand['name'])
                        <span class="block break-words text-sm leading-snug text-muted-foreground" data-testid="auth-brand-portal">{{ $brand['portal'] }}</span>
                    @endif
                </span>
            </div>
            <nav aria-label="Bahasa / Language" class="inline-flex shrink-0 rounded-full border bg-card p-0.5 text-sm font-bold">
                @foreach (\App\Support\UiLang::LABELS as $code => $label)
                    <a href="{{ request()->fullUrlWithQuery(['lang' => \App\Support\UiLang::stored($code)]) }}"
                       hreflang="{{ $code }}"
                       lang="{{ $code }}"
                       @if ($lang === $code) aria-current="true" @endif
                       class="auth-lang {{ $lang === $code ? 'is-active' : '' }}">{{ $label }}<span class="sr-only"> – {{ \App\Support\UiLang::NAMES[$code] }}</span></a>
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
            @yield('after-card')
        </main>

        <footer class="pb-6 text-center text-sm leading-relaxed text-muted-foreground" data-testid="auth-footer">
            <p>{{ __('For club members only') }}</p>
            {{-- Help without JS (CSP-safe): the link opens the section below. A guest's club is unknown, so a contact
                 is shown only when exactly one active club exists (AppServiceProvider: authHelpClub). --}}
            <details id="auth-help" class="auth-help" data-testid="auth-help">
                <summary class="auth-link cursor-pointer justify-center underline">{{ __('Need help? Contact your club administrator') }}</summary>
                <div class="mt-2 rounded-2xl border bg-card px-4 py-3 text-left text-base text-card-foreground">
                    {{-- Honest copy: only promise a channel that is actually linked below. --}}
                    <p data-testid="auth-help-copy">
                        @if (($authHelpClub ?? null) && $authHelpClub['whatsapp'])
                            {{ __('Your club treasurer can help: send them a WhatsApp message.') }}
                        @elseif ($authHelpClub ?? null)
                            {{ __('Your club treasurer can help: send them an email.') }}
                        @else
                            {{ __('Ask your club treasurer directly.') }}
                        @endif
                    </p>
                    @if (Route::has('password.request') && ! request()->routeIs('password.request'))
                        <p class="mt-1"><a href="{{ route('password.request') }}" class="auth-link" data-testid="auth-help-reset">{{ __('Forgot password? Reset it yourself') }}</a></p>
                    @endif
                    @if ($authHelpClub ?? null)
                        <p class="mt-1">
                            @if ($authHelpClub['whatsapp'])
                                <a href="https://wa.me/{{ $authHelpClub['whatsapp'] }}" target="_blank" rel="noopener noreferrer" class="auth-link" data-testid="auth-help-whatsapp">{{ __('WhatsApp :club', ['club' => $authHelpClub['name']]) }}</a>
                            @else
                                <a href="mailto:{{ $authHelpClub['email'] }}" class="auth-link" data-testid="auth-help-email">{{ __('Email :club', ['club' => $authHelpClub['name']]) }}</a>
                            @endif
                        </p>
                    @endif
                </div>
            </details>
            @if (Route::has('privacy') && ! request()->routeIs('privacy'))
                <p class="mt-2"><a href="{{ route('privacy') }}" class="auth-link underline" data-testid="auth-privacy-link">{{ __('Privacy notice') }}</a></p>
            @endif
        </footer>
    </div>
</body>
</html>
