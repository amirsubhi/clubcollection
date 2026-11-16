@extends('layouts.app')
@section('title', $club->name . ' - Members')
@section('page-title', 'Members')
@section('page-club', $club->name)

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <p class="text-muted mb-0">{{ $members->total() }} member(s) in this club.</p>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('admin.members.import', $club) }}" class="btn btn-outline-primary">
            <i class="bi bi-upload me-1"></i>Import CSV
        </a>
        <a href="{{ route('admin.clubs.members.create', $club) }}" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Add Member
        </a>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive position-relative">
        <table class="table table-hover align-middle mb-0 table-stack" role="table">
            <caption class="visually-hidden">Members of {{ $club->name }}</caption>
            <thead class="table-light" role="rowgroup">
                <tr role="row">
                    <th role="columnheader" scope="col">Name</th>
                    <th role="columnheader" scope="col">Email</th>
                    <th role="columnheader" scope="col">Job Level</th>
                    <th role="columnheader" scope="col">Club Role</th>
                    <th role="columnheader" scope="col">Joined</th>
                    <th role="columnheader" scope="col">Status</th>
                    <th role="columnheader" scope="col" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody role="rowgroup">
                @forelse($members as $member)
                <tr role="row">
                    <td role="cell" class="fw-semibold" style="min-width:10rem">{{ $member->name }}</td>
                    <td role="cell" class="text-muted small" data-label="Email">{{ $member->email }}</td>
                    <td role="cell" data-label="Job level">{{ $jobLevels[$member->pivot->job_level] ?? $member->pivot->job_level }}</td>
                    <td role="cell" data-label="Club role">
                        <span class="badge {{ $member->pivot->role === 'admin' ? 'bg-warning-subtle text-warning border border-warning-subtle' : 'bg-info-subtle text-info border border-info-subtle' }}">
                            {{ ucfirst($member->pivot->role) }}
                        </span>
                    </td>
                    <td role="cell" class="text-muted small text-nowrap" data-label="Joined">{{ \Carbon\Carbon::parse($member->pivot->joined_date)->format('d M Y') }}</td>
                    <td role="cell" data-label="Status">
                        @if($member->pivot->is_active)
                            <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                        @else
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Inactive</span>
                        @endif
                    </td>
                    <td role="cell" class="text-end text-nowrap stack-actions">
                        <div class="d-inline-flex align-items-center gap-1">
                        @if($member->pivot->role === 'admin' && ! auth()->user()->isSuperAdmin())
                        {{-- Club admin rows are managed by the super admin (S1). --}}
                        <span class="small text-muted"><i class="bi bi-shield-lock" aria-hidden="true"></i> Super admin only</span>
                        @else
                        <a href="{{ route('admin.members.edit', ['member' => $member, 'club' => $club->id]) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit<span class="visually-hidden"> {{ $member->name }}</span></a>
                        <form action="{{ route('admin.members.destroy', ['member' => $member, 'club' => $club->id]) }}" method="POST" class="d-inline"
                              data-confirm="Remove this member from the club?">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-person-dash me-1" aria-hidden="true"></i>Remove<span class="visually-hidden"> {{ $member->name }}</span></button>
                        </form>
                        @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr role="row">
                    <td role="cell" colspan="7" class="text-center text-muted py-4 stack-empty">No members yet. <a href="{{ route('admin.clubs.members.create', $club) }}">Add one</a>.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $members->links() }}</div>
@endsection
