@extends('layouts.app')
@section('title', 'Clubs')
@section('page-title', 'Clubs')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <p class="text-muted mb-0">Manage all clubs in the portal.</p>
    </div>
    <a href="{{ route('admin.clubs.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>New Club
    </a>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Club</th>
                    <th>Email</th>
                    <th>Members</th>
                    <th>Payment Gateway</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($clubs as $club)
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-3">
                            @if($club->logo)
                                <img src="{{ asset('storage/'.$club->logo) }}" width="40" height="40" class="rounded-circle object-fit-cover border">
                            @else
                                <div class="bg-secondary rounded-circle d-flex align-items-center justify-content-center" style="width:40px;height:40px">
                                    <i class="bi bi-building text-white"></i>
                                </div>
                            @endif
                            <span class="fw-semibold">{{ $club->name }}</span>
                        </div>
                    </td>
                    <td class="text-muted">{{ $club->email ?? '—' }}</td>
                    <td>{{ $club->members()->count() }}</td>
                    <td>
                        @if($club->hasToyyibPayCredentials())
                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                <i class="bi bi-check-circle me-1"></i>Configured
                            </span>
                        @else
                            <a href="{{ route('admin.clubs.edit', $club) }}"
                               class="badge bg-warning-subtle text-warning border border-warning-subtle text-decoration-none">
                                <i class="bi bi-exclamation-circle me-1"></i>Not Set
                            </a>
                        @endif
                    </td>
                    <td>
                        @if($club->is_active)
                            <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                        @else
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Inactive</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <a href="{{ route('admin.clubs.show', $club) }}" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-eye"></i>
                        </a>
                        <a href="{{ route('admin.clubs.edit', $club) }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <form action="{{ route('admin.clubs.destroy', $club) }}" method="POST" class="d-inline"
                              onsubmit="return confirm('Delete this club? This cannot be undone.')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">No clubs yet. <a href="{{ route('admin.clubs.create') }}">Create one</a>.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">{{ $clubs->links() }}</div>
@endsection
