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
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <caption class="visually-hidden">Members of {{ $club->name }}</caption>
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
                    <td class="fw-semibold" style="min-width:10rem">{{ $member->name }}</td>
                    <td class="text-muted small">{{ $member->email }}</td>
                    <td>{{ $jobLevels[$member->pivot->job_level] ?? $member->pivot->job_level }}</td>
                    <td>
                        <span class="badge {{ $member->pivot->role === 'admin' ? 'bg-warning-subtle text-warning border border-warning-subtle' : 'bg-info-subtle text-info border border-info-subtle' }}">
                            {{ ucfirst($member->pivot->role) }}
                        </span>
                    </td>
                    <td class="text-muted small text-nowrap">{{ \Carbon\Carbon::parse($member->pivot->joined_date)->format('d M Y') }}</td>
                    <td>
                        @if($member->pivot->is_active)
                            <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                        @else
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Inactive</span>
                        @endif
                    </td>
                    <td class="text-end text-nowrap">
                        @if($member->pivot->role === 'admin' && ! auth()->user()->isSuperAdmin())
                        {{-- Club admin rows are managed by the super admin (S1). --}}
                        <span class="small text-muted"><i class="bi bi-shield-lock" aria-hidden="true"></i> Super admin only</span>
                        @else
                        <a href="{{ route('admin.members.edit', ['member' => $member, 'club' => $club->id]) }}" class="btn btn-sm btn-outline-primary" aria-label="Edit {{ $member->name }}">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <form action="{{ route('admin.members.destroy', ['member' => $member, 'club' => $club->id]) }}" method="POST" class="d-inline"
                              data-confirm="Remove this member from the club?">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger" aria-label="Remove {{ $member->name }}"><i class="bi bi-person-dash"></i></button>
                        </form>
                        @endif
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
