<!DOCTYPE html>
{{-- The shared `locale` prop (HandleInertiaRequests): "ms" for members and guests. --}}
<html lang="{{ str_replace('_', '-', $page['props']['locale'] ?? app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Read by the Vite dev client and by app.tsx (Radix scroll-lock styles) so injected <style> tags carry the CSP nonce. --}}
    <meta property="csp-nonce" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    @include('partials.pwa-head')
    <title inertia>{{ config('app.name', 'Club Portal') }}</title>
    @include('partials.font-preload')
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @include('partials.brand-style')
    @inertiaHead
</head>
<body>
    @inertia('root')
</body>
</html>
