@extends('layouts.app')
@section('title', $club->name . ' - Job Levels')
@section('page-title', 'Job Levels')
@section('page-club', $club->name)

@section('content')
<p class="text-muted">
    Job levels (<span lang="ms">tahap jawatan</span>) are this club's own grades. Each member is on one level and
    <a href="{{ route('admin.clubs.fee-rates.index', $club) }}">fee rates</a> are set per level, so set the levels up first.
</p>

@if($errors->any())
<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="row g-4">
    <div class="col-md-5">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Add Job Level</div>
            <div class="card-body p-4">
                <form action="{{ route('admin.clubs.job-levels.store', $club) }}" method="POST">
                    @csrf
                    <div class="mb-3">
                        <label for="f-name" class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                        <input id="f-name" aria-describedby="f-name-help" type="text" name="name" maxlength="100" required
                               class="form-control" value="{{ old('name') }}" placeholder="e.g. Pegawai">
                        <div id="f-name-help" class="form-text">Shown to admins and members. You can rename it later.</div>
                    </div>
                    <div class="mb-3">
                        <label for="f-sort" class="form-label fw-semibold">Order <span class="fw-normal text-body-secondary">(optional)</span></label>
                        <input id="f-sort" aria-describedby="f-sort-help" type="number" name="sort_order" min="0" max="9999" class="form-control" value="{{ old('sort_order') }}">
                        <div id="f-sort-help" class="form-text">Lower numbers come first in lists. Left empty, the level goes last.</div>
                    </div>
                    <button type="submit" class="btn btn-primary">Add Level</button>
                </form>
                <p class="form-text mt-3 mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Each level gets a short code made from its first name (for the CSV import). The code never changes, even when you rename the level.</p>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Levels ({{ $levels->count() }})</div>
            <div class="list-group list-group-flush">
                @forelse($levels as $level)
                @php($inUse = ($memberCounts[$level->code] ?? 0) + ($rateCounts[$level->code] ?? 0) > 0)
                <div class="list-group-item py-2 {{ $level->is_active ? '' : 'bg-body-tertiary' }}">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <form action="{{ route('admin.job-levels.update', $level) }}" method="POST" class="d-flex flex-wrap align-items-center gap-2 flex-grow-1">
                            @csrf @method('PUT')
                            <input type="number" name="sort_order" value="{{ $level->sort_order }}" min="0" max="9999" class="form-control form-control-sm" style="width:5rem" aria-label="Order of job level {{ $level->name }}">
                            <input type="text" name="name" value="{{ $level->name }}" maxlength="100" required class="form-control form-control-sm" style="flex:1 1 9rem" aria-label="Name of job level {{ $level->name }}">
                            <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Save</button>
                        </form>
                        <form action="{{ route('admin.job-levels.active', $level) }}" method="POST">
                            @csrf @method('PATCH')
                            <input type="hidden" name="is_active" value="{{ $level->is_active ? 0 : 1 }}">
                            <button type="submit" class="btn btn-sm {{ $level->is_active ? 'btn-outline-secondary' : 'btn-outline-success' }} text-nowrap">{{ $level->is_active ? 'Deactivate' : 'Reactivate' }}<span class="visually-hidden"> {{ $level->name }}</span></button>
                        </form>
                        @unless($inUse)
                        <form action="{{ route('admin.job-levels.destroy', $level) }}" method="POST" data-confirm="Delete this job level?">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger" aria-label="Delete job level {{ $level->name }}"><i class="bi bi-trash" aria-hidden="true"></i></button>
                        </form>
                        @endunless
                    </div>
                    <div class="small text-muted mt-1">
                        Code <code>{{ $level->code }}</code>
                        &middot; {{ $memberCounts[$level->code] ?? 0 }} member(s) &middot; {{ $rateCounts[$level->code] ?? 0 }} fee rate(s)
                        @unless($level->is_active)
                            &middot; <span class="badge bg-secondary">Inactive</span> not offered for new members or fee rates
                        @endunless
                    </div>
                </div>
                @empty
                <div class="list-group-item text-muted text-center py-4">No job levels yet. Add one to start adding members and fee rates.</div>
                @endforelse
            </div>
        </div>
        <p class="form-text mt-2">A level in use by a member or a fee rate can't be deleted; deactivate it instead.</p>
    </div>
</div>
@endsection
