{{-- 403: signed in, but this page is not for this account (another club, admin-only). Admins on /admin URLs get the admin look (errors/_admin); everyone else errors/_branded. --}}
@php
    $adminPage = \App\Support\Admin\AdminErrorPage::render(request(), [
        'code' => 403,
        'title' => 'No access to this page',
        'body' => 'Your admin account cannot open this page (it may belong to another club). If you need access, ask a super admin.',
    ]);
@endphp
@if ($adminPage !== null)
{!! $adminPage !!}
@else
    @include('errors._branded', [
        'code' => 403,
        'title' => 'No access to this page',
        'subtitle' => 'Your account cannot open this page.',
        'body' => ['If you think it should, ask your club administrator.'],
    ])
@endif
