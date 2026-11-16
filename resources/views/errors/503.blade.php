{{-- 503: maintenance mode (php artisan down) or a service briefly unavailable. Renders without a session or database (errors/_branded). --}}
@include('errors._branded', [
    'code' => 503,
    'title' => 'Down for a short update',
    'subtitle' => 'Club Portal is being updated.',
    'body' => ['Please try again in a few minutes. Your payments and receipts are safe.'],
])
