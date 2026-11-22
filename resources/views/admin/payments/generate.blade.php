@extends('layouts.app')
@section('title', $club->name . ' - Generate fees')
@section('page-title', 'Generate fees for a month')
@section('page-club', $club->name)

@section('content')
@use('App\Services\Payments\MonthlyFeeGenerator', 'G')
@php
    $count = $preview['count'];
    $totalSen = $preview['totalSen'];
    $badge = [
        G::BILL => 'bg-success-subtle text-success-emphasis border border-success-subtle',
        G::SKIP_INACTIVE => 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
        G::SKIP_JOINED_LATER => 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
        G::SKIP_ALREADY_BILLED => 'bg-info-subtle text-info-emphasis border border-info-subtle',
        G::SKIP_NO_RATE => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
    ];
@endphp

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <p class="small text-body-secondary mb-3">
            Bills every member a monthly fee at the rate set for their job level in that month
            (<a href="{{ route('admin.clubs.fee-rates.index', $club) }}">Fee rates</a>). Look at the list below, then confirm.
            Nobody is emailed: members see the fee in the portal. Generating the same month again only adds members not billed yet.
        </p>
        <form method="GET" action="{{ route('admin.clubs.payments.generate', $club) }}" class="row g-2 align-items-end">
            <div class="col-sm-4">
                <label for="g-month" class="form-label small fw-semibold mb-1">Month</label>
                <select id="g-month" name="month" class="form-select">
                    @foreach($months as $key => $m)
                    <option value="{{ $key }}" {{ $month->format('Y-m') === $key ? 'selected' : '' }}>{{ $m->format('F Y') }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-4">
                <label for="g-due" class="form-label small fw-semibold mb-1">Due date</label>
                <input id="g-due" type="date" name="due_date" value="{{ $dueInput }}" min="{{ today()->toDateString() }}" max="{{ $latestDue->toDateString() }}"
                       class="form-control @if($dueError) is-invalid @endif" aria-describedby="g-due-help @if($dueError) g-due-error @endif" @if($dueError) aria-invalid="true" @endif>
                @if($dueError)<div id="g-due-error" role="alert" class="invalid-feedback">{{ $dueError }}</div>@endif
            </div>
            <div class="col-sm-4">
                <button type="submit" class="btn btn-outline-primary">Show the list</button>
            </div>
            <div id="g-due-help" class="form-text col-12 mt-1">
                Today or later (a past date would mark the fees overdue at once). Overdue reminders are emailed
                {{ $club->overdueGraceDays() }} {{ $club->overdueGraceDays() === 1 ? 'day' : 'days' }} after it.
            </div>
        </form>
    </div>
</div>

<section class="card shadow-sm mb-3" aria-labelledby="g-summary-h" data-testid="generate-summary">
    <div class="card-body">
        <h2 id="g-summary-h" class="h6 fw-semibold mb-2">{{ $month->format('F Y') }}</h2>
        <p class="mb-2">
            <strong data-testid="generate-count">{{ $count }}</strong> {{ $count === 1 ? 'member' : 'members' }} will be billed,
            total <strong class="money" data-testid="generate-total">RM {{ number_format($totalSen / 100, 2) }}</strong>@if($count > 0), due {{ $due->format('j M Y') }}@endif.
        </p>
        @if($preview['skipped'])
        <ul class="small text-body-secondary mb-0 ps-3" data-testid="generate-skipped">
            @foreach($labels as $code => $label)
                @if(($preview['skipped'][$code] ?? 0) > 0)
                <li>Skipped, {{ strtolower($label) }}: {{ $preview['skipped'][$code] }}</li>
                @endif
            @endforeach
        </ul>
        @endif
    </div>
</section>

<div class="card shadow-sm mb-3">
    <div class="table-responsive" tabindex="0" role="region" aria-label="Members and what happens to each">
        <table class="table table-hover align-middle mb-0 table-stack">
            <thead class="table-light">
                <tr>
                    <th scope="col">Member</th>
                    <th scope="col">Job level</th>
                    <th scope="col">Outcome</th>
                    <th scope="col" class="text-end">Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse($preview['rows'] as $row)
                <tr data-testid="generate-row" data-user="{{ $row['user']->id }}" data-outcome="{{ $row['outcome'] }}">
                    <td>
                        <div class="fw-semibold">{{ $row['user']->name }}</div>
                        <div class="text-muted small">{{ $row['user']->email }}</div>
                    </td>
                    <td data-label="Job level">{{ $row['levelName'] }}</td>
                    <td data-label="Outcome">
                        @if($row['outcome'] === G::BILL)
                            <span class="badge {{ $badge[G::BILL] }}">Will be billed</span>
                            <div class="small text-muted">{{ $row['levelName'] }} rate, RM {{ number_format($row['rateSen'] / 100, 2) }}/month</div>
                        @else
                            <span class="badge {{ $badge[$row['outcome']] ?? $badge[G::SKIP_INACTIVE] }}">Skipped</span>
                            <div class="small text-muted">{{ $labels[$row['outcome']] }}</div>
                        @endif
                    </td>
                    <td class="text-end money text-nowrap" data-label="Amount">{{ $row['outcome'] === G::BILL ? 'RM '.number_format($row['sen'] / 100, 2) : '—' }}</td>
                </tr>
                @empty
                <tr><td colspan="4" class="text-center text-muted py-4">This club has no members yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="d-flex flex-wrap gap-2 align-items-center">
    @if($count > 0 && ! $dueError)
    <form method="POST" action="{{ route('admin.clubs.payments.generate.store', $club) }}" data-confirm="Create {{ $count }} {{ $count === 1 ? 'fee' : 'fees' }} for {{ $month->format('F Y') }} (RM {{ number_format($totalSen / 100, 2) }})?">
        @csrf
        <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
        <input type="hidden" name="due_date" value="{{ $due->toDateString() }}">
        <input type="hidden" name="expected_count" value="{{ $count }}">
        <input type="hidden" name="expected_total_sen" value="{{ $totalSen }}">
        <button type="submit" class="btn btn-success" data-testid="generate-confirm">
            <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Create {{ $count }} {{ $count === 1 ? 'fee' : 'fees' }}, RM {{ number_format($totalSen / 100, 2) }}
        </button>
    </form>
    @elseif($count === 0)
    <p class="text-muted mb-0" data-testid="generate-nothing">Nothing to create for {{ $month->format('F Y') }}.</p>
    @endif
    <a href="{{ route('admin.clubs.payments.index', $club) }}" class="btn btn-outline-secondary">Back to payments</a>
</div>
@endsection
