{{-- One language: the recipient's (sent under their locale; English sentences are the keys of lang/<code>.json). --}}
@php
    $receiptNo = $cancellation->receipt_no ?? \App\Models\Payment::legacyReceiptNumber((int) $payment->id);
    $cancelledOn = $cancellation->created_at ? \App\Support\UiLang::date($cancellation->created_at) : null;
    $several = $fees->count() > 1;
    $totalSen = (int) $fees->sum(fn ($f) => $f->amountSen());
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
  .container { max-width: 580px; margin: 0 auto; background: #fff; border-radius: 8px; overflow: hidden; }
  .header { background: #b45309; color: #fff; padding: 30px; text-align: center; }
  .header h1 { margin: 0; font-size: 22px; }
  .body { padding: 30px; }
  .details { background: #f8f9fa; border-radius: 6px; padding: 16px; margin: 20px 0; }
  .details table { width: 100%; border-collapse: collapse; }
  .details td { padding: 6px 0; font-size: 14px; }
  .details td:first-child { color: #6c757d; width: 45%; }
  .details td:last-child { font-weight: 600; }
  .cta { text-align: center; margin: 28px 0; }
  .cta a { background: #5b4bc4; color: #fff; text-decoration: none; padding: 14px 32px; border-radius: 6px; font-weight: bold; font-size: 15px; display: inline-block; }
  .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #6c757d; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>{{ __('Payment cancelled') }}</h1>
    <p style="margin:8px 0 0;opacity:0.9">{{ $payment->club->name }}</p>
  </div>
  <div class="body">
    <p>{{ \App\Support\MailText::greeting('Hello **:name**,', $payment->user->name) }}</p>
    <p>{{ \App\Support\MailText::bold('Receipt **:no** was cancelled on :date.', ['no' => $receiptNo, 'date' => (string) $cancelledOn]) }}
      {{ __($reasonText) }}
      @if($slipPending)
        {{ __("Your transfer slip is still being checked by the treasurer; don't pay again.") }}
      @elseif($several)
        {{ \App\Support\MailText::bold('The fees below are **unpaid** again.') }}
      @else
        {{ \App\Support\MailText::bold('This fee is **unpaid** again.') }}
      @endif
    </p>

    <div class="details">
      <table>
        @foreach($fees as $fee)
        <tr>
          <td>{{ \App\Support\UiLang::date($fee->period_start) }} &ndash; {{ \App\Support\UiLang::date($fee->period_end) }}</td>
          <td>RM {{ number_format($fee->amountSen() / 100, 2) }}</td>
        </tr>
        @endforeach
        @if($several)
        <tr>
          <td>{{ __('Total') }}</td>
          <td>RM {{ number_format($totalSen / 100, 2) }}</td>
        </tr>
        @endif
        <tr>
          <td>{{ __('Receipt cancelled') }}</td>
          <td>{{ $receiptNo }}</td>
        </tr>
      </table>
    </div>

    @unless($slipPending)
    <p>{{ __("You can pay again as usual and you will get a new receipt with a new number. If money already left your account and was not refunded, don't pay again: contact the treasurer.") }}</p>
    @endunless

    <div class="cta">
      <a href="{{ $several ? route('member.payments.index', $payment->club_id) : route('member.payments.invoice', $payment) }}">{{ __('View the fee') }}</a>
    </div>
  </div>
  <div class="footer">
    &copy; {{ date('Y') }} {{ config('app.name') }} &middot; {{ __('Automated email, please do not reply.') }}
  </div>
</div>
</body>
</html>
