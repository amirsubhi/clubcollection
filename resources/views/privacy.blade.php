@extends('layouts.auth')
@section('title', __('Privacy notice'))

{{-- Only classes the scanned sign-in views already use (tailwind.config.ts lists those files, not this one). --}}
@section('content')
<div class="flex flex-col gap-5 text-base leading-relaxed" data-testid="privacy-notice">
    @if ($notice['paragraphs'] === [])
        <p data-testid="privacy-placeholder">{{ __('The club has not published its privacy notice yet. Ask your club administrator.') }}</p>
    @else
        @if ($notice['lang'] !== $lang)
            <p class="text-sm text-muted-foreground" data-testid="privacy-other-language">
                {{ $notice['lang'] === 'en' ? __('This notice is available in English only.') : __('This notice is available in Bahasa Melayu only.') }}
            </p>
        @endif
        <div class="flex flex-col gap-5 break-words" lang="{{ $notice['lang'] === 'en' ? 'en' : 'ms' }}">
            @foreach ($notice['paragraphs'] as $paragraph)
                {{-- Plain text: escaped, its line breaks kept. --}}
                <p>{!! nl2br(e($paragraph)) !!}</p>
            @endforeach
        </div>
    @endif

    @if ($notice['contactName'] || $notice['contactEmail'])
        <div class="rounded-2xl border bg-card px-4 py-3 text-left" data-testid="privacy-contact">
            <p class="font-bold">{{ __('Data protection contact') }}</p>
            @if ($notice['contactName'])
                <p class="break-words">{{ $notice['contactName'] }}</p>
            @endif
            @if ($notice['contactEmail'])
                <p class="break-words"><a href="mailto:{{ $notice['contactEmail'] }}" class="auth-link underline">{{ $notice['contactEmail'] }}</a></p>
            @endif
        </div>
    @endif

    <a href="{{ route('login') }}" class="auth-link">{{ __('Back to sign in') }}</a>
</div>
@endsection
