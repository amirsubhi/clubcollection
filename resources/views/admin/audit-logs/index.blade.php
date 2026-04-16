@extends('layouts.app')
@section('title', 'Audit Log')
@section('page-title', 'Audit Log')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="mb-0 fw-bold">Audit Log</h5>
        <div class="text-muted small">All system activity across every club</div>
    </div>
</div>

{{-- Filters --}}
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body py-3">
        <form method="GET" action="{{ route('admin.audit-logs.index') }}" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Club</label>
                <select name="club_id" class="form-select form-select-sm">
                    <option value="">All clubs</option>
                    @foreach($clubs as $c)
                    <option value="{{ $c->id }}" {{ request('club_id') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Action</label>
                <select name="action" class="form-select form-select-sm">
                    <option value="">All actions</option>
                    @foreach($actions as $act)
                    <option value="{{ $act }}" {{ request('action') === $act ? 'selected' : '' }}>{{ ucwords(str_replace(['.','_'],' ',$act)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="{{ request('from') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="{{ request('to') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Search</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="User or description…" value="{{ request('search') }}">
            </div>
            <div class="col-md-1 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
            </div>
            @if(request()->hasAny(['club_id','action','from','to','search']))
            <div class="col-auto">
                <a href="{{ route('admin.audit-logs.index') }}" class="btn btn-outline-secondary btn-sm">Clear</a>
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
                        <th scope="col" style="width:130px">User</th>
                        <th scope="col" style="width:120px">Club</th>
                        <th scope="col" style="width:160px">Action</th>
                        <th scope="col">Description</th>
                        <th scope="col" style="width:80px">Changes</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($logs as $log)
                <tr>
                    <td class="ps-3 text-muted" style="white-space:nowrap">
                        {{ $log->created_at->format('d M Y') }}<br>
                        <span style="font-size:0.75rem">{{ $log->created_at->format('H:i:s') }}</span>
                    </td>
                    <td>
                        <div class="fw-semibold">{{ $log->user_name }}</div>
                        <span class="badge bg-light text-secondary border" style="font-size:0.68rem">{{ str_replace('_',' ', $log->user_role) }}</span>
                    </td>
                    <td class="text-muted">{{ $log->club?->name ?? '—' }}</td>
                    <td>
                        <span class="badge bg-{{ $log->action_badge_class }}-subtle text-{{ $log->action_badge_class }} border border-{{ $log->action_badge_class }}-subtle">
                            {{ $log->action_label }}
                        </span>
                    </td>
                    <td style="max-width:300px">
                        <span class="text-truncate d-inline-block" style="max-width:295px" title="{{ $log->description }}">
                            {{ $log->description }}
                        </span>
                    </td>
                    <td class="text-center">
                        @if($log->old_values || $log->new_values)
                        <button class="btn btn-sm btn-outline-secondary py-0 px-2"
                                data-bs-toggle="modal" data-bs-target="#diffModal"
                                data-old="{{ json_encode($log->old_values, JSON_PRETTY_PRINT) }}"
                                data-new="{{ json_encode($log->new_values, JSON_PRETTY_PRINT) }}"
                                data-desc="{{ $log->description }}">
                            <i class="bi bi-code-square"></i>
                        </button>
                        @else
                        <span class="text-muted">—</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-5">
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
@endsection

{{-- Diff modal --}}
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

<div class="modal fade" id="diffModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-semibold">Change Details</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3" id="diffDesc"></p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold text-danger">Before</label>
                        <pre class="bg-light rounded p-3 small" style="max-height:300px;overflow:auto" id="diffOld"></pre>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold text-success">After</label>
                        <pre class="bg-light rounded p-3 small" style="max-height:300px;overflow:auto" id="diffNew"></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
