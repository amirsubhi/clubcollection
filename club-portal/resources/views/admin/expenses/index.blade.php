@extends('layouts.app')
@section('title', $club->name . ' - Expenses')
@section('page-title', $club->name . ' — Expenses')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="card border-0 bg-danger bg-opacity-10 px-3 py-2">
        <span class="small text-danger fw-semibold">This Month Total: RM {{ number_format($totalThisMonth, 2) }}</span>
    </div>
    <a href="{{ route('admin.expenses.create', $club) }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Record Expense
    </a>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1">Month</label>
                <input type="month" name="month" class="form-control form-control-sm" value="{{ $month }}">
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1">Category</label>
                <select name="category" class="form-select form-select-sm">
                    <option value="">All Categories</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" {{ $category == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                <a href="{{ route('admin.expenses.index', $club) }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                <a href="{{ route('admin.expense-categories.index', $club) }}" class="btn btn-sm btn-outline-secondary ms-auto">
                    <i class="bi bi-tags me-1"></i>Categories
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
                    <th>Date</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Amount</th>
                    <th>Receipt</th>
                    <th>Recorded By</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($expenses as $expense)
                <tr>
                    <td class="small">{{ $expense->expense_date->format('d M Y') }}</td>
                    <td><span class="badge bg-secondary-subtle text-secondary border">{{ $expense->category->name }}</span></td>
                    <td>{{ Str::limit($expense->description, 50) }}</td>
                    <td class="fw-semibold text-danger">RM {{ number_format($expense->amount, 2) }}</td>
                    <td>
                        @if($expense->receipt)
                            <a href="{{ asset('storage/'.$expense->receipt) }}" target="_blank" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-file-earmark me-1"></i>View
                            </a>
                        @else
                            <span class="text-muted small">—</span>
                        @endif
                    </td>
                    <td class="small text-muted">{{ $expense->recordedBy->name }}</td>
                    <td class="text-end">
                        <a href="{{ route('admin.expenses.show', $expense) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                        <a href="{{ route('admin.expenses.edit', $expense) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form action="{{ route('admin.expenses.destroy', $expense) }}" method="POST" class="d-inline"
                              onsubmit="return confirm('Delete this expense?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center text-muted py-4">No expenses recorded.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $expenses->links() }}</div>
@endsection
