{{-- Portal brand colour (Portal settings, settings phase 2): re-points the brand tokens of tokens.css and the
     Bootstrap triplets of bootstrap-theme.css at the chosen preset. Nothing at all for the default (lavender) or
     when the settings can't be read. The CSS is built only from BrandPalette's constants (never from input) and
     outranks the stylesheets by specificity, so it may sit anywhere in <head>. --}}
@php($brandCss = \App\Support\Portal\BrandPalette::css())
@if ($brandCss !== null)
<style nonce="{{ $cspNonce ?? '' }}" data-brand="{{ \App\Support\Portal\BrandPalette::current() }}">{!! $brandCss !!}</style>
@endif
