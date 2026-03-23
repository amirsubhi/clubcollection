@extends('layouts.app')
@section('title', 'Set Fee Rates')
@section('page-title', $club->name . ' — Set New Fee Rates')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="alert alert-info small">
            <i class="bi bi-info-circle me-1"></i>
            Setting new rates will automatically close existing active rates for updated job levels.
        </div>
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <form action="{{ route('admin.fee-rates.store', $club) }}" method="POST">
                    @csrf
                    @foreach($jobLevels as $key => $label)
                    <div class="card mb-3 border-0 bg-light p-3 rounded">
                        <h6 class="fw-semibold mb-3">{{ $label }}</h6>
                        <input type="hidden" name="rates[{{ $loop->index }}][job_level]" value="{{ $key }}">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small">Monthly Amount (RM) <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">RM</span>
                                    <input type="number" step="0.01" min="0"
                                           name="rates[{{ $loop->index }}][monthly_amount]"
                                           class="form-control @error('rates.'.$loop->index.'.monthly_amount') is-invalid @enderror"
                                           value="{{ old('rates.'.$loop->index.'.monthly_amount') }}"
                                           placeholder="0.00">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">Effective From <span class="text-danger">*</span></label>
                                <input type="date" name="rates[{{ $loop->index }}][effective_from]"
                                       class="form-control @error('rates.'.$loop->index.'.effective_from') is-invalid @enderror"
                                       value="{{ old('rates.'.$loop->index.'.effective_from', date('Y-m-d')) }}">
                            </div>
                        </div>
                    </div>
                    @endforeach

                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save Rates
                        </button>
                        <a href="{{ route('admin.fee-rates.index', $club) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
