@php
    $receiptNo = $cancellation->receipt_no ?? \App\Models\Payment::legacyReceiptNumber((int) $payment->id);
    $cancelledOn = $cancellation->created_at?->format('d M Y');
    $several = $fees->count() > 1;
    $totalSen = (int) $fees->sum(fn ($f) => $f->amountSen());
@endphp
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
    <h1>Bayaran dibatalkan</h1>
    <p style="margin:8px 0 0;opacity:0.9">{{ $payment->club->name }}</p>
  </div>
  <div class="body">
    <p>Salam <strong>{{ $payment->user->name }}</strong>,</p>
    <p>Resit <strong>{{ $receiptNo }}</strong> telah dibatalkan pada {{ $cancelledOn }}. {{ $wording[0] }} {{ $several ? 'Yuran di bawah' : 'Yuran ini' }} <strong>belum dibayar</strong>.</p>

    <div class="details">
      <table>
        @foreach($fees as $fee)
        <tr>
          <td>{{ $fee->period_start->format('d M Y') }} &ndash; {{ $fee->period_end->format('d M Y') }}</td>
          <td>RM {{ number_format($fee->amountSen() / 100, 2) }}</td>
        </tr>
        @endforeach
        @if($several)
        <tr>
          <td>Jumlah / Total</td>
          <td>RM {{ number_format($totalSen / 100, 2) }}</td>
        </tr>
        @endif
        <tr>
          <td>Resit dibatalkan / Receipt cancelled</td>
          <td>{{ $receiptNo }}</td>
        </tr>
      </table>
    </div>

    <p>Anda boleh membayar semula seperti biasa; resit baharu dengan nombor baharu akan dikeluarkan. Jika wang telah keluar dari akaun anda dan belum dikembalikan, jangan bayar sekali lagi: hubungi bendahari.</p>

    <div class="cta">
      <a href="{{ $several ? route('member.payments.index', $payment->club_id) : route('member.payments.invoice', $payment) }}">Lihat yuran / View the fee</a>
    </div>

    <hr>
    <p class="en">Receipt <strong>{{ $receiptNo }}</strong> was cancelled on {{ $cancelledOn }}. {{ $wording[1] }} {{ $several ? 'The fees above are' : 'This fee is' }} <strong>unpaid</strong> again. You can pay again as usual and you will get a new receipt with a new number. If money already left your account and was not refunded, don't pay again: contact the treasurer.</p>
  </div>
  <div class="footer">
    &copy; {{ date('Y') }} {{ config('app.name') }} &middot; E-mel automatik, jangan balas. / Automated email, please do not reply.
  </div>
</div>
</body>
</html>
