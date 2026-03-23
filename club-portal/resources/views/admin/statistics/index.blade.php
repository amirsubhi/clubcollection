@extends('layouts.app')
@section('title', 'Platform Statistics')
@section('page-title', 'Platform Statistics')

@push('styles')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
@endpush

@section('content')

{{-- Row 1: Primary KPI Cards --}}
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="bg-primary bg-opacity-10 rounded p-2">
                    <i class="bi bi-building fs-4 text-primary"></i>
                </div>
                <div>
                    <div class="fs-3 fw-bold lh-1">{{ $totalClubs }}</div>
                    <div class="text-muted small mt-1">Total Clubs</div>
                    <div style="font-size:0.72rem" class="text-success">{{ $activeClubs }} active</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="bg-success bg-opacity-10 rounded p-2">
                    <i class="bi bi-people fs-4 text-success"></i>
                </div>
                <div>
                    <div class="fs-3 fw-bold lh-1">{{ $totalMembers }}</div>
                    <div class="text-muted small mt-1">Total Members</div>
                    <div style="font-size:0.72rem" class="text-muted">across all clubs</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="bg-info bg-opacity-10 rounded p-2">
                    <i class="bi bi-arrow-left-right fs-4 text-info"></i>
                </div>
                <div>
                    <div class="fs-3 fw-bold lh-1">{{ $totalPayments }}</div>
                    <div class="text-muted small mt-1">Total Transactions</div>
                    <div style="font-size:0.72rem" class="text-success">{{ $paidCount }} paid</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="bg-success bg-opacity-10 rounded p-2">
                    <i class="bi bi-cash-coin fs-4 text-success"></i>
                </div>
                <div>
                    <div class="fs-3 fw-bold lh-1 text-success">RM {{ number_format($totalRevenue, 2) }}</div>
                    <div class="text-muted small mt-1">Total Revenue Collected</div>
                    <div style="font-size:0.72rem" class="text-muted">all time</div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Row 2: Secondary KPI Cards --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="bg-warning bg-opacity-10 rounded p-2">
                    <i class="bi bi-hourglass-split fs-4 text-warning"></i>
                </div>
                <div>
                    <div class="fs-3 fw-bold lh-1 text-warning">{{ $pendingCount }}</div>
                    <div class="text-muted small mt-1">Pending Payments</div>
                    <div style="font-size:0.72rem" class="text-muted">awaiting payment</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="bg-danger bg-opacity-10 rounded p-2">
                    <i class="bi bi-exclamation-triangle fs-4 text-danger"></i>
                </div>
                <div>
                    <div class="fs-3 fw-bold lh-1 text-danger">{{ $overdueCount }}</div>
                    <div class="text-muted small mt-1">Overdue Payments</div>
                    <div style="font-size:0.72rem" class="text-danger">requires action</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="bg-danger bg-opacity-10 rounded p-2">
                    <i class="bi bi-receipt fs-4 text-danger"></i>
                </div>
                <div>
                    <div class="fs-3 fw-bold lh-1 text-danger">RM {{ number_format($totalExpenses, 2) }}</div>
                    <div class="text-muted small mt-1">Total Expenses</div>
                    <div style="font-size:0.72rem" class="text-muted">all clubs</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="{{ $netBalance >= 0 ? 'bg-success' : 'bg-danger' }} bg-opacity-10 rounded p-2">
                    <i class="bi bi-scale fs-4 {{ $netBalance >= 0 ? 'text-success' : 'text-danger' }}"></i>
                </div>
                <div>
                    <div class="fs-3 fw-bold lh-1 {{ $netBalance >= 0 ? 'text-success' : 'text-danger' }}">
                        RM {{ number_format(abs($netBalance), 2) }}
                    </div>
                    <div class="text-muted small mt-1">Net Balance</div>
                    <div style="font-size:0.72rem" class="{{ $netBalance >= 0 ? 'text-success' : 'text-danger' }}">
                        {{ $netBalance >= 0 ? 'surplus' : 'deficit' }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Row 3: Revenue/Expense Trend + Payment Status --}}
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold">Revenue vs Expenses — Last 12 Months (All Clubs)</div>
            <div class="card-body">
                <canvas id="trendChart" height="110"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold">Payment Status — All Time</div>
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

{{-- Row 4: Per-Club Revenue Chart + Job Level Distribution --}}
<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold">Revenue by Club (All Time)</div>
            <div class="card-body">
                <canvas id="clubRevenueChart" height="160"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold">Member Distribution by Job Level</div>
            <div class="card-body d-flex flex-column align-items-center justify-content-center">
                <canvas id="jobLevelChart" height="180" style="max-width:220px"></canvas>
                <div class="mt-3 w-100">
                    @foreach($jobLevelLabels as $key => $label)
                    <div class="d-flex justify-content-between small mb-1">
                        <span>{{ $label }}</span>
                        <strong>{{ $jobLevelDistribution[$key] }}</strong>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Row 5: Per-Club Breakdown Table --}}
