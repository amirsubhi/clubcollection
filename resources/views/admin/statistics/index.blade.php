@extends('layouts.app')
@section('title', 'Platform Statistics')
@section('page-title', 'Platform Statistics')

@php
    $rm = fn (int $sen) => 'RM '.number_format($sen / 100, 2);
@endphp

@section('content')
<p class="text-muted mb-4">Money figures are each club's fund figures added together (the same numbers as the club dashboards and ledgers): all time, up to {{ $asOf }}.</p>

{{-- Money --}}
<section class="card shadow-sm mb-3 overflow-hidden" aria-labelledby="moneyTitle">
    <h2 id="moneyTitle" class="visually-hidden">Fund figures, all clubs</h2>
    <div class="stat-strip">
        <div>
            <span class="stat-label">Collected</span>
            <span class="stat-value money" data-testid="stat-collected">{{ $rm($totals['collected']) }}</span>
            <span class="stat-note">Fees paid, all time</span>
        </div>
        <div>
            <span class="stat-label">Spent</span>
            <span class="stat-value money" data-testid="stat-spent">{{ $rm($totals['spent']) }}</span>
            <span class="stat-note">Expenses {{ $rm($totals['expenses']) }} · Welfare {{ $rm($totals['welfare']) }} · Bank / gateway charges {{ $rm($totals['charges']) }}</span>
        </div>
        <div>
            <span class="stat-label">Fund balance</span>
            <span class="stat-value money {{ $totals['balance'] < 0 ? 'text-danger' : '' }}" data-testid="stat-balance">{{ $totals['balance'] < 0 ? '−' : '' }}{{ $rm(abs($totals['balance'])) }}</span>
            <span class="stat-note">Collected minus spent, as of {{ $asOf }}</span>
        </div>
    </div>
</section>

{{-- Counts --}}
<section class="card shadow-sm mb-4 overflow-hidden" aria-labelledby="countsTitle">
    <h2 id="countsTitle" class="visually-hidden">Clubs, members and fees</h2>
    <div class="stat-strip">
        <div><span class="stat-label">Clubs</span><span class="stat-value">{{ $totalClubs }}</span><span class="stat-note">{{ $activeClubs }} active</span></div>
        <div><span class="stat-label">Members</span><span class="stat-value">{{ $totalMembers }}</span><span class="stat-note">across all clubs</span></div>
        <div><span class="stat-label">Fee records</span><span class="stat-value">{{ $totalPayments }}</span><span class="stat-note">{{ $paidCount }} paid</span></div>
        <div><span class="stat-label">Due</span><span class="stat-value">{{ $pendingCount }}</span><span class="stat-note">unpaid, within the grace period</span></div>
        <div><span class="stat-label">Overdue</span><span class="stat-value">{{ $overdueCount }}</span><span class="stat-note">past the grace period</span></div>
        <div><span class="stat-label">FPX in progress</span><span class="stat-value">{{ $processingCount }}</span><span class="stat-note">bank not confirmed yet</span></div>
        <div><span class="stat-label">Awaiting verification</span><span class="stat-value">{{ $verifyingCount }}</span><span class="stat-note">transfer slips to check</span></div>
    </div>
</section>

