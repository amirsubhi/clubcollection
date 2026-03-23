<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
    * { box-sizing: border-box; }
    body {
        font-family: DejaVu Sans, Arial, sans-serif;
        font-size: 8.5pt;
        color: #1a1a1a;
        margin: 0;
        padding: 0;
    }
    .page { padding: 18mm 16mm 14mm 16mm; }

    /* ── Header ── */
    .report-header { margin-bottom: 14px; border-bottom: 2px solid #0d6efd; padding-bottom: 10px; }
    .report-header table { width: 100%; }
    .report-title { font-size: 14pt; font-weight: bold; color: #0d6efd; }
    .report-club  { font-size: 11pt; font-weight: bold; color: #212529; margin-top: 2px; }
    .report-meta  { font-size: 7.5pt; color: #6c757d; margin-top: 4px; }
    .report-logo-cell { text-align: right; vertical-align: top; }

    /* ── Section headings ── */
    .section-title {
        background: #0d6efd;
        color: #fff;
        font-weight: bold;
        font-size: 8pt;
        padding: 4px 8px;
        margin-top: 14px;
        margin-bottom: 0;
    }

    /* ── Generic table ── */
    table.data {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 0;
    }
    table.data th {
        background: #e9ecef;
        font-weight: bold;
        font-size: 7.5pt;
        padding: 4px 6px;
        border: 1px solid #dee2e6;
        text-align: left;
    }
    table.data td {
        padding: 3px 6px;
        border: 1px solid #dee2e6;
        vertical-align: top;
    }
    table.data tr:nth-child(even) td { background: #f8f9fa; }
    .text-right  { text-align: right; }
    .text-center { text-align: center; }
    .text-green  { color: #198754; }
    .text-red    { color: #dc3545; }
    .text-blue   { color: #0d6efd; }
    .text-muted  { color: #6c757d; }
    .fw-bold     { font-weight: bold; }
    .font-mono   { font-family: DejaVu Sans Mono, Courier, monospace; font-size: 7.5pt; }

    /* ── Summary cards ── */
    .summary-table { width: 100%; border-collapse: collapse; margin-top: 10px; margin-bottom: 10px; }
    .summary-table td {
        width: 25%;
        border: 1px solid #dee2e6;
        padding: 8px 10px;
        vertical-align: top;
    }
    .summary-label { font-size: 7pt; color: #6c757d; margin-bottom: 3px; }
    .summary-value { font-size: 12pt; font-weight: bold; }

    /* ── Opening/closing rows ── */
    .row-opening td { background: #e8f4fd !important; font-style: italic; color: #6c757d; }
    .row-closing td { background: #e9ecef !important; font-weight: bold; }

    /* ── Outstanding badges ── */
    .badge-overdue { background: #dc3545; color: #fff; padding: 1px 5px; border-radius: 3px; font-size: 7pt; }
    .badge-pending { background: #ffc107; color: #1a1a1a; padding: 1px 5px; border-radius: 3px; font-size: 7pt; }

    /* ── Footer ── */
    .page-footer {
        position: fixed;
        bottom: 8mm;
        left: 16mm;
        right: 16mm;
        border-top: 1px solid #dee2e6;
        padding-top: 4px;
        font-size: 7pt;
        color: #6c757d;
    }
    .page-footer table { width: 100%; }

    /* ── Two-column layout ── */
    .two-col table.left  { width: 100%; }
    .two-col-wrap { width: 100%; }
    .col-left  { width: 38%; vertical-align: top; padding-right: 10px; }
    .col-right { width: 62%; vertical-align: top; }
</style>
</head>
<body>

<div class="page-footer">
    <table>
        <tr>
            <td>{{ $club->name }} — Financial Ledger Report</td>
            <td class="text-right">Generated {{ $generatedAt }} by {{ $generatedBy }}</td>
            <td class="text-right" style="width:60px">Page <span class="pagenum"></span></td>
        </tr>
    </table>
</div>

<div class="page">

    {{-- ── Report header ───────────────────────────────────────────────── --}}
    <div class="report-header">
        <table>
            <tr>
                <td>
                    <div class="report-title">Financial Ledger Report</div>
                    <div class="report-club">{{ $club->name }}</div>
                    <div class="report-meta">
                        Period: {{ $filters['from']->format('d M Y') }} — {{ $filters['to']->format('d M Y') }}
                        &nbsp;&nbsp;|&nbsp;&nbsp;
                        Opening Balance: RM {{ number_format($filters['openingBalance'], 2) }}
                        &nbsp;&nbsp;|&nbsp;&nbsp;
                        Generated: {{ $generatedAt }} by {{ $generatedBy }}
                    </div>
                </td>
                <td class="report-logo-cell">
                    @if($club->logo)
                    <img src="{{ storage_path('app/public/' . $club->logo) }}" style="max-height:40px;max-width:120px">
                    @endif
                </td>
            </tr>
        </table>
    </div>

    {{-- ── Summary cards ────────────────────────────────────────────────── --}}
    <table class="summary-table">
        <tr>
            <td>
                <div class="summary-label">Opening Balance</div>
                <div class="summary-value">RM {{ number_format($filters['openingBalance'], 2) }}</div>
            </td>
            <td>
                <div class="summary-label">Total In</div>
                <div class="summary-value text-green">RM {{ number_format($totalIncome, 2) }}</div>
            </td>
            <td>
                <div class="summary-label">Total Out</div>
                <div class="summary-value text-red">RM {{ number_format($totalExpenses, 2) }}</div>
            </td>
            <td>
                <div class="summary-label">Closing Balance</div>
                <div class="summary-value {{ $closingBalance < 0 ? 'text-red' : 'text-green' }}">
                    RM {{ number_format(abs($closingBalance), 2) }}
                    @if($closingBalance < 0) <span style="font-size:8pt">(deficit)</span>@endif
                </div>
            </td>
        </tr>
    </table>

    {{-- ── Two-column: monthly breakdown + outstanding ─────────────────── --}}
    <table style="width:100%;border-collapse:collapse">
        <tr>
            <td class="col-left">
                {{-- Monthly breakdown --}}
                <div class="section-title">Monthly Breakdown</div>
                <table class="data">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th class="text-right text-green">In (RM)</th>
                            <th class="text-right text-red">Out (RM)</th>
                            <th class="text-right">Net (RM)</th>
                            <th class="text-right">Balance (RM)</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($monthlySummary as $row)
                    <tr>
                        <td>{{ $row['month'] }}</td>
                        <td class="text-right text-green">{{ $row['income'] > 0 ? number_format($row['income'],2) : '—' }}</td>
                        <td class="text-right text-red">{{ $row['expenses'] > 0 ? number_format($row['expenses'],2) : '—' }}</td>
                        <td class="text-right {{ $row['net'] < 0 ? 'text-red' : ($row['net'] > 0 ? 'text-green' : '') }}">
                            {{ number_format($row['net'], 2) }}
                        </td>
                        <td class="text-right fw-bold {{ $row['balance'] < 0 ? 'text-red' : '' }}">
                            {{ number_format($row['balance'], 2) }}
                        </td>
                    </tr>
                    @endforeach
                    </tbody>
                </table>
            </td>
            <td class="col-right">
                {{-- Outstanding payments --}}
                <div class="section-title">
                    Outstanding Payments (All Time)
                    @if($overduePayments->isNotEmpty()) — {{ $overduePayments->count() }} overdue
                    @endif
                    @if($pendingPayments->isNotEmpty()) — {{ $pendingPayments->count() }} pending
                    @endif
                </div>
                @if($overduePayments->isEmpty() && $pendingPayments->isEmpty())
                <table class="data"><tr><td class="text-center text-muted">No outstanding payments.</td></tr></table>
                @else
                <table class="data">
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th>Period</th>
                            <th>Due Date</th>
                            <th class="text-right">Amount (RM)</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($overduePayments as $p)
                    <tr>
                        <td class="fw-bold">{{ $p->user->name }}</td>
                        <td>{{ $p->period_start->format('M Y') }}</td>
                        <td>{{ $p->due_date->format('d M Y') }}</td>
                        <td class="text-right text-red">{{ number_format($p->amount, 2) }}</td>
                        <td class="text-center"><span class="badge-overdue">Overdue</span></td>
                    </tr>
                    @endforeach
                    @foreach($pendingPayments as $p)
                    <tr>
                        <td>{{ $p->user->name }}</td>
                        <td>{{ $p->period_start->format('M Y') }}</td>
                        <td>{{ $p->due_date->format('d M Y') }}</td>
                        <td class="text-right">{{ number_format($p->amount, 2) }}</td>
                        <td class="text-center"><span class="badge-pending">Pending</span></td>
                    </tr>
                    @endforeach
                    <tr class="row-closing">
                        <td colspan="3" class="text-right">Total Outstanding (RM)</td>
                        <td class="text-right">{{ number_format($overduePayments->sum('amount') + $pendingPayments->sum('amount'), 2) }}</td>
                        <td></td>
                    </tr>
                    </tbody>
                </table>
                @endif
            </td>
        </tr>
    </table>

    {{-- ── Transaction ledger ───────────────────────────────────────────── --}}
    <div class="section-title" style="margin-top:16px">
        Transactions ({{ count($transactions) }})
    </div>
    <table class="data">
        <thead>
            <tr>
                <th style="width:22px">#</th>
                <th style="width:68px">Date</th>
                <th style="width:55px">Type</th>
                <th>Description</th>
                <th style="width:90px">Member / Category</th>
                <th style="width:60px">Ref</th>
                <th class="text-right" style="width:72px">In (RM)</th>
                <th class="text-right" style="width:72px">Out (RM)</th>
                <th class="text-right" style="width:80px">Balance (RM)</th>
            </tr>
        </thead>
        <tbody>
        @if($filters['openingBalance'] != 0 && count($transactions) > 0)
        <tr class="row-opening">
            <td class="text-center">—</td>
            <td>{{ $filters['from']->format('Y-m-d') }}</td>
            <td class="text-center">Opening</td>
            <td colspan="4" class="text-muted">Opening balance brought forward</td>
            <td></td>
            <td class="text-right fw-bold">{{ number_format($filters['openingBalance'], 2) }}</td>
        </tr>
        @endif

        @forelse($transactions as $i => $t)
        <tr>
            <td class="text-center text-muted">{{ $i + 1 }}</td>
            <td>{{ $t['date'] }}</td>
            <td class="text-center {{ $t['type'] === 'Income' ? 'text-green' : 'text-red' }}">
                {{ $t['type'] }}
            </td>
            <td>{{ $t['description'] }}</td>
            <td class="text-muted">{{ $t['party'] }}</td>
            <td class="font-mono text-muted">{{ $t['reference'] ?: '' }}</td>
            <td class="text-right text-green fw-bold">
                {{ $t['amount_in'] !== null ? number_format($t['amount_in'], 2) : '' }}
            </td>
            <td class="text-right text-red fw-bold">
                {{ $t['amount_out'] !== null ? number_format($t['amount_out'], 2) : '' }}
            </td>
            <td class="text-right fw-bold {{ $t['balance'] < 0 ? 'text-red' : '' }}">
                {{ number_format($t['balance'], 2) }}
            </td>
        </tr>
        @empty
        <tr><td colspan="9" class="text-center text-muted">No transactions in this period.</td></tr>
        @endforelse

        @if(count($transactions) > 0)
        <tr class="row-closing">
            <td colspan="6" class="text-right fw-bold">Closing Balance</td>
            <td class="text-right text-green fw-bold">{{ number_format($totalIncome, 2) }}</td>
            <td class="text-right text-red fw-bold">{{ number_format($totalExpenses, 2) }}</td>
            <td class="text-right fw-bold {{ $closingBalance < 0 ? 'text-red' : 'text-green' }}">
                {{ number_format($closingBalance, 2) }}
            </td>
        </tr>
        @endif
        </tbody>
    </table>

</div>
</body>
</html>
