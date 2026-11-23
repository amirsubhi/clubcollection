{{-- One language: the recipient's (sent under their locale; English sentences are the keys of lang/<code>.json). --}}
@php
    $daysLate = max(1, (int) abs($payment->due_date->copy()->startOfDay()->diffInDays(now()->startOfDay())));
    $frequency = ['monthly' => __('Monthly'), 'quarterly' => __('Quarterly'), 'yearly' => __('Yearly')][$payment->frequency] ?? ucfirst((string) $payment->frequency);
    $dueOn = \App\Support\UiLang::date($payment->due_date);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
  .container { max-width: 580px; margin: 0 auto; background: #fff; border-radius: 8px; overflow: hidden; }
  .header { background: #dc3545; color: #fff; padding: 30px; text-align: center; }
  .header h1 { margin: 0; font-size: 22px; }
  .body { padding: 30px; }
  .amount { text-align: center; font-size: 36px; font-weight: bold; color: #dc3545; margin: 20px 0; }
  .days-overdue { text-align: center; background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 12px; margin: 16px 0; font-size: 14px; color: #856404; }
  .details { background: #f8f9fa; border-radius: 6px; padding: 16px; margin: 20px 0; }
  .details table { width: 100%; border-collapse: collapse; }
  .details td { padding: 6px 0; font-size: 14px; }
  .details td:first-child { color: #6c757d; width: 45%; }
  .details td:last-child { font-weight: 600; }
  .cta { text-align: center; margin: 28px 0; }
  .cta a { background: #dc3545; color: #fff; text-decoration: none; padding: 14px 32px; border-radius: 6px; font-weight: bold; font-size: 15px; display: inline-block; }
  .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #6c757d; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>&#9888; {{ __('Payment Overdue') }}</h1>
    <p style="margin:8px 0 0;opacity:0.9">{{ $payment->club->name }}</p>
  </div>
  <div class="body">
    <p>{{ \App\Support\MailText::greeting('Dear **:name**,', $payment->user->name) }}</p>
    <p>{{ \App\Support\MailText::bold('Your club membership fee payment is **overdue**. Please settle it as soon as possible to avoid any disruption to your membership.') }}</p>

    <div class="amount">RM {{ number_format($payment->amount, 2) }}</div>

    <div class="days-overdue">
      &#128197; {{ \App\Support\MailText::bold('Originally due on **:date**', ['date' => $dueOn]) }}
      &mdash; <strong>{{ $daysLate === 1 ? __('1 day overdue') : __(':count days overdue', ['count' => $daysLate]) }}</strong>
    </div>

    <div class="details">
      <table>
        <tr>
          <td>{{ __('Club') }}</td>
          <td>{{ $payment->club->name }}</td>
        </tr>
        <tr>
          <td>{{ __('Period') }}</td>
          <td>{{ \App\Support\UiLang::date($payment->period_start) }} &ndash; {{ \App\Support\UiLang::date($payment->period_end) }}</td>
        </tr>
        <tr>
          <td>{{ __('Payment Type') }}</td>
          <td>{{ $frequency }}</td>
        </tr>
        <tr>
          <td>{{ __('Due Date') }}</td>
          <td style="color:#dc3545">{{ $dueOn }}</td>
        </tr>
        <tr>
          <td>{{ __('Amount') }}</td>
          <td style="color:#dc3545">RM {{ number_format($payment->amount, 2) }}</td>
        </tr>
      </table>
    </div>

    <div class="cta">
      <a href="{{ route('member.payments.review', $payment) }}">{{ __('Pay Now') }}</a>
    </div>

    <p style="font-size:13px;color:#6c757d;text-align:center">
      {{ __('If you have already made this payment, please contact your club administrator.') }}
    </p>
  </div>
  <div class="footer">
    &copy; {{ date('Y') }} {{ config('app.name') }} &middot; {{ __('This is an automated reminder, please do not reply.') }}
  </div>
</div>
</body>
</html>
