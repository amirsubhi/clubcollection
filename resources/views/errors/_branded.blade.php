{{--
    Branded error page (403, 404, 413, 419, 500, 503), the same look as the sign-in pages (layouts.auth:
    Lavender tokens, nonce'd styles, no inline handlers) and the 429 page.
    Language: ?lang= (the page's BM/EN switch), else the cp_lang cookie the member app and sign-in pages share, else BM.
    It must render with no session and no database (500, 503, or an unknown URL before the web middleware runs),
    so it reads only the request, config and routes.

    Expects: $code (int), $title, $subtitle, $body (lines, translated keys), optional $primary = [label key, url].
--}}
@php
    $pick = fn ($v) => \App\Support\UiLang::normalise($v);
    $authLang = $pick($authLang ?? null) ?? $pick(request()->query('lang')) ?? $pick(request()->cookie(\App\Http\Middleware\AuthPageLocale::COOKIE)) ?? \App\Support\UiLang::DEFAULT;
    app()->setLocale($authLang);

    $safeRoute = function (string $name, string $fallback) {
        try {
            return \Illuminate\Support\Facades\Route::has($name) ? route($name) : url($fallback);
        } catch (\Throwable) {
            return $fallback;
        }
    };
    $home = url('/');
    // "Back": only to a page of this site (same scheme, host and port as this request), never off-site
    // or to a javascript: / data: URL.
    $referer = (string) request()->headers->get('referer', '');
    $back = \Illuminate\Support\Str::startsWith($referer, request()->getSchemeAndHttpHost().'/') && $referer !== request()->fullUrl() ? $referer : null;
    $primary = $primary ?? null;
    if (is_array($primary) && ($primary[1] ?? null) === 'login') {
        $primary[1] = $safeRoute('login', '/login');
    }
@endphp
@extends('layouts.auth')
@section('title', __($title))
@section('auth-subtitle', __($subtitle))

@section('content')
<div class="flex flex-col gap-4" data-testid="error-page" data-status="{{ $code }}">
    @foreach ($body as $line)
        <p class="text-base">{{ __($line) }}</p>
    @endforeach
    <div class="flex flex-col gap-3">
        @if ($primary)
            <a href="{{ $primary[1] }}" class="auth-btn">{{ __($primary[0]) }}</a>
        @endif
        @if ($back && ! $primary)
            <a href="{{ $back }}" class="auth-btn">{{ __('Go back') }}</a>
            <a href="{{ $home }}" class="auth-link text-center">{{ __('Go to the home page') }}</a>
        @else
            <a href="{{ $home }}" class="{{ $primary ? 'auth-link text-center' : 'auth-btn' }}">{{ __('Go to the home page') }}</a>
        @endif
    </div>
    <p class="text-sm text-muted-foreground">{{ __('Error code') }} {{ $code }}</p>
</div>
@endsection
