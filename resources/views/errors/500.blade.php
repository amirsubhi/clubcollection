{{-- 500: unexpected server error. Renders without a session or database (errors/_branded). --}}
@include('errors._branded', [
    'code' => 500,
    'title' => 'Something went wrong',
    'subtitle' => 'It was not something you did.',
    'body' => ['Please try again in a few minutes.', 'If you were paying a fee, check your payments before paying again.'],
])
