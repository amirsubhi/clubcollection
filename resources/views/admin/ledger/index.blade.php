@extends('layouts.app')
@section('title', $club->name . ' — Ledger')
@section('page-title', $club->name . ' — Financial Ledger')

@section('content')

{{-- ── Filter bar ─────────────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body py-3">
        <form method="GET" action="{{ route('admin.clubs.ledger', $club) }}" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">From</label>
                <input type="date" name="from" class="form-control form-control-sm"
                       value="{{ $filters['from']->format('Y-m-d') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">To</label>
                <input type="date" name="to" class="form-control form-control-sm"
                       value="{{ $filters['to']->format('Y-m-d') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Opening Balance (RM)</label>
                <input type="number" name="opening_balance" class="form-control form-control-sm"
                       step="0.01" min="0" placeholder="0.00"
                       value="{{ $filters['openingBalance'] > 0 ? number_format($filters['openingBalance'], 2, '.', '') : '' }}">
            </div>
            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                <a href="{{ route('admin.clubs.ledger', $club) }}" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
            <div class="col-auto ms-auto d-flex gap-2">
                {{-- Pass current filters to export links --}}
                @php
                    $exportParams = http_build_query([
                        'from'            => $filters['from']->format('Y-m-d'),
                        'to'              => $filters['to']->format('Y-m-d'),
                        'opening_balance' => $filters['openingBalance'],
                    ]);
                @endphp
                <a href="{{ route('admin.clubs.ledger.export', $club) . '?' . $exportParams }}"
                   class="btn btn-sm btn-success">
                    <i class="bi bi-filetype-csv me-1"></i>Export CSV
                </a>
                <a href="{{ route('admin.clubs.ledger.export-pdf', $club) . '?' . $exportParams }}"
                   class="btn btn-sm btn-danger">
                    <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
                </a>
            </div>
        </form>
    </div>
</div>

{{-- ── Summary cards ──────────────────────────────────────────────────── --}}
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="text-muted small mb-1">Opening Balance</div>
                <div class="fs-5 fw-bold">RM {{ number_format($filters['openingBalance'], 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="text-muted small mb-1">Total In</div>
                <div class="fs-5 fw-bold text-success">+ RM {{ number_format($totalIncome, 2) }}</div>
                <div class="text-muted" style="font-size:0.75rem">{{ count(array_filter($transactions, fn($t) => $t['type'] === 'Income')) }} payments received</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="text-muted small mb-1">Total Out</div>
                <div class="fs-5 fw-bold text-danger">− RM {{ number_format($totalExpenses, 2) }}</div>
                <div class="text-muted" style="font-size:0.75rem">{{ count(array_filter($transactions, fn($t) => $t['type'] === 'Expense')) }} expenses</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="text-muted small mb-1">Closing Balance</div>
                <div class="fs-5 fw-bold {{ $closingBalance >= 0 ? 'text-success' : 'text-danger' }}">
                    RM {{ number_format(abs($closingBalance), 2) }}
                    @if($closingBalance < 0) <small class="text-danger">(deficit)</small> @endif
                </div>
                <div class="text-muted" style="font-size:0.75rem">{{ $filters['from']->format('d M Y') }} — {{ $filters['to']->format('d M Y') }}</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    {{-- ── Monthly breakdown ──────────────────────────────────────────── --}}
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header fw-semibold py-2">Monthly Breakdown</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="ps-3">Month</th>
                            <th scope="col" class="text-end text-success">In</th>
                            <th scope="col" class="text-end text-danger">Out</th>
                            <th scope="col" class="text-end pe-3">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($monthlySummary as $row)
                    <tr>
                        <td class="ps-3">{{ $row['month'] }}</td>
                        <td class="text-end text-success">{{ $row['income'] > 0 ? number_format($row['income'],2) : '—' }}</td>
                        <td class="text-end text-danger">{{ $row['expenses'] > 0 ? number_format($row['expenses'],2) : '—' }}</td>
                        <td class="text-end pe-3 fw-semibold {{ $row['balance'] < 0 ? 'text-danger' : '' }}">
                            {{ number_format($row['balance'], 2) }}
                        </td>
                    </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ── Outstanding payments ───────────────────────────────────────── --}}
    <div class="col-lg-8">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header fw-semibold py-2 d-flex justify-content-between align-items-center">
                Outstanding Payments
                <div class="d-flex gap-2">
                    @if($overduePayments->isNotEmpty())
                    <span class="badge bg-danger">{{ $overduePayments->count() }} overdue</span>
                    @endif
                    @if($pendingPayments->isNotEmpty())
                    <span class="badge bg-warning text-dark">{{ $pendingPayments->count() }} pending</span>
                    @endif
                    @if($overduePayments->isEmpty() && $pendingPayments->isEmpty())
                    <span class="badge bg-success">All clear</span>
                    @endif
                </div>
            </div>
            @if($overduePayments->isNotEmpty() || $pendingPayments->isNotEmpty())
            <div class="table-responsive" style="max-height:280px;overflow-y:auto">
                <table class="table table-sm mb-0 small">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th scope="col" class="ps-3">Member</th>
                            <th scope="col">Period</th>
                            <th scope="col">Due</th>
                            <th scope="col" class="text-end">Amount</th>
                            <th scope="col" class="text-end pe-3">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($overduePayments as $p)
                    <tr class="table-danger-subtle">
                        <td class="ps-3 fw-semibold">{{ $p->user->name }}</td>
                        <td>{{ $p->period_start->format('M Y') }}</td>
                        <td>{{ $p->due_date->format('d M Y') }}</td>
                        <td class="text-end">RM {{ number_format($p->amount, 2) }}</td>
                        <td class="text-end pe-3"><span class="badge bg-danger">Overdue</span></td>
                    </tr>
                    @endforeach
                    @foreach($pendingPayments as $p)
                    <tr>
                        <td class="ps-3">{{ $p->user->name }}</td>
                        <td>{{ $p->period_start->format('M Y') }}</td>
                        <td>{{ $p->due_date->format('d M Y') }}</td>
                        <td class="text-end">RM {{ number_format($p->amount, 2) }}</td>
                        <td class="text-end pe-3"><span class="badge bg-warning text-dark">Pending</span></td>
                    </tr>
                    @endforeach
                    </tbody>
                    <tfoot class="table-light fw-semibold">
                        <tr>
                            <td class="ps-3" colspan="3">Total Outstanding</td>
                            <td class="text-end">RM {{ number_format($overduePayments->sum('amount') + $pendingPayments->sum('amount'), 2) }}</td>
                            <td class="pe-3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            @else
            <div class="card-body text-center text-muted py-4">
                <i class="bi bi-check-circle-fill text-success fs-3 d-block mb-2"></i>
                No outstanding payments.
            </div>
            @endif
        </div>
    </div>
</div>

{{-- ── Transaction ledger ──────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0">
    <div class="card-header fw-semibold py-2 d-flex justify-content-between align-items-center">
        <span>Transactions <span class="text-muted fw-normal small">({{ count($transactions) }})</span></span>
        <span class="text-muted small">{{ $filters['from']->format('d M Y') }} — {{ $filters['to']->format('d M Y') }}</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 small align-middle">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="ps-3" style="width:40px">#</th>
                        <th scope="col" style="width:100px">Date</th>
                        <th scope="col" style="width:90px">Type</th>
                        <th scope="col">Description</th>
                        <th scope="col">Member / Category</th>
                        <th scope="col" style="width:110px">Reference</th>
                        <th scope="col" class="text-end" style="width:120px">Amount In</th>
                        <th scope="col" class="text-end" style="width:120px">Amount Out</th>
                        <th scope="col" class="text-end pe-3" style="width:130px">Balance</th>
                    </tr>
                </thead>
                <tbody>
                @if(count($transactions) === 0)
                <tr>
                    <td colspan="9" class="text-center text-muted py-5">
                        <i class="bi bi-inbox fs-2 d-block mb-2 opacity-25"></i>
                        No transactions in this period.
                    </td>
                </tr>
                @endif

                {{-- Opening balance row --}}
                @if($filters['openingBalance'] != 0 && count($transactions) > 0)
                <tr class="table-light">
                    <td class="ps-3 text-muted">—</td>
                    <td class="text-muted">{{ $filters['from']->format('Y-m-d') }}</td>
                    <td><span class="badge bg-secondary">Opening</span></td>
                    <td class="text-muted fst-italic" colspan="4">Opening balance brought forward</td>
                    <td></td>
                    <td class="text-end pe-3 fw-semibold">RM {{ number_format($filters['openingBalance'], 2) }}</td>
                </tr>
                @endif

                @foreach($transactions as $i => $t)
                <tr>
                    <td class="ps-3 text-muted">{{ $i + 1 }}</td>
                    <td style="white-space:nowrap">{{ \Carbon\Carbon::parse($t['date'])->format('d M Y') }}</td>
                    <td>
                        @if($t['type'] === 'Income')
                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                            <i class="bi bi-arrow-down-circle me-1"></i>Income
                        </span>
                        @else
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                            <i class="bi bi-arrow-up-circle me-1"></i>Expense
                        </span>
                        @endif
                    </td>
                    <td>{{ $t['description'] }}</td>
                    <td class="text-muted">{{ $t['party'] }}</td>
                    <td class="text-muted font-monospace" style="font-size:0.8rem">{{ $t['reference'] ?: '—' }}</td>
                    <td class="text-end text-success fw-semibold">
                        {{ $t['amount_in'] !== null ? 'RM ' . number_format($t['amount_in'], 2) : '' }}
                    </td>
                    <td class="text-end text-danger fw-semibold">
                        {{ $t['amount_out'] !== null ? 'RM ' . number_format($t['amount_out'], 2) : '' }}
                    </td>
                    <td class="text-end pe-3 fw-bold {{ $t['balance'] < 0 ? 'text-danger' : 'text-dark' }}">
                        RM {{ number_format($t['balance'], 2) }}
                    </td>
                </tr>
                @endforeach

                {{-- Closing balance row --}}
                @if(count($transactions) > 0)
                <tr class="table-light fw-bold">
                    <td class="ps-3" colspan="6">Closing Balance</td>
                    <td class="text-end text-success">RM {{ number_format($totalIncome, 2) }}</td>
                    <td class="text-end text-danger">RM {{ number_format($totalExpenses, 2) }}</td>
                    <td class="text-end pe-3 {{ $closingBalance < 0 ? 'text-danger' : 'text-success' }}">
                        RM {{ number_format($closingBalance, 2) }}
                    </td>
                </tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
</div>

@endsection
