<!DOCTYPE html>
<html>
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
  .en { color: #6c757d; font-size: 13px; }
  hr { border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0; }
  .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #6c757d; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>Slip pindahan belum diterima</h1>
    <p style="margin:8px 0 0;opacity:0.9">{{ $payment->club->name }}</p>
  </div>
  <div class="body">
    <p>Salam <strong>{{ $payment->user->name }}</strong>,</p>
    <p>Bendahari telah menyemak slip pindahan anda untuk yuran di bawah, tetapi belum dapat mengesahkannya. Yuran ini masih <strong>belum dibayar</strong>.</p>

    <div class="reason"><strong>Sebab / Reason:</strong><br>{{ $payment->rejection_reason }}</div>

    <div class="details">
      <table>
        <tr>
          <td>Tempoh / Period</td>
          <td>{{ $payment->period_start->format('d M Y') }} &ndash; {{ $payment->period_end->format('d M Y') }}</td>
        </tr>
        <tr>
          <td>Amaun / Amount</td>
          <td>RM {{ number_format($payment->amount, 2) }}</td>
        </tr>
        <tr>
          <td>Rujukan / Reference</td>
          <td>{{ $payment->transferReference() }}</td>
        </tr>
      </table>
    </div>

    <p>Sila semak sebab di atas, kemudian hantar slip yang betul atau bayar dalam talian. Jika wang telah keluar dari akaun anda, jangan bayar sekali lagi. Hubungi bendahari.</p>

    <div class="cta">
      <a href="{{ route('member.payments.review', $payment) }}">Semak &amp; bayar / Review &amp; pay</a>
    </div>

    <hr>
    <p class="en">The treasurer checked your transfer slip for this fee but could not confirm it yet, so the fee is still <strong>unpaid</strong>. Please read the reason above, then send the right slip or pay online. If money already left your account, don't pay again: contact the treasurer.</p>
  </div>
  <div class="footer">
    &copy; {{ date('Y') }} {{ config('app.name') }} &middot; E-mel automatik, jangan balas. / Automated email, please do not reply.
  </div>
</div>
</body>
</html>
