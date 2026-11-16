{{-- 403: signed in, but this page is not for this account (another club, admin-only). See errors/_branded. --}}
@include('errors._branded', [
    'code' => 403,
    'title' => 'No access to this page',
    'subtitle' => 'Your account cannot open this page.',
    'body' => ['If you think it should, ask your club administrator.'],
])
