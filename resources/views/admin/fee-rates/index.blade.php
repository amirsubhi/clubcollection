@extends('layouts.app')
@section('title', $club->name . ' - Fee Rates')
@section('page-title', $club->name . ' — Fee Rates')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <p class="text-muted mb-0">Current and historical fee rates for this club.</p>
    <a href="{{ route('admin.clubs.fee-rates.create', $club) }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Set New Rates
    </a>
</div>

@foreach($jobLevels as $key => $label)
<div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold">{{ $label }}</h6>
        @if(isset($rates[$key]) && $rates[$key]->first()?->effective_to === null)
            <span class="badge bg-success">Current Rate: RM {{ number_format($rates[$key]->first()->monthly_amount, 2) }}/month</span>
        @else
            <span class="badge bg-secondary">No active rate</span>
        @endif
    </div>
    @if(isset($rates[$key]) && $rates[$key]->count() > 0)
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th scope="col">Monthly (RM)</th>
                    <th scope="col">Effective From</th>
                    <th scope="col">Effective To</th>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rates[$key] as $rate)
                <tr>
                    <td class="fw-semibold">RM {{ number_format($rate->monthly_amount, 2) }}</td>
                    <td>{{ $rate->effective_from->format('d M Y') }}</td>
                    <td>
                        @if($rate->effective_to)
                            {{ $rate->effective_to->format('d M Y') }}
                        @else
                            <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <form action="{{ route('admin.fee-rates.destroy', [$club, $rate]) }}" method="POST" class="d-inline"
                              data-confirm="Delete this rate record?">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger" aria-label="Delete rate"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
@endforeach
@endsection