<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <section class="card shadow-sm h-100" aria-labelledby="trendTitle">
            <div class="card-header"><h2 id="trendTitle" class="h6 m-0 fw-semibold">Collected vs spent, last 12 months (all clubs)</h2></div>
            <div class="card-body">
                <div style="position:relative;height:260px">
                    <canvas id="trendChart" role="img" aria-label="Bar chart of money collected and spent per month for the last 12 months. The same figures are in the table below the chart."></canvas>
                </div>
                <details class="mt-3">
                    <summary class="small fw-semibold">Show as table</summary>
                    <div class="table-responsive mt-2">
                        <table class="table table-sm small mb-0" data-testid="trend-table">
                            <caption class="visually-hidden">Collected and spent per month</caption>
                            <thead><tr><th scope="col">Month</th><th scope="col" class="text-end">Collected</th><th scope="col" class="text-end">Spent</th></tr></thead>
                            <tbody>
                                @foreach($monthly as $m)
                                <tr><th scope="row" class="fw-normal">{{ $m['label'] }}</th><td class="text-end money">{{ $rm($m['collected']) }}</td><td class="text-end money">{{ $rm($m['spent']) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="card shadow-sm h-100" aria-labelledby="statusTitle">
            <div class="card-header"><h2 id="statusTitle" class="h6 m-0 fw-semibold">Fee status, all time</h2></div>
            <div class="card-body">
                @include('admin.partials.bar-list', [
                    'caption' => 'Fee records by status',
                    'testid' => 'status-bars',
                    'rows' => collect([['Paid', $paidCount], ['Due', $pendingCount], ['Overdue', $overdueCount], ['FPX in progress', $processingCount], ['Awaiting verification', $verifyingCount]])
                        ->map(fn ($r) => ['label' => $r[0], 'value' => $r[1], 'display' => number_format($r[1])])->all(),
                ])
            </div>
        </section>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <section class="card shadow-sm h-100" aria-labelledby="clubChartTitle">
            <div class="card-header"><h2 id="clubChartTitle" class="h6 m-0 fw-semibold">Collected by club, all time</h2></div>
            <div class="card-body">
                @include('admin.partials.bar-list', [
                    'caption' => 'Money collected by each club',
                    'testid' => 'club-bars',
                    'rows' => $clubStats->map(fn ($c) => ['label' => $c->name, 'value' => $c->collected_sen, 'display' => $rm($c->collected_sen)])->values()->all(),
                ])
            </div>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="card shadow-sm h-100" aria-labelledby="levelTitle">
            <div class="card-header"><h2 id="levelTitle" class="h6 m-0 fw-semibold">Members by job level</h2></div>
            <div class="card-body">
                @include('admin.partials.bar-list', [
                    'caption' => 'Members by job level',
                    'testid' => 'level-bars',
                    'rows' => collect($jobLevelLabels)->map(fn ($label, $key) => ['label' => $label, 'value' => (int) $jobLevelDistribution[$key], 'display' => number_format($jobLevelDistribution[$key])])->values()->all(),
                ])
            </div>
        </section>
    </div>
</div>

<section class="card shadow-sm mb-4" aria-labelledby="breakdownTitle">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 id="breakdownTitle" class="h6 m-0 fw-semibold">Club breakdown</h2>
        <span class="small text-muted">{{ $clubStats->count() }} {{ \Illuminate\Support\Str::plural('club', $clubStats->count()) }}</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small" data-testid="club-breakdown">
            <thead class="table-light">
                <tr>
                    <th scope="col">Club</th>
                    <th scope="col" class="text-end">Members</th>
                    <th scope="col" class="text-end">Collected</th>
                    <th scope="col" class="text-end">Spent</th>
                    <th scope="col" class="text-end">Fund balance</th>
                    <th scope="col" class="text-end">Paid</th>
                    <th scope="col" class="text-end">Due</th>
                    <th scope="col" class="text-end">Overdue</th>
                </tr>
            </thead>
            <tbody>
                @forelse($clubStats as $club)
                <tr data-club="{{ $club->id }}">
                    <th scope="row" class="fw-normal">
                        <a href="{{ route('admin.clubs.dashboard', $club) }}" class="fw-semibold">{{ $club->name }}</a>
                        @if(!$club->is_active)
                        <span class="badge bg-secondary ms-1">Inactive</span>
                        @endif
                    </th>
                    <td class="text-end">{{ $club->members_count }}</td>
                    <td class="text-end money">{{ $rm($club->collected_sen) }}</td>
                    <td class="text-end money">{{ $rm($club->spent_sen) }}</td>
                    <td class="text-end money fw-semibold {{ $club->balance_sen < 0 ? 'text-danger' : '' }}">{{ $club->balance_sen < 0 ? '−' : '' }}{{ $rm(abs($club->balance_sen)) }}</td>
                    <td class="text-end">{{ $club->paid_count }}</td>
                    <td class="text-end">{{ $club->pending_count }}</td>
                    <td class="text-end">{{ $club->overdue_count }}</td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No clubs yet.</td></tr>
                @endforelse
            </tbody>
            @if($clubStats->count() > 1)
            <tfoot class="table-light fw-semibold">
                <tr>
                    <th scope="row">Total</th>
                    <td class="text-end">{{ $clubStats->sum('members_count') }}</td>
                    <td class="text-end money">{{ $rm($totals['collected']) }}</td>
                    <td class="text-end money">{{ $rm($totals['spent']) }}</td>
                    <td class="text-end money {{ $totals['balance'] < 0 ? 'text-danger' : '' }}">{{ $totals['balance'] < 0 ? '−' : '' }}{{ $rm(abs($totals['balance'])) }}</td>
                    <td class="text-end">{{ $clubStats->sum('paid_count') }}</td>
                    <td class="text-end">{{ $clubStats->sum('pending_count') }}</td>
                    <td class="text-end">{{ $clubStats->sum('overdue_count') }}</td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</section>

<section class="card shadow-sm" aria-labelledby="recentTitle">
    <div class="card-header"><h2 id="recentTitle" class="h6 m-0 fw-semibold">Latest 10 fees paid</h2></div>
    <ul class="list-group list-group-flush">
        @forelse($recentTransactions as $p)
        <li class="list-group-item py-2 d-flex justify-content-between align-items-center gap-3">
            <div class="min-w-0">
                <div class="fw-semibold">{{ $p->user?->name ?? 'Former member' }}</div>
                <div class="small text-muted">
                    {{ $p->club->name }} &middot; {{ $p->paid_date?->format('d M Y') }}
                    @if($p->reference)
                    &middot; <span class="font-monospace">{{ $p->reference }}</span>
                    @endif
                </div>
            </div>
            <span class="text-success fw-semibold money">+RM {{ number_format($p->amount, 2) }}</span>
        </li>
        @empty
        <li class="list-group-item text-muted text-center py-4">No fees paid yet.</li>
        @endforelse
    </ul>
</section>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.js" integrity="sha384-G436+Z2nlA8+PNoeRvWdxKbvOf8E/y+lYxqht2iBwNHTQDV5CJr3+AGVj8fGZi5t" crossorigin="anonymous"></script>
<script nonce="{{ $cspNonce }}">
(function () {
    if (!window.Chart) return;
    // Colours come from the theme tokens (tokens.css), so the charts follow light / dark.
    const fallback = { '--chart-1-stroke': '91 79 207', '--chart-4': '249 203 211', '--chart-4-stroke': '184 64 90', '--chart-grid': '232 230 243', '--chart-axis': '95 92 122' };
    function tok(name, alpha) {
        const v = (getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback[name]).split(/\s+/).join(', ');
        return alpha === undefined ? `rgb(${v})` : `rgba(${v}, ${alpha})`;
    }
    const rm = (v) => 'RM ' + Number(v).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const charts = [];

    const monthly = @json($monthly);
    charts.push([new Chart(document.getElementById('trendChart'), {
        type: 'bar',
        data: {
            labels: monthly.map(m => m.label),
            datasets: [
                { label: 'Collected', data: monthly.map(m => m.collected / 100), borderRadius: 4 },
                { label: 'Spent', data: monthly.map(m => m.spent / 100), borderRadius: 4 },
            ],
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: (c) => `${c.dataset.label}: ${rm(c.parsed.y)}` } } },
            scales: { y: { beginAtZero: true } },
        },
    }), (c) => {
        // Same marks as the React CollectedSpentChart: collected solid lavender, spent a pale rose with its stroke.
        c.data.datasets[0].backgroundColor = tok('--chart-1-stroke');
        c.data.datasets[1].backgroundColor = tok('--chart-4', 0.55);
        c.data.datasets[1].borderColor = tok('--chart-4-stroke');
        c.data.datasets[1].borderWidth = 1.5;
    }]);

    function paint() {
        Chart.defaults.color = tok('--chart-axis');
        Chart.defaults.borderColor = tok('--chart-grid');
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        charts.forEach(([chart, colour]) => {
            colour(chart);
            Object.values(chart.options.scales || {}).forEach(s => { s.grid = { ...(s.grid || {}), color: tok('--chart-grid') }; s.ticks = { ...(s.ticks || {}), color: tok('--chart-axis') }; });
            if (chart.options.plugins.legend) chart.options.plugins.legend.labels = { ...(chart.options.plugins.legend.labels || {}), color: tok('--chart-axis') };
            chart.update();
        });
    }
    paint();
    // Theme switched (menu or OS): repaint with the new tokens.
    new MutationObserver(paint).observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
})();
</script>
@endpush
