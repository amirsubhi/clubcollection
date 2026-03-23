@extends('layouts.app')
@section('title', $club->name . ' - Financial Dashboard')
@section('page-title', $club->name . ' — Financial Dashboard')

@push('styles')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
@endpush

@section('content')
{{-- Period Selector + Ledger shortcut --}}
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
<form method="GET" class="d-flex gap-2 align-items-center">
    <select name="month" class="form-select form-select-sm" style="width:auto">
        @foreach(range(1,12) as $m)
            <option value="{{ $m }}" {{ $month == $m ? 'selected' : '' }}>{{ date('F', mktime(0,0,0,$m,1)) }}</option>
        @endforeach
    </select>
    <select name="year" class="form-select form-select-sm" style="width:auto">
        @foreach($availableYears as $y)
            <option value="{{ $y }}" {{ $year == $y ? 'selected' : '' }}>{{ $y }}</option>
        @endforeach
    </select>
    <button type="submit" class="btn btn-sm btn-primary">View</button>
</form>
<div class="d-flex gap-2">
    @php $ledgerParams = '?' . http_build_query(['from' => date('Y-01-01'), 'to' => date('Y-m-d')]); @endphp
    <a href="{{ route('admin.clubs.ledger', $club) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-journal-bookmark me-1"></i>Ledger
    </a>
    <a href="{{ route('admin.clubs.ledger.export', $club) . $ledgerParams }}" class="btn btn-sm btn-success">
        <i class="bi bi-filetype-csv me-1"></i>Export CSV
    </a>
    <a href="{{ route('admin.clubs.ledger.export-pdf', $club) . $ledgerParams }}" class="btn btn-sm btn-danger">
        <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
    </a>
</div>
</div>

