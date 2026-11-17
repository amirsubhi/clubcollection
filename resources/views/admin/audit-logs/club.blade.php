@extends('layouts.app')
@section('title', $club->name . ' — Audit Log')
@section('page-title', 'Audit Log')
@section('page-club', $club->name)

@section('content')
<p class="text-muted mb-4">Activity history for {{ $club->name }}</p>

{{-- Filters --}}
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body py-3">
        <form method="GET" action="{{ route('admin.clubs.audit-logs', $club) }}" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label for="f-action" class="form-label small fw-semibold mb-1">Action</label>
                <select id="f-action" name="action" class="form-select form-select-sm">
                    <option value="">All actions</option>
                    @foreach($actions as $act)
                    <option value="{{ $act }}" {{ request('action') === $act ? 'selected' : '' }}>{{ ucwords(str_replace(['.','_'],' ',$act)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="f-from" class="form-label small fw-semibold mb-1">From</label>
                <input id="f-from" type="date" name="from" class="form-control form-control-sm" value="{{ request('from') }}">
            </div>
            <div class="col-md-2">
                <label for="f-to" class="form-label small fw-semibold mb-1">To</label>
                <input id="f-to" type="date" name="to" class="form-control form-control-sm" value="{{ request('to') }}">
            </div>
            <div class="col-md-3">
                <label for="f-search" class="form-label small fw-semibold mb-1">Search</label>
                <input id="f-search" type="text" name="search" class="form-control form-control-sm" placeholder="User or description…" value="{{ request('search') }}">
            </div>
            <div class="col-md-auto d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
            </div>
            @if(request()->hasAny(['action','from','to','search']))
            <div class="col-auto">
                <a href="{{ route('admin.clubs.audit-logs', $club) }}" class="btn btn-outline-secondary btn-sm">Clear</a>
            </div>
            @endif
        </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="ps-3" style="width:155px">Date / Time</th>
                        <th scope="col" style="width:140px">User</th>
                        <th scope="col" style="width:160px">Action</th>
                        <th scope="col">Description</th>
                        <th scope="col" style="width:140px" class="text-center">Changes</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($logs as $log)
                <tr>
                    <td class="ps-3 text-muted" style="white-space:nowrap">
                        {{ $log->created_at->format('d M Y') }}<br>
                        <span class="small">{{ $log->created_at->format('H:i:s') }}</span>
                    </td>
                    <td>
                        <div class="fw-semibold">{{ $log->user_name }}</div>
                        <span class="badge bg-light text-secondary border">{{ str_replace('_',' ', $log->user_role) }}</span>
                    </td>
                    <td>
                        <span class="badge bg-{{ $log->action_badge_class }}-subtle text-{{ $log->action_badge_class }} border border-{{ $log->action_badge_class }}-subtle">
                            {{ $log->action_label }}
                        </span>
                    </td>
                    {{-- Full text, wrapped; friendly references and dates (FriendlyDescription, display only). --}}
                    <td class="audit-desc" data-testid="audit-desc">{{ $friendly->html($log->description) }}</td>
                    <td class="text-center">
                        @if($log->old_values || $log->new_values)
                        <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap"
                                data-bs-toggle="modal" data-bs-target="#diffModal"
                                data-old="{{ json_encode($log->old_values, JSON_PRETTY_PRINT) }}"
                                data-new="{{ json_encode($log->new_values, JSON_PRETTY_PRINT) }}"
                                data-desc="{{ $friendly->format($log->description) }}">
                            <i class="bi bi-code-square me-1" aria-hidden="true"></i>View changes<span class="visually-hidden"> ({{ $log->action_label }}, {{ $log->created_at->format('d M Y H:i') }})</span>
                        </button>
                        @else
                        <span class="text-muted" aria-label="No changes recorded">—</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="text-center text-muted py-5">
                        <i class="bi bi-journal-text fs-2 d-block mb-2 opacity-25"></i>
                        No audit log entries found.
                    </td>
                </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($logs->hasPages())
        <div class="px-3 py-2 border-top">
            {{ $logs->links() }}
        </div>
        @endif
    </div>
</div>
<div class="modal fade" id="diffModal" tabindex="-1" aria-labelledby="diffTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title h5" id="diffTitle">Change details</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3" id="diffDesc"></p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <h3 class="h6 fw-semibold text-danger">Before</h3>
                        <pre class="bg-light rounded p-3 small" style="max-height:300px;overflow:auto" id="diffOld"></pre>
                    </div>
                    <div class="col-md-6">
                        <h3 class="h6 fw-semibold text-success">After</h3>
                        <pre class="bg-light rounded p-3 small" style="max-height:300px;overflow:auto" id="diffNew"></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection


@push('scripts')
<script nonce="{{ $cspNonce }}">
document.getElementById('diffModal')?.addEventListener('show.bs.modal', function (e) {
    const btn = e.relatedTarget;
    document.getElementById('diffDesc').textContent = btn.dataset.desc;
    document.getElementById('diffOld').textContent  = btn.dataset.old || '(none)';
    document.getElementById('diffNew').textContent  = btn.dataset.new || '(none)';
});
</script>
@endpush
