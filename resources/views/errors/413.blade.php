{{-- PostTooLargeException (S1): the request body was over PHP's post_max_size, so nothing was saved. See errors/_branded. --}}
@include('errors._branded', [
    'code' => 413,
    'title' => 'Upload too large',
    'subtitle' => 'What you sent was too large, so nothing was saved.',
    'body' => ['Each file must be 10 MB or smaller.', 'Go back and try again with smaller files (for example a screenshot of the slip, not a full-size photo).'],
])
