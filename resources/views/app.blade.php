<!DOCTYPE html>
{{-- The shared `locale` prop (HandleInertiaRequests): "ms" for members and guests. --}}
<html lang="{{ str_replace('_', '-', $page['props']['locale'] ?? app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Read by the Vite dev client and by app.tsx (Radix scroll-lock styles) so injected <style> tags carry the CSP nonce. --}}
    <meta property="csp-nonce" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    <title inertia>{{ config('app.name', 'Club Portal') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700..800&family=JetBrains+Mono:wght@500&family=Plus+Jakarta+Sans:wght@400..700&display=swap" rel="stylesheet">
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body>
    @inertia('root')
</body>
</html>
