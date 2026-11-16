{{-- 419: the form's session (CSRF token) expired, e.g. a page left open overnight. See errors/_branded. --}}
@include('errors._branded', [
    'code' => 419,
    'title' => 'Your session has ended',
    'subtitle' => 'Your session ended. Please sign in again.',
    'body' => ['For your security, a page left open for a long time stops working. What you were doing was not saved; sign in and try again.'],
    'primary' => ['Sign in again', 'login'],
])
