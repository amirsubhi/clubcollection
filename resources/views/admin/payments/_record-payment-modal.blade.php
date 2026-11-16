{{--
    "Record payment…" (R2a): an admin records a fee paid outside the app.
    One dialog per page, filled from the data-record-* attributes of the
    button that opened it (PaymentController::markPaid validates again).
    After a validation error the page reloads and the dialog reopens for the
    same fee with what was typed.
--}}
@php
    $rpErrors = $errors->hasAny(['method', 'paid_date', 'reference', 'confirm_processing']);
    $rpOldId = $rpErrors ? old('payment_id') : null;
    $rpField = fn (string $f) => $errors->has($f) ? 'is-invalid' : '';
@endphp
<div class="modal fade" id="recordPaymentModal" tabindex="-1" aria-labelledby="recordPaymentTitle" aria-hidden="true" data-old-id="{{ $rpOldId }}">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" method="POST" action="" id="recordPaymentForm">
            @csrf
            @method('PATCH')
            <input type="hidden" name="payment_id" id="rp-payment-id" value="{{ old('payment_id') }}">
            <div class="modal-header">
                <h2 class="modal-title h5" id="recordPaymentTitle">Record payment</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small text-body-secondary mb-3">For money the club received outside the app: cash, a bank transfer without a slip, or another way. The member is shown it as paid.</p>
                <dl class="record-summary row g-0 mb-3 rounded border bg-body-tertiary px-3 py-2 small">
                    <dt class="col-4 fw-normal text-body-secondary">Member</dt>
                    <dd class="col-8 mb-1 fw-semibold" id="rp-member"></dd>
                    <dt class="col-4 fw-normal text-body-secondary">Fee</dt>
                    <dd class="col-8 mb-1" id="rp-period"></dd>
                    <dt class="col-4 fw-normal text-body-secondary">Amount</dt>
                    <dd class="col-8 mb-0 fw-semibold money" id="rp-amount"></dd>
                </dl>

                <div class="alert alert-warning {{ $errors->has('confirm_processing') ? '' : 'd-none' }}" id="rp-processing">
                    <div class="d-flex gap-2">
                        <i class="bi bi-arrow-repeat mt-1" aria-hidden="true"></i>
                        <div>
                            <p class="fw-semibold mb-1">An FPX payment is in progress</p>
                            <p class="mb-2 small">The member started paying online and the bank has not confirmed it yet. If that payment goes through, recording this one too means they paid twice. Check with the member first.</p>
                            <div class="form-check">
                                <input class="form-check-input {{ $rpField('confirm_processing') }}" type="checkbox" name="confirm_processing" value="1" id="rp-confirm"
                                       @if($errors->has('confirm_processing')) aria-invalid="true" aria-describedby="rp-confirm-error" @endif>
                                <label class="form-check-label small" for="rp-confirm">I checked: the FPX payment did not go through</label>
                            </div>
                        </div>
                    </div>
                </div>
                {{-- Outside the amber alert, so the red text keeps its contrast. --}}
                @error('confirm_processing')<div id="rp-confirm-error" role="alert" class="invalid-feedback d-block mt-n2 mb-3">{{ $message }}</div>@enderror

                <fieldset class="mb-3">
                    <legend class="form-label fw-semibold fs-6 mb-1">How was it paid? <span class="text-danger" aria-hidden="true">*</span></legend>
                    <div class="record-methods {{ $errors->has('method') ? 'is-invalid' : '' }}">
                        @foreach(['cash' => ['Cash', 'bi-cash-coin'], 'transfer' => ['Bank transfer', 'bi-bank'], 'other' => ['Other', 'bi-three-dots']] as $value => [$text, $icon])
                        <div class="form-check record-method">
                            <input class="form-check-input" type="radio" name="method" id="rp-method-{{ $value }}" value="{{ $value }}" required {{ old('method') === $value ? 'checked' : '' }}
                                   @if($loop->first && $errors->has('method')) aria-invalid="true" aria-describedby="rp-method-error" @endif>
                            <label class="form-check-label" for="rp-method-{{ $value }}"><i class="bi {{ $icon }}" aria-hidden="true"></i>{{ $text }}</label>
                        </div>
                        @endforeach
                    </div>
                    @error('method')<div id="rp-method-error" role="alert" class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </fieldset>

                <div class="mb-3">
                    <label for="rp-date" class="form-label fw-semibold">Date received <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="date" class="form-control {{ $rpField('paid_date') }}" name="paid_date" id="rp-date" required
                           value="{{ old('paid_date', now()->toDateString()) }}" max="{{ now()->toDateString() }}"
                           aria-describedby="rp-date-help @error('paid_date') rp-date-error @enderror" @error('paid_date') aria-invalid="true" @enderror>
                    <div id="rp-date-help" class="form-text">Not in the future<span id="rp-date-min"></span>.</div>
                    @error('paid_date')<div id="rp-date-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-1">
                    <label for="rp-ref" class="form-label fw-semibold">Reference <span class="fw-normal text-body-secondary">(optional)</span></label>
                    <input type="text" class="form-control {{ $rpField('reference') }}" name="reference" id="rp-ref" maxlength="255" autocomplete="off"
                           value="{{ old('reference') }}" aria-describedby="rp-ref-help @error('reference') rp-ref-error @enderror" @error('reference') aria-invalid="true" @enderror>
                    <div id="rp-ref-help" class="form-text">Receipt number, bank reference or a short note. Kept in the audit log.</div>
                    @error('reference')<div id="rp-ref-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Record payment</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script nonce="{{ $cspNonce }}">
