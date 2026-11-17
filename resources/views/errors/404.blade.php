{{-- 404: unknown URL or a record that no longer exists. Admins on /admin URLs get the admin look (errors/_admin); everyone else errors/_branded. --}}
@php
    $adminPage = \App\Support\Admin\AdminErrorPage::render(request(), [
        'code' => 404,
        'title' => 'Page not found',
        'body' => 'This admin page does not exist, or the record was removed. Check the link, or go back to the dashboard.',
    ]);
@endphp
@if ($adminPage !== null)
{!! $adminPage !!}
@else
    @include('errors._branded', [
        'code' => 404,
        'title' => 'Page not found',
        'subtitle' => 'The link may be old, or typed slightly wrong.',
        'body' => ['Nothing has changed in your account.'],
    ])
@endif
