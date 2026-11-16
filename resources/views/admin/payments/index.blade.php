@extends('layouts.app')
@section('title', $club->name . ' - Payments')
@section('page-title', 'Payments')
@section('page-club', $club->name)

@section('content')
{{-- Summary: the same status words as the table, members and the Ledger (DisplayStatus). --}}
@php $c = $summary['counts']; @endphp
<section class="card shadow-sm mb-4 overflow-hidden" aria-label="Summary">
    <div class="stat-strip">
        <div>
            <span class="stat-label">Collected (all time)</span>
            <span class="stat-value money">RM {{ number_format($summary['total_paid'], 2) }}</span>
        </div>
        <div>
            <span class="stat-label">Due</span>
            <span class="stat-value">{{ $c['due'] }}</span>
        </div>
        <div>
            <span class="stat-label">Overdue</span>
            <span class="stat-value">{{ $c['overdue'] }}</span>
            <span class="stat-note">over {{ $summary['grace_days'] }} {{ $summary['grace_days'] === 1 ? 'day' : 'days' }} late</span>
        </div>
        <div>
            <span class="stat-label">FPX in progress</span>
            <span class="stat-value">{{ $c['processing'] }}</span>
        </div>
        <a href="{{ route('admin.clubs.verifications.index', $club) }}" class="stat-link">
            <span class="stat-label">Awaiting verification <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
            <span class="stat-value">{{ $c['verifying'] }}</span>
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
                    @foreach(['due', 'overdue', 'processing', 'verifying', 'paid'] as $s)
                    <option value="{{ $s }}" {{ $status === $s ? 'selected' : '' }}>{{ \App\Support\Payments\DisplayStatus::adminLabel($s) }}</option>
                    @endforeach
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
    {{-- Phones: each row is a card (table-stack); the status sits under the member's name. --}}
    <div class="table-responsive position-relative" tabindex="0" role="region" aria-label="Payments">
        <table class="table table-hover align-middle mb-0 table-stack" role="table">
            <thead class="table-light" role="rowgroup">
                <tr role="row">
                    <th role="columnheader" scope="col">Member</th>
                    <th role="columnheader" scope="col">Period</th>
                    <th role="columnheader" scope="col">Frequency</th>
                    <th role="columnheader" scope="col">Amount</th>
                    <th role="columnheader" scope="col">Due Date</th>
                    <th role="columnheader" scope="col" class="d-none d-xl-table-cell">Status</th>
                    <th role="columnheader" scope="col" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody role="rowgroup">
                @forelse($payments as $payment)
                @php
                    $display = \App\Support\Payments\DisplayStatus::of($payment);
                    $who = ($payment->user?->name ?? 'Former member').', '.$payment->period_start->format('M Y');
                @endphp
                <tr role="row">
                    <td role="cell">
                        <div class="fw-semibold">{{ $payment->user?->name ?? 'Former member' }}</div>
                        <div class="text-muted small">{{ $payment->user?->email }}</div>
                        <div class="d-xl-none mt-1">@include('admin.payments._status-badge', ['status' => $display])</div>
                    </td>
                    <td role="cell" class="small text-nowrap" data-label="Period">{{ $payment->period_start->format('d M Y') }} – {{ $payment->period_end->format('d M Y') }}</td>
                    <td role="cell" data-label="Frequency"><span class="badge bg-light text-dark border">{{ ucfirst($payment->frequency) }}</span></td>
                    <td role="cell" class="fw-semibold money text-nowrap" data-label="Amount">RM {{ number_format($payment->amount, 2) }}</td>
                    <td role="cell" class="small {{ $display === 'overdue' ? 'fw-semibold' : '' }}" data-label="Due date">
                        {{ $payment->due_date->format('d M Y') }}
                    </td>
                    <td role="cell" class="d-none d-xl-table-cell">@include('admin.payments._status-badge', ['status' => $display])</td>
                    <td role="cell" class="text-end text-nowrap stack-actions">
                        <div class="d-inline-flex align-items-center gap-1">
                            <a href="{{ route('admin.payments.show', $payment) }}" class="btn btn-sm btn-outline-secondary">View<span class="visually-hidden"> payment for {{ $who }}</span></a>
                            @if($payment->status === 'pending_verification')
                            <a href="{{ route('admin.clubs.verifications.index', $club) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-patch-check me-1" aria-hidden="true"></i>Check slip<span class="visually-hidden"> for {{ $who }}</span></a>
                            @elseif($payment->status !== 'paid')
                            @include('admin.payments._record-payment-button', ['payment' => $payment, 'status' => $display, 'class' => 'btn btn-sm btn-outline-primary'])
                            @endif
                            <div class="dropdown">
                                <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"
                                        data-bs-popper-config='{"strategy":"fixed"}'>More<span class="visually-hidden"> actions for {{ $who }}</span></button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="{{ route('admin.payments.edit', $payment) }}"><i class="bi bi-pencil me-2" aria-hidden="true"></i>Edit</a></li>
                                    <li>
                                        <form action="{{ route('admin.payments.destroy', $payment) }}" method="POST" data-confirm="Delete this payment record for {{ $who }}?">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2" aria-hidden="true"></i>Delete</button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </td>
                </tr>
                @empty
                <tr role="row"><td role="cell" colspan="7" class="text-center text-muted py-4 stack-empty">No payment records found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $payments->links() }}</div>
@include('admin.payments._record-payment-modal')
@endsection
