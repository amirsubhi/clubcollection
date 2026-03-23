@extends('layouts.app')
@section('title', 'Administrators')
@section('page-title', 'Administrators')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <p class="text-muted mb-0">Manage portal administrators.</p>
    <a href="{{ route('admin.admins.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>New Administrator
    </a>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Created</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($admins as $admin)
                <tr>
                    <td class="fw-semibold">
                        {{ $admin->name }}
                        @if($admin->id === auth()->id())
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-1">You</span>
                        @endif
                    </td>
                    <td class="text-muted small">{{ $admin->email }}</td>
                    <td>
                        <span class="badge {{ $admin->role === 'super_admin' ? 'bg-danger-subtle text-danger border border-danger-subtle' : 'bg-warning-subtle text-warning border border-warning-subtle' }}">
                            {{ $admin->role === 'super_admin' ? 'Super Admin' : 'Admin' }}
                        </span>
                    </td>
                    <td class="text-muted small">{{ $admin->created_at->format('d M Y') }}</td>
                    <td class="text-end">
                        <a href="{{ route('admin.admins.edit', $admin) }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-pencil"></i>
                        </a>
                        @if($admin->id !== auth()->id())
                        <form action="{{ route('admin.admins.destroy', $admin) }}" method="POST" class="d-inline"
                              onsubmit="return confirm('Remove this administrator?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">No administrators found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $admins->links() }}</div>
@endsection