<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span>Club Breakdown</span>
        <span class="badge bg-secondary">{{ $clubStats->count() }} clubs</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>Club</th>
                    <th class="text-end">Members</th>
                    <th class="text-end">Total Revenue</th>
                    <th class="text-end">Total Expenses</th>
                    <th class="text-end">Net</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Pending</th>
                    <th class="text-end">Overdue</th>
                </tr>
            </thead>
            <tbody>
                @forelse($clubStats as $club)
                @php $net = $club->total_revenue - $club->total_expenses; @endphp
                <tr>
                    <td>
                        <a href="{{ route('admin.clubs.dashboard', $club) }}" class="fw-semibold text-decoration-none">
                            {{ $club->name }}
                        </a>
                        @if(!$club->is_active)
                        <span class="badge bg-secondary ms-1" style="font-size:0.65rem">inactive</span>
                        @endif
                    </td>
                    <td class="text-end">{{ $club->members_count }}</td>
                    <td class="text-end text-success fw-semibold">RM {{ number_format($club->total_revenue, 2) }}</td>
                    <td class="text-end text-danger">RM {{ number_format($club->total_expenses, 2) }}</td>
                    <td class="text-end fw-semibold {{ $net >= 0 ? 'text-success' : 'text-danger' }}">
                        RM {{ number_format(abs($net), 2) }}
                        @if($net < 0)<small>(deficit)</small>@endif
                    </td>
                    <td class="text-end"><span class="badge bg-success">{{ $club->paid_count }}</span></td>
                    <td class="text-end"><span class="badge bg-warning text-dark">{{ $club->pending_count }}</span></td>
                    <td class="text-end"><span class="badge bg-danger">{{ $club->overdue_count }}</span></td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No clubs found.</td></tr>
                @endforelse
            </tbody>
            @if($clubStats->count() > 1)
            <tfoot class="table-light fw-semibold">
                <tr>
                    <td>Total</td>
                    <td class="text-end">{{ $clubStats->sum('members_count') }}</td>
                    <td class="text-end text-success">RM {{ number_format($totalRevenue, 2) }}</td>
                    <td class="text-end text-danger">RM {{ number_format($totalExpenses, 2) }}</td>
                    <td class="text-end {{ $netBalance >= 0 ? 'text-success' : 'text-danger' }}">
                        RM {{ number_format(abs($netBalance), 2) }}
                    </td>
                    <td class="text-end">{{ $paidCount }}</td>
                    <td class="text-end">{{ $pendingCount }}</td>
                    <td class="text-end">{{ $overdueCount }}</td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</div>

{{-- Row 6: Recent Transactions --}}
<div class="card shadow-sm">
    <div class="card-header fw-semibold">Recent Transactions (Latest 10 Paid)</div>
    <div class="list-group list-group-flush">
        @forelse($recentTransactions as $p)
        <div class="list-group-item py-2 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-success bg-opacity-10 rounded p-1" style="line-height:1">
                    <i class="bi bi-check-circle-fill text-success"></i>
                </div>
                <div>
                    <div class="small fw-semibold">{{ $p->user->name }}</div>
                    <div class="text-muted" style="font-size:0.75rem">
                        {{ $p->club->name }} &middot; {{ $p->paid_date?->format('d M Y') }}
                        @if($p->reference)
                        &middot; <span class="font-monospace">{{ $p->reference }}</span>
                        @endif
                    </div>
                </div>
            </div>
            <span class="text-success fw-semibold small">+RM {{ number_format($p->amount, 2) }}</span>
        </div>
        @empty
        <div class="list-group-item text-muted text-center py-4">No transactions recorded yet.</div>
        @endforelse
    </div>
</div>

@endsection

@push('scripts')
<script>
// Revenue vs Expenses trend (last 12 months)
const trendLabels   = @json(array_keys($monthlyRevenue));
const revenueData   = @json(array_values($monthlyRevenue));
const expenseData   = @json(array_values($monthlyExpenses));

new Chart(document.getElementById('trendChart'), {
    type: 'bar',
    data: {
        labels: trendLabels,
        datasets: [
            { label: 'Revenue (RM)',  data: revenueData,  backgroundColor: 'rgba(25,135,84,0.7)',  borderRadius: 4 },
            { label: 'Expenses (RM)', data: expenseData,  backgroundColor: 'rgba(220,53,69,0.7)',   borderRadius: 4 },
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' } },
        scales: { y: { beginAtZero: true } }
    }
});

// Payment status doughnut
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: ['Paid', 'Pending', 'Overdue'],
        datasets: [{
            data: [{{ $paidCount }}, {{ $pendingCount }}, {{ $overdueCount }}],
            backgroundColor: ['#198754', '#ffc107', '#dc3545']
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        cutout: '65%'
    }
});

// Per-club revenue horizontal bar chart
const clubLabels    = @json($clubStats->pluck('name')->values());
const clubRevenue   = @json($clubStats->pluck('total_revenue')->values());

new Chart(document.getElementById('clubRevenueChart'), {
    type: 'bar',
    data: {
        labels: clubLabels,
        datasets: [{
            label: 'Revenue (RM)',
            data: clubRevenue,
            backgroundColor: 'rgba(13,110,253,0.7)',
            borderRadius: 4
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true } }
    }
});

// Job level distribution doughnut
const jobLabels = @json($jobLevelChartLabels);
const jobCounts = @json($jobLevelChartCounts);

new Chart(document.getElementById('jobLevelChart'), {
    type: 'doughnut',
    data: {
        labels: jobLabels,
        datasets: [{
            data: jobCounts,
            backgroundColor: ['#0d6efd','#198754','#ffc107','#fd7e14','#dc3545']
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        cutout: '60%'
    }
});
</script>
@endpush