(function () {
    const modal = document.getElementById('recordPaymentModal');
    if (!modal) return;
    const form = document.getElementById('recordPaymentForm');
    const $ = (id) => document.getElementById(id);
    let reopened = false;
    const fmt = (iso) => {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
        return m ? new Date(+m[1], +m[2] - 1, +m[3]).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '';
    };

    modal.addEventListener('show.bs.modal', (e) => {
        const btn = e.relatedTarget;
        if (!btn || !btn.dataset.recordAction) return;
        const d = btn.dataset;
        // Reopened after a validation error: keep what the admin typed.
        const keep = !reopened && modal.dataset.oldId !== '' && modal.dataset.oldId === d.recordId;
        reopened = true;
        form.action = d.recordAction;
        $('rp-payment-id').value = d.recordId;
        $('rp-member').textContent = d.recordMember;
        $('rp-period').textContent = d.recordPeriod;
        $('rp-amount').textContent = d.recordAmount;
        const date = $('rp-date');
        date.min = d.recordMin;
        $('rp-date-min').textContent = ', not before ' + fmt(d.recordMin);
        $('rp-processing').classList.toggle('d-none', d.recordProcessing !== '1');
        $('rp-confirm').required = d.recordProcessing === '1';
        if (!keep) {
            form.querySelectorAll('input[name="method"]').forEach((r) => { r.checked = false; });
            $('rp-confirm').checked = false;
            $('rp-ref').value = '';
            date.value = date.max;
            form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
            form.querySelectorAll('.invalid-feedback').forEach((el) => el.remove());
            form.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
            form.querySelectorAll('[aria-describedby]').forEach((el) => {
                const ids = el.getAttribute('aria-describedby').split(/\s+/).filter((id) => id && !id.endsWith('-error'));
                if (ids.length) el.setAttribute('aria-describedby', ids.join(' ')); else el.removeAttribute('aria-describedby');
            });
        }
        date.dispatchEvent(new Event('change'));
    });
    // Focus the first choice, not the close button.
    modal.addEventListener('shown.bs.modal', () => {
        const target = modal.querySelector('[aria-invalid="true"]') || ($('rp-processing').classList.contains('d-none') ? modal.querySelector('input[name="method"]') : $('rp-confirm'));
        target?.focus();
    });

    // Closed: back to the fee's own "Record payment…" button (also when the dialog was reopened after an error, with no click to return to).
    modal.addEventListener('hidden.bs.modal', () => {
        const id = document.getElementById('rp-payment-id').value;
        const trigger = id ? document.querySelector('[data-record-id="' + id + '"]') : null;
        if (trigger && document.activeElement !== trigger) trigger.focus();
    });

    if (modal.dataset.oldId) {
        const btn = document.querySelector('[data-record-id="' + modal.dataset.oldId + '"]');
        if (btn) window.cpWhenReady('bootstrap', () => window.bootstrap.Modal.getOrCreateInstance(modal).show(btn));
    }
})();
</script>
@endpush
