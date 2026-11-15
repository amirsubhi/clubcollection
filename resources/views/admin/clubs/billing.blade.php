@extends('layouts.app')
@section('title', $club->name . ' - Billing settings')
@section('page-title', $club->name . ' — Billing settings')

@section('content')
@php
    $planLabels = ['monthly' => 'Monthly (1 month)', 'quarterly' => 'Quarterly (3 months)', 'yearly' => 'Yearly (12 months)'];
    $payer = old('gateway_fee_payer', $club->gatewayFeePayer());
    if (! $memberFeeAllowed) { $payer = 'club'; }
    $defaultPlan = old('default_plan', $club->default_plan ?: 'yearly');
    $feeRm = old('gateway_fee', number_format($club->gatewayFeeSen() / 100, 2, '.', ''));
@endphp
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <form action="{{ route('admin.clubs.billing.update', $club) }}" method="POST">
                    @csrf @method('PUT')

                    <h2 class="h6 fw-semibold mb-1">Billing plans members can choose</h2>
                    <p class="text-muted small mb-3">
                        Members use these when paying ahead from their Payments page. Longer plans mean
                        fewer bills, so fewer gateway fees.
                    </p>
                    @foreach($plans as $plan => $months)
                        <div class="form-check mb-2">
                            <input type="hidden" name="allow_{{ $plan }}" value="0">
                            <input class="form-check-input @error('allow_'.$plan) is-invalid @enderror" type="checkbox"
                                   name="allow_{{ $plan }}" value="1" id="allow_{{ $plan }}"
                                   {{ old('allow_'.$plan, $club->{'allow_'.$plan} !== false) ? 'checked' : '' }}>
                            <label class="form-check-label" for="allow_{{ $plan }}">{{ $planLabels[$plan] }}</label>
                        </div>
                    @endforeach
                    @error('allow_monthly')<div class="text-danger small mb-2">{{ $message }}</div>@enderror

                    <div class="mb-4 mt-3">
                        <label for="default_plan" class="form-label fw-semibold">Default plan</label>
                        <select name="default_plan" id="default_plan" class="form-select @error('default_plan') is-invalid @enderror">
                            @foreach($planLabels as $plan => $label)
                                <option value="{{ $plan }}" {{ $defaultPlan === $plan ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Pre-selected for members. Yearly is recommended.</div>
                        @error('default_plan')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <hr>

                    <h2 class="h6 fw-semibold mb-1">Gateway fee ({{ $gatewayLabel }})</h2>
                    <p class="text-muted small mb-3">
                        The online gateway charges a fee for each bill (about RM1.00 for FPX).
                        Choose who pays it.
                    </p>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="gateway_fee_payer" id="payer_club" value="club" {{ $payer === 'club' ? 'checked' : '' }}>
                        <label class="form-check-label" for="payer_club">
                            Club absorbs it <span class="text-muted small">— members pay the fee amount only</span>
                        </label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="gateway_fee_payer" id="payer_member" value="member"
                               {{ $payer === 'member' ? 'checked' : '' }} {{ $memberFeeAllowed ? '' : 'disabled' }}
                               @unless($memberFeeAllowed) aria-describedby="payer_member_help" @endunless>
                        <label class="form-check-label" for="payer_member">
                            Member pays it <span class="text-muted small">— shown on the review page and added to what they pay</span>
                        </label>
                    </div>
                    @error('gateway_fee_payer')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
                    @unless($memberFeeAllowed)
                        <div class="alert alert-warning small mt-2 mb-3" id="payer_member_help">
                            <strong>Not available yet for ToyyibPay.</strong> How ToyyibPay's "charge to customer" option
                            behaves must first be confirmed in its sandbox. Until then the club absorbs the fee.
                        </div>
                    @endunless

                    <div class="mb-4">
                        <label for="gateway_fee" class="form-label fw-semibold">Gateway fee per bill (RM)</label>
                        <input type="number" step="0.01" min="0" max="10" inputmode="decimal" name="gateway_fee" id="gateway_fee"
                               class="form-control w-auto @error('gateway_fee') is-invalid @enderror" value="{{ $feeRm }}">
                        <div class="form-text">Used for the review page, savings hints and the dashboard's fee total. Check your gateway plan.</div>
                        @error('gateway_fee')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save billing settings
                        </button>
                        <a href="{{ route('admin.clubs.dashboard', $club) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
