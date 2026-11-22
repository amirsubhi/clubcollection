@extends('layouts.app')
@section('title', 'Set Fee Rates')
@section('page-title', 'Set New Fee Rates')
@section('page-club', $club->name)

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-7">
        @if(empty($jobLevels))
        <div class="alert alert-warning">
            This club has no active job levels, so there is nothing to set a rate for.
            <a href="{{ route('admin.clubs.job-levels.index', $club) }}">Add job levels</a> first.
        </div>
        @else
        <div class="alert alert-info small">
            <i class="bi bi-info-circle me-1"></i>
            Setting new rates will automatically close existing active rates for updated job levels.
            The levels are this club's own: add or rename them on the <a href="{{ route('admin.clubs.job-levels.index', $club) }}">Job Levels</a> page.
        </div>
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <form action="{{ route('admin.clubs.fee-rates.store', $club) }}" method="POST">
                    @csrf
                    @foreach($jobLevels as $key => $label)
                    <fieldset class="mb-3 bg-light p-3 rounded border">
                        <legend class="visually-hidden">{{ $label }}</legend>
                        <h2 class="h6 fw-semibold mb-3">{{ $label }}</h2>
                        <input type="hidden" name="rates[{{ $loop->index }}][job_level]" value="{{ $key }}">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="rate-{{ $key }}-amount" class="form-label small">Monthly Amount (RM) <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">RM</span>
                                    <input type="number" step="0.01" min="0" id="rate-{{ $key }}-amount" aria-describedby="rate-{{ $key }}-amount-help"
                                           @error('rates.'.$loop->index.'.monthly_amount') aria-invalid="true" @enderror
                                           name="rates[{{ $loop->index }}][monthly_amount]"
                                           class="form-control @error('rates.'.$loop->index.'.monthly_amount') is-invalid @enderror"
                                           value="{{ old('rates.'.$loop->index.'.monthly_amount') }}"
                                           placeholder="0.00">
                                </div>
                                <div class="form-text" id="rate-{{ $key }}-amount-help">Base fee per month. Quarterly/yearly payments multiply this amount.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="rate-{{ $key }}-from" class="form-label small">Effective From <span class="text-danger">*</span></label>
                                <input type="date" id="rate-{{ $key }}-from" aria-describedby="rate-{{ $key }}-from-help" @error('rates.'.$loop->index.'.effective_from') aria-invalid="true" @enderror name="rates[{{ $loop->index }}][effective_from]"
                                       class="form-control @error('rates.'.$loop->index.'.effective_from') is-invalid @enderror"
                                       value="{{ old('rates.'.$loop->index.'.effective_from', date('Y-m-d')) }}">
                                <div class="form-text" id="rate-{{ $key }}-from-help">Existing rate for this level will be closed on this date.</div>
                            </div>
                        </div>
                    </fieldset>
                    @endforeach

                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save Rates
                        </button>
                        <a href="{{ route('admin.clubs.fee-rates.index', $club) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
        @endif
    </div>
</div>
@endsection
