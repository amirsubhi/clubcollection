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

                    <hr>

                    <h2 class="h6 fw-semibold mb-1" id="transfer">Pindahan bank / DuitNow</h2>
                    <p class="text-muted small mb-3">
                        Let members pay by bank transfer or DuitNow with no gateway fee. They upload the slip, and an
                        admin checks it in <strong>Verifications</strong> before the fee counts as paid. Members see
                        these details, so use the club's own account.
                    </p>
                    <div class="form-check form-switch mb-3">
                        <input type="hidden" name="transfer_enabled" value="0">
                        <input class="form-check-input @error('transfer_enabled') is-invalid @enderror" type="checkbox" role="switch"
                               name="transfer_enabled" value="1" id="transfer_enabled"
                               {{ old('transfer_enabled', $club->transfer_enabled) ? 'checked' : '' }}>
                        <label class="form-check-label" for="transfer_enabled">Offer bank transfer / DuitNow to members</label>
                    </div>
                    @error('transfer_enabled')<div class="text-danger small mb-3">{{ $message }}</div>@enderror

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="bank_name" class="form-label fw-semibold">Bank name</label>
                            <input type="text" name="bank_name" id="bank_name" maxlength="100" autocomplete="off"
                                   class="form-control @error('bank_name') is-invalid @enderror"
                                   value="{{ old('bank_name', $club->bank_name) }}" placeholder="e.g. Bank Contoh">
                            @error('bank_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="bank_account_name" class="form-label fw-semibold">Account holder</label>
                            <input type="text" name="bank_account_name" id="bank_account_name" maxlength="150" autocomplete="off"
                                   class="form-control @error('bank_account_name') is-invalid @enderror"
                                   value="{{ old('bank_account_name', $club->bank_account_name) }}" placeholder="e.g. Kelab Contoh">
                            @error('bank_account_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="bank_account_no" class="form-label fw-semibold">Account number</label>
                            <input type="text" name="bank_account_no" id="bank_account_no" maxlength="20" inputmode="numeric" autocomplete="off"
                                   class="form-control @error('bank_account_no') is-invalid @enderror"
                                   value="{{ old('bank_account_no', $club->bank_account_no) }}" placeholder="e.g. 1234 5678 9012">
                            <div class="form-text">Digits, spaces or dashes.</div>
                            @error('bank_account_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="duitnow_id" class="form-label fw-semibold">DuitNow ID <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" name="duitnow_id" id="duitnow_id" maxlength="100" autocomplete="off"
                                   class="form-control @error('duitnow_id') is-invalid @enderror"
                                   value="{{ old('duitnow_id', $club->duitnow_id) }}" placeholder="Phone, business reg. no. or other ID">
                            <div class="form-text">Needed only if there is no account number.</div>
                            @error('duitnow_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
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
