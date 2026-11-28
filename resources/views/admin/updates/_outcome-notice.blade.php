{{-- J7: shown to each super admin until they dismiss it, or a later update finishes. Never for club admins. --}}
@php $updateNotice = \App\Support\Updates\UpdateOutcomes::noticeFor(auth()->user()); @endphp
@if ($updateNotice)
    <div class="alert alert-warning d-flex flex-wrap align-items-center gap-3 mb-4" role="status" id="update-outcome-notice">
        <span class="flex-grow-1">{{ $updateNotice['notice'] }}</span>
        <a class="btn btn-sm btn-outline-dark" href="{{ route('admin.updates.index') }}">Try again</a>
        <form method="POST" action="{{ route('admin.updates.dismiss') }}" class="m-0">
            @csrf
            <input type="hidden" name="file" value="{{ $updateNotice['file'] }}">
            <button type="submit" class="btn btn-sm btn-outline-secondary">Dismiss</button>
        </form>
    </div>
@endif
