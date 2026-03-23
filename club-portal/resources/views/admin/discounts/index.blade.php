@extends('layouts.app')
@section('title', 'Discounts')
@section('page-title', $club->name . ' — Discounts')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <p class="text-muted mb-0">Manage event or special discounts for this club.</p>
    <a href="{{ route('admin.clubs.discounts.create', $club) }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>New Discount
    </a>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Value</th>
                    <th>Valid From</th>
                    <th>Valid To</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($discounts as $discount)
                <tr>
                    <td class="fw-semibold">{{ $discount->name }}</td>
                    <td><span class="badge bg-light text-dark border">{{ ucfirst($discount->type) }}</span></td>
                    <td>
                        @if($discount->type === 'fixed')
                            RM {{ number_format($discount->value, 2) }}
                        @else
                            {{ $discount->value }}%
                        @endif
                    </td>
                    <td class="small">{{ $discount->valid_from->format('d M Y') }}</td>
                    <td class="small">{{ $discount->valid_to ? $discount->valid_to->format('d M Y') : '—' }}</td>
                    <td>
                        @if($discount->is_active)
                            <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                        @else
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Inactive</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <a href="{{ route('admin.discounts.edit', $discount) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form action="{{ route('admin.discounts.destroy', $discount) }}" method="POST" class="d-inline"
                              onsubmit="return confirm('Delete this discount?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center text-muted py-4">No discounts yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $discounts->links() }}</div>
@endsection
