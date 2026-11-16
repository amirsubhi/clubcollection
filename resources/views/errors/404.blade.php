{{-- 404: unknown URL or a record that no longer exists. See errors/_branded. --}}
@include('errors._branded', [
    'code' => 404,
    'title' => 'Page not found',
    'subtitle' => 'The link may be old, or typed slightly wrong.',
    'body' => ['Nothing has changed in your account.'],
])
