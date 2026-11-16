{{-- The body font (self-hosted, resources/css/fonts.css) starts downloading with the HTML, not after the CSS. --}}
@if ($bodyFontUrl = \App\Support\FrontendAssets::bodyFontUrl())
    <link rel="preload" href="{{ $bodyFontUrl }}" as="font" type="font/woff2" crossorigin>
@endif
