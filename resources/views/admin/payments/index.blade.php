@extends('layouts.app')
@section('title', $club->name . ' - Payments')
@section('page-title', 'Payments')
@section('page-club', $club->name)

@section('content')
{{-- Summary --}}
<section class="card shadow-sm mb-4 overflow-hidden" aria-label="Summary">
    <div class="stat-strip">
        <div>
            <span class="stat-label">Collected (all time)</span>
            <span class="stat-value money">RM {{ number_format($summary['total_paid'], 2) }}</span>
        </div>
        <div>
            <span class="stat-label">Pending</span>
            <span class="stat-value">{{ $summary['total_pending'] }}</span>
        </div>
        <div>
            <span class="stat-label">Overdue</span>
            <span class="stat-value {{ $summary['total_overdue'] ? 'text-danger' : '' }}">{{ $summary['total_overdue'] }}</span>
        </div>
        <a href="{{ route('admin.clubs.verifications.index', $club) }}" class="stat-link">
            <span class="stat-label">Awaiting verification <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
            <span class="stat-value">{{ $summary['total_verifying'] }}</span>
        </a>
    </div>
</section>

{{-- Filters & Actions --}}
<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label for="f-month" class="form-label small mb-1">Month</label>
                <input id="f-month" type="month" name="month" class="form-control form-control-sm" value="{{ $month }}">
            </div>
            <div class="col-md-3">
                <label for="f-status" class="form-label small mb-1">Status</label>
                <select id="f-status" name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="pending" {{ $status == 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="paid" {{ $status == 'paid' ? 'selected' : '' }}>Paid</option>
                    <option value="overdue" {{ $status == 'overdue' ? 'selected' : '' }}>Overdue</option>
                    <option value="pending_verification" {{ $status == 'pending_verification' ? 'selected' : '' }}>Awaiting verification</option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="f-job-level" class="form-label small mb-1">Job Level</label>
                <select id="f-job-level" name="job_level" class="form-select form-select-sm">
                    <option value="">All Levels</option>
                    @foreach($jobLevels as $key => $label)
                        <option value="{{ $key }}" {{ $jobLevel == $key ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 d-flex flex-wrap gap-2">
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
                    <th scope="col">Member</th>
                    <th scope="col">Period</th>
                    <th scope="col">Frequency</th>
                    <th scope="col">Amount</th>
                    <th scope="col">Due Date</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($payments as $payment)
                <tr>
                    <td>
                        <div class="fw-semibold">{{ $payment->user?->name ?? 'Former member' }}</div>
                        <div class="text-muted small">{{ $payment->user?->email }}</div>
                    </td>
                    <td class="small text-nowrap">{{ $payment->period_start->format('d M Y') }} – {{ $payment->period_end->format('d M Y') }}</td>
                    <td><span class="badge bg-light text-dark border">{{ ucfirst($payment->frequency) }}</span></td>
                    <td class="fw-semibold money">RM {{ number_format($payment->amount, 2) }}</td>
                    <td class="small {{ $payment->isOverdue() ? 'text-danger fw-semibold' : '' }}">
                        {{ $payment->due_date->format('d M Y') }}
                    </td>
                    <td>
                        @if($payment->status === 'paid')
                            <span class="badge bg-success-subtle text-success border border-success-subtle">Paid</span>
                        @elseif($payment->status === 'overdue')
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Overdue</span>
                        @elseif($payment->status === 'pending_verification')
                            <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">Awaiting verification</span>
                        @else
                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle">Pending</span>
                        @endif
                    </td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('admin.payments.show', $payment) }}" class="btn btn-sm btn-outline-secondary" aria-label="View payment"><i class="bi bi-eye"></i></a>
                        @if($payment->status === 'pending_verification')
                        <a href="{{ route('admin.clubs.verifications.index', $club) }}" class="btn btn-sm btn-info" title="Check the transfer slip" aria-label="Check the transfer slip"><i class="bi bi-patch-check"></i></a>
                        @elseif($payment->status !== 'paid')
                        <form action="{{ route('admin.payments.mark-paid', $payment) }}" method="POST" class="d-inline">
                            @csrf @method('PATCH')
                            <button class="btn btn-sm btn-success" title="Mark Paid" aria-label="Mark payment as paid"><i class="bi bi-check-lg"></i></button>
                        </form>
                        @endif
                        <a href="{{ route('admin.payments.edit', $payment) }}" class="btn btn-sm btn-outline-primary" aria-label="Edit payment"><i class="bi bi-pencil"></i></a>
                        <form action="{{ route('admin.payments.destroy', $payment) }}" method="POST" class="d-inline"
                              data-confirm="Delete this payment record?">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger" aria-label="Delete payment"><i class="bi bi-trash"></i></button>
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
