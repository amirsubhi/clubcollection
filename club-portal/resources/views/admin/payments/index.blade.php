@extends('layouts.app')
@section('title', $club->name . ' - Payments')
@section('page-title', $club->name . ' — Payments')

@section('content')
{{-- Summary Cards --}}
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="bg-success bg-opacity-10 rounded p-2"><i class="bi bi-cash-coin fs-4 text-success"></i></div>
                <div>
                    <div class="fw-bold fs-5">RM {{ number_format($summary['total_paid'], 2) }}</div>
                    <div class="text-muted small">Total Collected</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="bg-warning bg-opacity-10 rounded p-2"><i class="bi bi-hourglass-split fs-4 text-warning"></i></div>
                <div>
                    <div class="fw-bold fs-5">{{ $summary['total_pending'] }}</div>
                    <div class="text-muted small">Pending</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="bg-danger bg-opacity-10 rounded p-2"><i class="bi bi-exclamation-triangle fs-4 text-danger"></i></div>
                <div>
                    <div class="fw-bold fs-5">{{ $summary['total_overdue'] }}</div>
                    <div class="text-muted small">Overdue</div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Filters & Actions --}}
<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1">Month</label>
                <input type="month" name="month" class="form-control form-control-sm" value="{{ $month }}">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="pending" {{ $status == 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="paid" {{ $status == 'paid' ? 'selected' : '' }}>Paid</option>
                    <option value="overdue" {{ $status == 'overdue' ? 'selected' : '' }}>Overdue</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Job Level</label>
                <select name="job_level" class="form-select form-select-sm">
                    <option value="">All Levels</option>
                    @foreach($jobLevels as $key => $label)
                        <option value="{{ $key }}" {{ $jobLevel == $key ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                <a href="{{ route('admin.clubs.payments.index', $club) }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                <a href="{{ route('admin.clubs.payments.create', $club) }}" class="btn btn-sm btn-success ms-auto">
                    <i class="bi bi-plus-lg me-1"></i>Add
                </a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Member</th>
                    <th>Period</th>
                    <th>Frequency</th>
                    <th>Amount</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($payments as $payment)
                <tr>
                    <td>
                        <div class="fw-semibold">{{ $payment->user->name }}</div>
                        <div class="text-muted small">{{ $payment->user->email }}</div>
                    </td>
                    <td class="small">{{ $payment->period_start->format('d M Y') }} – {{ $payment->period_end->format('d M Y') }}</td>
                    <td><span class="badge bg-light text-dark border">{{ ucfirst($payment->frequency) }}</span></td>
                    <td class="fw-semibold">RM {{ number_format($payment->amount, 2) }}</td>
                    <td class="small {{ $payment->isOverdue() ? 'text-danger fw-semibold' : '' }}">
                        {{ $payment->due_date->format('d M Y') }}
                    </td>
                    <td>
                        @if($payment->status === 'paid')
                            <span class="badge bg-success-subtle text-success border border-success-subtle">Paid</span>
                        @elseif($payment->status === 'overdue')
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Overdue</span>
                        @else
                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle">Pending</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <a href="{{ route('admin.payments.show', $payment) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                        @if($payment->status !== 'paid')
                        <form action="{{ route('admin.payments.mark-paid', $payment) }}" method="POST" class="d-inline">
                            @csrf @method('PATCH')
                            <button class="btn btn-sm btn-success" title="Mark Paid"><i class="bi bi-check-lg"></i></button>
                        </form>
                        @endif
                        <a href="{{ route('admin.payments.edit', $payment) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form action="{{ route('admin.payments.destroy', $payment) }}" method="POST" class="d-inline"
                              onsubmit="return confirm('Delete this payment record?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center text-muted py-4">No payment records found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $payments->links() }}</div>
@endsection
