{{--
    429 Too Many Requests (route throttles: sign-in, password reset, 2FA, install).
    Same look as the sign-in pages (layouts.auth: Lavender tokens, nonce'd styles, no inline handlers).
    Language: the sign-in pages' choice (AuthPageLocale), else the cp_lang cookie, else BM.
--}}
@php
    $authLang = \App\Support\UiLang::normalise($authLang ?? null)
        ?? \App\Support\UiLang::normalise(request()->cookie(\App\Http\Middleware\AuthPageLocale::COOKIE))
        ?? \App\Support\UiLang::DEFAULT;
    app()->setLocale($authLang);
    $retryAfter = isset($exception) && method_exists($exception, 'getHeaders') ? (int) ($exception->getHeaders()['Retry-After'] ?? 0) : 0;
    $wait = max(1, $retryAfter ?: 60);
@endphp
@extends('layouts.auth')
@section('title', __('Too many attempts'))
@section('auth-subtitle', __('For your security we have paused this for a moment.'))

@section('content')
<div role="alert" class="auth-alert auth-alert-danger mb-5" data-testid="throttle-message">
    <p class="min-w-0">{{ __('Please wait about :seconds seconds, then try again.', ['seconds' => $wait]) }}</p>
</div>
<a href="{{ route('login') }}" class="auth-btn">{{ __('Back to sign in') }}</a>
@endsection
