@extends('layouts.app')
@section('title', $club->name . ' - Financial Dashboard')
@section('page-title', 'Financial Dashboard')
@section('page-club', $club->name)

@section('content')
{{-- Period Selector + Ledger shortcut --}}
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
<form method="GET" class="d-flex flex-wrap gap-2 align-items-end" aria-label="Period">
    <div>
    <label for="dash-month" class="form-label small mb-1">Month</label>
    <select name="month" id="dash-month" class="form-select form-select-sm" style="width:auto">
        @foreach(range(1,12) as $m)
            <option value="{{ $m }}" {{ $month == $m ? 'selected' : '' }}>{{ date('F', mktime(0,0,0,$m,1)) }}</option>
        @endforeach
    </select>
    </div>
    <div>
    <label for="dash-year" class="form-label small mb-1">Year</label>
    <select name="year" id="dash-year" class="form-select form-select-sm" style="width:auto">
        @foreach($availableYears as $y)
            <option value="{{ $y }}" {{ $year == $y ? 'selected' : '' }}>{{ $y }}</option>
        @endforeach
    </select>
    </div>
    <button type="submit" class="btn btn-sm btn-primary">View</button>
</form>
<div class="d-flex gap-2">
    @php $ledgerParams = '?' . http_build_query(['from' => date('Y-01-01'), 'to' => date('Y-m-d')]); @endphp
    <a href="{{ route('admin.clubs.ledger', $club) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-journal-bookmark me-1" aria-hidden="true"></i>Ledger
    </a>
    <a href="{{ route('admin.clubs.ledger.export', $club) . $ledgerParams }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>Export CSV
    </a>
    <a href="{{ route('admin.clubs.ledger.export-pdf', $club) . $ledgerParams }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Export PDF
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
                <div class="fs-4 fw-bold">RM {{ number_format($expenseThisMonth, 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="text-muted small mb-1">Income This Year</div>
                <div class="fs-4 fw-bold">RM {{ number_format($incomeThisYear, 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="text-muted small mb-1">Club Balance (All Time)</div>
                <div class="fs-4 fw-bold {{ $balance < 0 ? 'text-danger' : '' }}">
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
                <div style="position:relative;height:260px">
                    <canvas id="incomeExpenseChart" role="img" aria-label="Bar chart of income and expenses per month for the last 12 months. The same figures are in the table below the chart."></canvas>
                </div>
                <details class="mt-3">
                    <summary class="small fw-semibold">Show as table</summary>
                    <div class="table-responsive mt-2">
                        <table class="table table-sm small mb-0">
                            <caption class="visually-hidden">Income and expenses per month</caption>
                            <thead><tr><th scope="col">Month</th><th scope="col" class="text-end">Income</th><th scope="col" class="text-end">Expenses</th></tr></thead>
                            <tbody>
                                @foreach($monthlyIncome as $label => $amt)
                                <tr><th scope="row" class="fw-normal">{{ $label }}</th><td class="text-end money">RM {{ number_format($amt, 2) }}</td><td class="text-end money">RM {{ number_format($monthlyExpense[$label] ?? 0, 2) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>
        </div>
    </div>

    {{-- Payment Status --}}
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold">Payment Status (All Time)</div>
            <div class="card-body">
                @include('admin.partials.bar-list', [
                    'caption' => 'Fee records by status',
                    'testid' => 'status-bars',
                    'rows' => collect([['Paid', $paidCount], ['Due', $pendingCount], ['Overdue', $overdueCount], ['FPX in progress', $processingCount], ['Awaiting verification', $verifyingCount]])
                        ->map(fn ($r) => ['label' => $r[0], 'value' => $r[1], 'display' => number_format($r[1])])->all(),
                ])
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
                @include('admin.partials.bar-list', [
                    'caption' => 'Income by job level',
                    'rows' => collect($jobLevels)->map(fn ($label, $key) => ['label' => $label, 'value' => (float) ($incomeByJobLevel[$key] ?? 0), 'display' => 'RM '.number_format($incomeByJobLevel[$key] ?? 0, 2)])->values()->all(),
                ])
            </div>
        </div>
    </div>

    {{-- Expenses by Category --}}
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold">Expenses by Category — {{ date('F', mktime(0,0,0,$month,1)) }} {{ $year }}</div>
            <div class="card-body">
                @if ($expenseByCategory->isEmpty())
                <p class="text-muted text-center py-3 mb-0">No expenses this month.</p>
                @else
                <div class="bar-list-spent">
                @include('admin.partials.bar-list', [
                    'caption' => 'Expenses by category',
                    'rows' => $expenseByCategory->map(fn ($cat) => ['label' => $cat->name, 'value' => (float) ($cat->total ?? 0), 'display' => 'RM '.number_format($cat->total ?? 0, 2)])->values()->all(),
                ])
                </div>
                @endif
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
            <div class="list-group list-group-flush" style="max-height:220px;overflow-y:auto" tabindex="0" role="region" aria-label="Members who paid this month">
                @forelse($paidMembers as $m)
                <div class="list-group-item py-2 d-flex align-items-center gap-2">
                    <i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i>
                    <span>{{ $m->name }}</span>
                    <span class="text-muted small ms-auto">{{ $jobLevels[$m->pivot->job_level] ?? $m->pivot->job_level }}</span>
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
            <div class="list-group list-group-flush" style="max-height:220px;overflow-y:auto" tabindex="0" role="region" aria-label="Members not yet paid this month">
                @forelse($unpaidMembers as $m)
                <div class="list-group-item py-2 d-flex align-items-center gap-2">
                    <i class="bi bi-circle text-muted" aria-hidden="true"></i>
                    <span>{{ $m->name }}</span>
                    <span class="text-muted small ms-auto">{{ $jobLevels[$m->pivot->job_level] ?? $m->pivot->job_level }}</span>
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
                <a href="{{ route('admin.clubs.payments.index', $club) }}" class="btn btn-sm btn-outline-secondary">View All</a>
            </div>
            <div class="list-group list-group-flush">
                @forelse($recentPayments as $p)
                <div class="list-group-item py-2 d-flex justify-content-between align-items-center">
                    <div>
                        <div class="small fw-semibold">{{ $p->user?->name ?? 'Former member' }}</div>
                        <div class="text-muted small">{{ $p->paid_date?->format('d M Y') }}</div>
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
                <a href="{{ route('admin.clubs.fund-usage.index', $club) }}" class="btn btn-sm btn-outline-secondary">View All</a>
            </div>
            <div class="list-group list-group-flush">
                @forelse($recentExpenses as $e)
                <div class="list-group-item py-2 d-flex justify-content-between align-items-center">
                    <div>
                        <div class="small fw-semibold">{{ Str::limit($e->description, 35) }}</div>
                        <div class="text-muted small">{{ $e->category->name }} &middot; {{ $e->expense_date->format('d M Y') }}</div>
                    </div>
                    <span class="fw-semibold small">−RM {{ number_format($e->amount,2) }}</span>
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
@include('partials.chart-js')
<script nonce="{{ $cspNonce }}">
window.cpWhenReady('chart', function () {
    // Theme tokens (tokens.css), like Statistics and the React dashboard: income solid lavender, expenses pale rose.
    const fallback = { '--chart-1-stroke': '91 79 207', '--chart-4': '249 203 211', '--chart-4-stroke': '184 64 90', '--chart-grid': '232 230 243', '--chart-axis': '95 92 122' };
    function tok(name, alpha) {
        const v = (getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback[name]).split(/\s+/).join(', ');
        return alpha === undefined ? `rgb(${v})` : `rgba(${v}, ${alpha})`;
    }
    const rm = (v) => 'RM ' + Number(v).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const chart = new Chart(document.getElementById('incomeExpenseChart'), {
        type: 'bar',
        data: {
            labels: @json(array_keys($monthlyIncome)),
            datasets: [
                { label: 'Income', data: @json(array_values($monthlyIncome)), borderRadius: 4 },
                { label: 'Expenses', data: @json(array_values($monthlyExpense)), borderRadius: 4, borderWidth: 1.5 },
            ],
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: (c) => `${c.dataset.label}: ${rm(c.parsed.y)}` } } },
            scales: { y: { beginAtZero: true } },
        },
    });
    function paint() {
        chart.data.datasets[0].backgroundColor = tok('--chart-1-stroke');
        chart.data.datasets[1].backgroundColor = tok('--chart-4', 0.55);
        chart.data.datasets[1].borderColor = tok('--chart-4-stroke');
        Object.values(chart.options.scales).forEach(s => { s.grid = { ...(s.grid || {}), color: tok('--chart-grid') }; s.ticks = { ...(s.ticks || {}), color: tok('--chart-axis') }; });
        chart.options.plugins.legend.labels = { ...(chart.options.plugins.legend.labels || {}), color: tok('--chart-axis') };
        chart.update();
    }
    paint();
    new MutationObserver(paint).observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
});
</script>
@endpush
