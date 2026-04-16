@extends('layouts.app')
@section('title', $club->name)
@section('page-title', $club->name)

@section('content')
<div class="row g-4">
    <div class="col-md-4">
        <div class="card shadow-sm text-center p-4">
            @if($club->logo)
                <img src="{{ asset('storage/'.$club->logo) }}" alt="{{ $club->name }} logo" class="rounded-circle mx-auto mb-3 border" width="100" height="100" style="object-fit:cover">
            @else
                <div class="bg-secondary rounded-circle mx-auto mb-3 d-flex align-items-center justify-content-center" style="width:100px;height:100px">
                    <i class="bi bi-building fs-1 text-white"></i>
                </div>
            @endif
            <h5 class="fw-bold">{{ $club->name }}</h5>
            <p class="text-muted small">{{ $club->email ?? 'No email set' }}</p>
            <span class="badge {{ $club->is_active ? 'bg-success' : 'bg-secondary' }}">
                {{ $club->is_active ? 'Active' : 'Inactive' }}
            </span>
            <div class="mt-3">
                <a href="{{ route('admin.clubs.edit', $club) }}" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-pencil me-1"></i>Edit Club
                </a>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold">Members ({{ $club->members->count() }})</h6>
                <a href="{{ route('admin.clubs.members.create', $club) }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1"></i>Add Member
                </a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Job Level</th></tr>
                    </thead>
                    <tbody>
                        @forelse($club->members as $member)
                        <tr>
                            <td>{{ $member->name }}</td>
                            <td class="text-muted small">{{ $member->email }}</td>
                            <td><span class="badge bg-info-subtle text-info border border-info-subtle">{{ ucfirst($member->pivot->role) }}</span></td>
                            <td>{{ \App\Models\FeeRate::jobLevelLabels()[$member->pivot->job_level] ?? $member->pivot->job_level }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">No members yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold">Fee Rates</h6>
                <a href="{{ route('admin.clubs.fee-rates.index', $club) }}" class="btn btn-sm btn-outline-secondary">
                    Manage Rates
                </a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr><th scope="col">Job Level</th><th scope="col">Monthly (RM)</th><th scope="col">Effective From</th></tr>
                    </thead>
                    <tbody>
                        @forelse($club->feeRates->sortByDesc('effective_from')->take(10) as $rate)
                        <tr>
                            <td>{{ \App\Models\FeeRate::jobLevelLabels()[$rate->job_level] ?? $rate->job_level }}</td>
                            <td>RM {{ number_format($rate->monthly_amount, 2) }}</td>
                            <td>{{ $rate->effective_from->format('d M Y') }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="3" class="text-center text-muted py-3">No fee rates configured.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
