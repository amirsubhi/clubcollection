@extends('layouts.member')
@section('title', 'My Dashboard')
@section('page-heading', 'My Dashboard')

@section('content')
<p class="text-muted mb-4">Welcome back, <strong>{{ auth()->user()->name }}</strong>. Here are your club memberships.</p>
<p class="text-muted mb-4">Manage your club membership fees below.</p>

@forelse($clubs as $club)
@php $s = $summary[$club->id]; @endphp
<div class="card shadow-sm mb-4">
    <div class="card-body p-4">
        <div class="d-flex align-items-center gap-3 mb-3">
            @if($club->logo)
                <img src="{{ asset('storage/'.$club->logo) }}" alt="{{ $club->name }} logo" width="48" height="48" class="rounded-circle border" style="object-fit:cover">
            @else
                <div class="bg-secondary rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px">
                    <i class="bi bi-building text-white"></i>
                </div>
            @endif
            <div>
                <h6 class="mb-0 fw-bold">{{ $club->name }}</h6>
                <span class="text-muted small">{{ $jobLevels[$club->pivot->job_level] ?? $club->pivot->job_level }} &middot; {{ ucfirst($club->pivot->role) }}</span>
            </div>
            <a href="{{ route('member.payments.index', $club) }}" class="btn btn-primary btn-sm ms-auto">
                <i class="bi bi-wallet2 me-1"></i>My Payments
            </a>
        </div>

        <div class="row g-3">
            <div class="col-6 col-md-3">
                <div class="text-center p-3 bg-success bg-opacity-10 rounded">
                    <div class="fw-bold text-success">{{ $s['paid'] }}</div>
                    <div class="small text-muted">Paid</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-center p-3 bg-warning bg-opacity-10 rounded">
                    <div class="fw-bold text-warning">{{ $s['pending'] }}</div>
                    <div class="small text-muted">Pending</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-center p-3 bg-danger bg-opacity-10 rounded">
                    <div class="fw-bold text-danger">{{ $s['overdue'] }}</div>
                    <div class="small text-muted">Overdue</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-center p-3 bg-primary bg-opacity-10 rounded">
                    <div class="fw-bold text-primary">RM {{ number_format($s['total_paid'], 2) }}</div>
                    <div class="small text-muted">Total Paid</div>
                </div>
            </div>
        </div>

        @if($s['overdue'] > 0)
        <div class="alert alert-danger mt-3 mb-0 py-2 small">
            <i class="bi bi-exclamation-triangle me-1"></i>
            You have <strong>{{ $s['overdue'] }} overdue</strong> payment(s). Please settle as soon as possible.
            <a href="{{ route('member.payments.index', $club) }}" class="alert-link ms-1">Pay now →</a>
        </div>
        @endif
    </div>
</div>
@empty
<div class="card shadow-sm text-center p-5">
    <i class="bi bi-building fs-1 text-muted"></i>
    <p class="text-muted mt-3">You are not a member of any club yet.</p>
</div>
@endforelse
@endsection
