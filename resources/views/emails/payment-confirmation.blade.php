{{-- One language: the recipient's (sent under their locale; English sentences are the keys of lang/<code>.json). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
  .container { max-width: 580px; margin: 0 auto; background: #fff; border-radius: 8px; overflow: hidden; }
  .header { background: #198754; color: #fff; padding: 30px; text-align: center; }
  .header h1 { margin: 0; font-size: 22px; }
  .body { padding: 30px; }
  .amount { text-align: center; font-size: 36px; font-weight: bold; color: #198754; margin: 20px 0; }
  .details { background: #f8f9fa; border-radius: 6px; padding: 16px; margin: 20px 0; }
  .details table { width: 100%; border-collapse: collapse; }
  .details td { padding: 6px 0; font-size: 14px; }
  .details td:first-child { color: #6c757d; width: 45%; }
  .details td:last-child { font-weight: 600; }
  .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #6c757d; }
  .badge { display: inline-block; background: #198754; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 12px; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>✓ {{ __('Payment Confirmed') }}</h1>
    <p style="margin:8px 0 0;opacity:0.85">{{ $payment->club->name }}</p>
  </div>
  <div class="body">
    <p>{{ \App\Support\MailText::bold('Dear **:name**,', ['name' => $payment->user->name]) }}</p>
    <p>{{ __('Your payment has been received and confirmed. Thank you!') }}</p>

    @if($fees)
    <div class="amount">RM {{ number_format($fees->sum(fn ($f) => (float) $f->amount), 2) }}</div>
    <p style="text-align:center;margin-top:-10px;color:#6c757d">{{ $fees->count() === 1 ? __('1 fee paid together') : __(':count fees paid together', ['count' => $fees->count()]) }}{{ $group ? ' ('.($group->isTransfer() ? $group->reference : __('bill :code', ['code' => $group->bill_code])).')' : '' }}</p>
    @else
    <div class="amount">RM {{ number_format($payment->amount, 2) }}</div>
    @endif

    <div class="details">
      <table>
        <tr>
          <td>{{ __('Club') }}</td>
          <td>{{ $payment->club->name }}</td>
        </tr>
        {{-- One receipt number for the email: the group's shared one, else the fee's (RCP-{id} for older fees). --}}
        @php($receiptNo = $group?->receipt_no ?? ($fees ? null : $payment->receiptNumber()))
        @if($receiptNo)
        <tr>
          <td>{{ __('Receipt no.') }}</td>
          <td data-testid="receipt-no">{{ $receiptNo }}</td>
        </tr>
        @endif
        @if($fees)
        @foreach($fees as $fee)
        <tr>
          <td>{{ \App\Support\UiLang::date($fee->period_start) }} – {{ \App\Support\UiLang::date($fee->period_end) }}</td>
          {{-- A fee under the group's number shows its invoice no.; otherwise its own receipt number. --}}
          <td>RM {{ number_format($fee->amount, 2) }} &middot; {{ $receiptNo !== null && $fee->receipt_no === $receiptNo ? \App\Support\Member\MemberPaymentPresenter::invoiceNo($fee) : $fee->receiptNumber() }}</td>
        </tr>
        @endforeach
        @else
        <tr>
          <td>{{ __('Period') }}</td>
          <td>{{ \App\Support\UiLang::date($payment->period_start) }} – {{ \App\Support\UiLang::date($payment->period_end) }}</td>
        </tr>
        <tr>
          <td>{{ __('Payment Type') }}</td>
          <td>{{ ['monthly' => __('Monthly'), 'quarterly' => __('Quarterly'), 'yearly' => __('Yearly')][$payment->frequency] ?? ucfirst((string) $payment->frequency) }}</td>
        </tr>
        @endif
        <tr>
          <td>{{ __('Paid On') }}</td>
          <td>{{ \App\Support\UiLang::date($payment->paid_date) }}</td>
        </tr>
        @if($payment->paid_via === 'transfer')
        <tr>
          <td>{{ __('Method') }}</td>
          <td>{{ __('Bank transfer / DuitNow (verified by the club)') }}</td>
        </tr>
        @if($payment->transfer_bank_ref || $payment->transfer_ref)
        <tr>
          <td>{{ __('Bank reference') }}</td>
          <td>{{ $payment->transfer_bank_ref ?? $payment->transfer_ref }}</td>
        </tr>
        @endif
        @endif
        @if($payment->transaction_id)
        <tr>
          <td>{{ __('Transaction ID') }}</td>
          <td>{{ $payment->transaction_id }}</td>
        </tr>
        @endif
        @if($payment->reference && $payment->paid_via !== 'transfer' && ! $fees)
        <tr>
          <td>{{ __('Reference') }}</td>
          <td>{{ $payment->reference }}</td>
        </tr>
        @endif
        <tr>
          <td>{{ __('Status') }}</td>
          <td><span class="badge">{{ __('Paid') }}</span></td>
        </tr>
      </table>
    </div>

    <p style="font-size:13px;color:#6c757d">
      {{ __('Please keep this email as your payment receipt. If you have any questions, contact your club administrator.') }}
    </p>
    {{-- The club's receipt footer as issued (issuer snapshot): plain text, escaped, line breaks kept. --}}
    @php($receiptFooter = \App\Support\Member\MemberPaymentPresenter::issuer($payment)['footer'])
    @if($receiptFooter)
    <p style="font-size:13px;color:#6c757d" data-testid="receipt-footer">{!! nl2br(e($receiptFooter)) !!}</p>
    @endif
  </div>
  <div class="footer">
    &copy; {{ date('Y') }} {{ config('app.name') }} &middot; {{ __('This is an automated email, please do not reply.') }}
  </div>
</div>
</body>
</html>
