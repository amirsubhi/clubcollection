@extends('layouts.app')
@section('title', $club->name . ' - Members')
@section('page-title', $club->name . ' — Members')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <p class="text-muted mb-0">{{ $members->total() }} member(s) in this club.</p>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.members.import', $club) }}" class="btn btn-outline-primary">
            <i class="bi bi-upload me-1"></i>Import CSV
        </a>
        <a href="{{ route('admin.clubs.members.create', $club) }}" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Add Member
        </a>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Email</th>
                    <th scope="col">Job Level</th>
                    <th scope="col">Club Role</th>
                    <th scope="col">Joined</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($members as $member)
                <tr>
                    <td class="fw-semibold">{{ $member->name }}</td>
                    <td class="text-muted small">{{ $member->email }}</td>
                    <td>{{ $jobLevels[$member->pivot->job_level] ?? $member->pivot->job_level }}</td>
                    <td>
                        <span class="badge {{ $member->pivot->role === 'admin' ? 'bg-warning-subtle text-warning border border-warning-subtle' : 'bg-info-subtle text-info border border-info-subtle' }}">
                            {{ ucfirst($member->pivot->role) }}
                        </span>
                    </td>
                    <td class="text-muted small">{{ \Carbon\Carbon::parse($member->pivot->joined_date)->format('d M Y') }}</td>
                    <td>
                        @if($member->pivot->is_active)
                            <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                        @else
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Inactive</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <a href="{{ route('admin.members.edit', [$club, $member]) }}" class="btn btn-sm btn-outline-primary" aria-label="Edit member">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <form action="{{ route('admin.members.destroy', [$club, $member]) }}" method="POST" class="d-inline"
                              data-confirm="Remove this member from the club?">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger" aria-label="Remove member"><i class="bi bi-person-dash"></i></button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">No members yet. <a href="{{ route('admin.clubs.members.create', $club) }}">Add one</a>.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $members->links() }}</div>
@endsection
