{{-- 405: the address exists but not for this kind of request (e.g. a form posted to a page that only opens).
     Outside local, GET/HEAD 405s are mapped to 404 in bootstrap/app.php, so this shows for the other methods.
     Admins on /admin URLs get the admin look (errors/_admin); everyone else errors/_branded. --}}
@php
    $adminPage = \App\Support\Admin\AdminErrorPage::render(request(), [
        'code' => 405,
        'title' => 'That action is not available here',
        'body' => 'This address does not accept that kind of request, so nothing was changed. Go back and use the buttons on the page itself.',
    ]);
@endphp
@if ($adminPage !== null)
{!! $adminPage !!}
@else
    @include('errors._branded', [
        'code' => 405,
        'title' => 'That action is not available here',
        'subtitle' => 'This page was opened in a way it does not support.',
        'body' => ['Nothing has changed in your account.'],
    ])
@endif