{{-- Top KPI Cards --}}
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="text-muted small mb-1">Income This Month</div>
                <div class="fs-4 fw-bold text-success">RM {{ number_format($incomeThisMonth, 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="text-muted small mb-1">Expenses This Month</div>
                <div class="fs-4 fw-bold text-danger">RM {{ number_format($expenseThisMonth, 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="text-muted small mb-1">Income This Year</div>
                <div class="fs-4 fw-bold text-primary">RM {{ number_format($incomeThisYear, 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="text-muted small mb-1">Club Balance (All Time)</div>
                <div class="fs-4 fw-bold {{ $balance >= 0 ? 'text-success' : 'text-danger' }}">
                    RM {{ number_format(abs($balance), 2) }} {{ $balance < 0 ? '(deficit)' : '' }}
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    {{-- Income vs Expense Chart --}}
    <div class="col-lg-8">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold">Income vs Expenses (Last 12 Months)</div>
            <div class="card-body">
                <canvas id="incomeExpenseChart" height="100"></canvas>
            </div>
        </div>
    </div>

    {{-- Payment Status --}}
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold">Payment Status (All Time)</div>
            <div class="card-body d-flex flex-column align-items-center justify-content-center">
                <canvas id="statusChart" height="160" style="max-width:200px"></canvas>
                <div class="mt-3 w-100">
                    <div class="d-flex justify-content-between small mb-1">
                        <span><span class="badge bg-success me-1">&nbsp;</span>Paid</span>
                        <strong>{{ $paidCount }}</strong>
                    </div>
                    <div class="d-flex justify-content-between small mb-1">
                        <span><span class="badge bg-warning me-1">&nbsp;</span>Pending</span>
                        <strong>{{ $pendingCount }}</strong>
                    </div>
                    <div class="d-flex justify-content-between small">
                        <span><span class="badge bg-danger me-1">&nbsp;</span>Overdue</span>
                        <strong>{{ $overdueCount }}</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    {{-- Income by Job Level --}}
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold">Income by Job Level — {{ date('F', mktime(0,0,0,$month,1)) }} {{ $year }}</div>
            <div class="card-body">
                @foreach($jobLevels as $key => $label)
                @php $amt = $incomeByJobLevel[$key] ?? 0; $maxAmt = max(array_values($incomeByJobLevel) ?: [1]); @endphp
                <div class="mb-2">
                    <div class="d-flex justify-content-between small mb-1">
                        <span>{{ $label }}</span>
                        <span class="fw-semibold">RM {{ number_format($amt, 2) }}</span>
                    </div>
                    <div class="progress" style="height:8px">
                        <div class="progress-bar bg-success" style="width: {{ $maxAmt > 0 ? ($amt/$maxAmt*100) : 0 }}%"></div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Expenses by Category --}}
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold">Expenses by Category — {{ date('F', mktime(0,0,0,$month,1)) }} {{ $year }}</div>
            <div class="card-body">
                @php $maxExp = $expenseByCategory->max('total') ?: 1; @endphp
                @forelse($expenseByCategory as $cat)
                @php $amt = $cat->total ?? 0; @endphp
                <div class="mb-2">
                    <div class="d-flex justify-content-between small mb-1">
                        <span>{{ $cat->name }}</span>
                        <span class="fw-semibold text-danger">RM {{ number_format($amt, 2) }}</span>
                    </div>
                    <div class="progress" style="height:8px">
                        <div class="progress-bar bg-danger" style="width: {{ $maxExp > 0 ? ($amt/$maxExp*100) : 0 }}%"></div>
                    </div>
                </div>
                @empty
                <p class="text-muted text-center py-3">No expenses this month.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    {{-- Paid vs Unpaid Members --}}
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Paid This Month</span>
                <span class="badge bg-success">{{ $paidMembers->count() }} / {{ $paidMembers->count() + $unpaidMembers->count() }}</span>
            </div>
            <div class="list-group list-group-flush" style="max-height:220px;overflow-y:auto">
                @forelse($paidMembers as $m)
                <div class="list-group-item py-2 d-flex align-items-center gap-2">
                    <i class="bi bi-check-circle-fill text-success"></i>
                    <span>{{ $m->name }}</span>
                    <span class="text-muted small ms-auto">{{ $jobLevels[$m->pivot->job_level] ?? '' }}</span>
                </div>
                @empty
                <div class="list-group-item text-muted text-center py-3">No payments recorded this month.</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Not Yet Paid</span>
                <span class="badge bg-warning text-dark">{{ $unpaidMembers->count() }}</span>
            </div>
            <div class="list-group list-group-flush" style="max-height:220px;overflow-y:auto">
                @forelse($unpaidMembers as $m)
                <div class="list-group-item py-2 d-flex align-items-center gap-2">
                    <i class="bi bi-x-circle text-warning"></i>
                    <span>{{ $m->name }}</span>
                    <span class="text-muted small ms-auto">{{ $jobLevels[$m->pivot->job_level] ?? '' }}</span>
                </div>
                @empty
                <div class="list-group-item text-success text-center py-3"><i class="bi bi-check-all me-1"></i>All members have paid!</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

{{-- Recent Transactions --}}
<div class="row g-4">
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Recent Payments</span>
                <a href="{{ route('admin.payments.index', $club) }}" class="btn btn-sm btn-outline-secondary">View All</a>
            </div>
            <div class="list-group list-group-flush">
                @forelse($recentPayments as $p)
                <div class="list-group-item py-2 d-flex justify-content-between align-items-center">
                    <div>
                        <div class="small fw-semibold">{{ $p->user->name }}</div>
                        <div class="text-muted" style="font-size:0.75rem">{{ $p->paid_date?->format('d M Y') }}</div>
                    </div>
                    <span class="text-success fw-semibold small">+RM {{ number_format($p->amount,2) }}</span>
                </div>
                @empty
                <div class="list-group-item text-muted text-center py-3">No recent payments.</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Recent Expenses</span>
                <a href="{{ route('admin.expenses.index', $club) }}" class="btn btn-sm btn-outline-secondary">View All</a>
            </div>
            <div class="list-group list-group-flush">
                @forelse($recentExpenses as $e)
                <div class="list-group-item py-2 d-flex justify-content-between align-items-center">
                    <div>
                        <div class="small fw-semibold">{{ Str::limit($e->description, 35) }}</div>
                        <div class="text-muted" style="font-size:0.75rem">{{ $e->category->name }} &middot; {{ $e->expense_date->format('d M Y') }}</div>
                    </div>
                    <span class="text-danger fw-semibold small">-RM {{ number_format($e->amount,2) }}</span>
                </div>
                @empty
                <div class="list-group-item text-muted text-center py-3">No recent expenses.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
// Income vs Expense bar chart
const labels = @json(array_keys($monthlyIncome));
const incomeData = @json(array_values($monthlyIncome));
const expenseData = @json(array_values($monthlyExpense));

new Chart(document.getElementById('incomeExpenseChart'), {
    type: 'bar',
    data: {
        labels,
        datasets: [
            { label: 'Income (RM)', data: incomeData, backgroundColor: 'rgba(25,135,84,0.7)', borderRadius: 4 },
            { label: 'Expenses (RM)', data: expenseData, backgroundColor: 'rgba(220,53,69,0.7)', borderRadius: 4 },
        ]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } }
});

// Payment status doughnut
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: ['Paid', 'Pending', 'Overdue'],
        datasets: [{ data: [{{ $paidCount }}, {{ $pendingCount }}, {{ $overdueCount }}], backgroundColor: ['#198754','#ffc107','#dc3545'] }]
    },
    options: { responsive: true, plugins: { legend: { display: false } }, cutout: '65%' }
});
</script>
@endpush
