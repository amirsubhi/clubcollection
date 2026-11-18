{{--
    Admin-styled error page (403, 404) for a signed-in admin or super admin on an /admin URL: English,
    the admin layout (sidebar, theme), so they stay in the admin portal. Members and guests get errors/_branded.
    Expects: $code (int), $title, $body (one line).
--}}
@extends('layouts.app')
@section('title', $title)
@section('page-title', $title)

@section('content')
<div class="card" data-testid="error-page" data-status="{{ $code }}" data-variant="admin">
    <div class="card-body d-flex flex-column gap-3">
        <p class="mb-0 text-body-secondary">Error {{ $code }}</p>
        <p class="mb-0">{{ $body }}</p>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ \App\Support\Admin\AdminNav::forRequest(request())['homeHref'] }}" class="btn btn-primary">Go to the admin dashboard</a>
            @php
                $referer = (string) request()->headers->get('referer', '');
                $back = \Illuminate\Support\Str::startsWith($referer, request()->getSchemeAndHttpHost().'/') && $referer !== request()->fullUrl() ? $referer : null;
            @endphp
            @if ($back)
                <a href="{{ $back }}" class="btn btn-outline-secondary">Go back</a>
            @endif
        </div>
    </div>
</div>
@endsection
