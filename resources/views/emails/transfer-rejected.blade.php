{{-- One language: the recipient's (sent under their locale; English sentences are the keys of lang/<code>.json). --}}
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
  .reason { background: #fff7ed; border: 1px solid #fdba74; border-radius: 6px; padding: 14px 16px; margin: 16px 0; font-size: 14px; color: #7c2d12; }
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
    <h1>{{ __('Transfer slip not accepted yet') }}</h1>
    <p style="margin:8px 0 0;opacity:0.9">{{ $payment->club->name }}</p>
  </div>
  <div class="body">
    <p>{{ \App\Support\MailText::greeting('Hello **:name**,', $payment->user->name) }}</p>
    <p>{{ \App\Support\MailText::bold('The treasurer checked your transfer slip for this fee but could not confirm it yet, so the fee is still **unpaid**.') }}</p>

    {{-- The admin's reason, as typed (any language). --}}
    <div class="reason"><strong>{{ __('Reason:') }}</strong><br>{{ $payment->rejection_reason }}</div>

    <div class="details">
      <table>
        @if($fees)
        @foreach($fees as $fee)
        <tr>
          <td>{{ \App\Support\UiLang::date($fee->period_start) }} &ndash; {{ \App\Support\UiLang::date($fee->period_end) }}</td>
          <td>RM {{ number_format($fee->amount, 2) }}</td>
        </tr>
        @endforeach
        <tr>
          <td>{{ __('Total') }}</td>
          <td>RM {{ number_format($fees->sum(fn ($f) => (float) $f->amount), 2) }}</td>
        </tr>
        @if($group)
        <tr>
          <td>{{ __('Reference') }}</td>
          <td>{{ $group->reference }}</td>
        </tr>
        @endif
        @else
        <tr>
          <td>{{ __('Period') }}</td>
          <td>{{ \App\Support\UiLang::date($payment->period_start) }} &ndash; {{ \App\Support\UiLang::date($payment->period_end) }}</td>
        </tr>
        <tr>
          <td>{{ __('Amount') }}</td>
          <td>RM {{ number_format($payment->amount, 2) }}</td>
        </tr>
        <tr>
          <td>{{ __('Reference') }}</td>
          <td>{{ $payment->transferReference() }}</td>
        </tr>
        @endif
      </table>
    </div>

    <p>{{ __("Please read the reason above, then send the right slip or pay online. If money already left your account, don't pay again: contact the treasurer.") }}</p>

    <div class="cta">
      <a href="{{ $fees ? route('member.payments.review-all', $payment->club_id) : route('member.payments.review', $payment) }}">{{ __('Review & pay') }}</a>
    </div>
  </div>
  <div class="footer">
    &copy; {{ date('Y') }} {{ config('app.name') }} &middot; {{ __('Automated email, please do not reply.') }}
  </div>
</div>
</body>
</html>
