{{-- Opens the "Record payment…" dialog (_record-payment-modal) for one fee. --}}
@php
    $rpMember = $payment->user?->name ?? 'Former member';
    $rpPeriod = $payment->period_start->format('d M Y').' – '.$payment->period_end->format('d M Y');
@endphp
<button type="button" class="{{ $class ?? 'btn btn-sm btn-primary' }}" data-bs-toggle="modal" data-bs-target="#recordPaymentModal"
        data-record-action="{{ route('admin.payments.mark-paid', $payment) }}"
        data-record-id="{{ $payment->id }}"
        data-record-member="{{ $rpMember }}"
        data-record-period="{{ $rpPeriod }}"
        data-record-amount="RM {{ number_format($payment->amount, 2) }}"
        data-record-min="{{ \App\Http\Controllers\Admin\PaymentController::earliestPaidDate($payment)->toDateString() }}"
        data-record-processing="{{ $status === \App\Support\Payments\DisplayStatus::PROCESSING ? '1' : '0' }}"><i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Record payment…<span class="visually-hidden"> for {{ $rpMember }}, {{ $rpPeriod }}</span></button>
