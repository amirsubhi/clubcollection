@extends('layouts.app')
@section('title', 'Expense Details')
@section('page-title', 'Expense Details')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <dl class="row mb-0">
                    <dt class="col-5 text-muted">Club</dt>
                    <dd class="col-7">{{ $expense->club->name }}</dd>
                    <dt class="col-5 text-muted">Category</dt>
                    <dd class="col-7">{{ $expense->category->name }}</dd>
                    <dt class="col-5 text-muted">Description</dt>
                    <dd class="col-7">{{ $expense->description }}</dd>
                    <dt class="col-5 text-muted">Amount</dt>
                    <dd class="col-7 fw-bold text-danger fs-5">RM {{ number_format($expense->amount, 2) }}</dd>
                    <dt class="col-5 text-muted">Date</dt>
                    <dd class="col-7">{{ $expense->expense_date->format('d M Y') }}</dd>
                    <dt class="col-5 text-muted">Receipt</dt>
                    <dd class="col-7">
                        @if($expense->receipt)
                            <a href="{{ asset('storage/'.$expense->receipt) }}" target="_blank" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-file-earmark-arrow-down me-1"></i>Download Receipt
                            </a>
                        @else
                            <span class="text-muted">No receipt uploaded</span>
                        @endif
                    </dd>
                    <dt class="col-5 text-muted">Recorded By</dt>
                    <dd class="col-7">{{ $expense->recordedBy->name }}</dd>
                    <dt class="col-5 text-muted">Recorded At</dt>
                    <dd class="col-7">{{ $expense->created_at->format('d M Y H:i') }}</dd>
                </dl>
            </div>
            <div class="card-footer d-flex gap-2">
                <a href="{{ route('admin.expenses.edit', $expense) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
                <a href="{{ route('admin.expenses.index', $expense->club) }}" class="btn btn-outline-secondary btn-sm ms-auto">Back</a>
            </div>
        </div>
    </div>
</div>
@endsection
