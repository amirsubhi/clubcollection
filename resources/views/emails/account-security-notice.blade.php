<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
  .container { max-width: 580px; margin: 0 auto; background: #fff; border-radius: 8px; overflow: hidden; }
  .header { background: #5b4bc4; color: #fff; padding: 26px 30px; }
  .header h1 { margin: 0; font-size: 20px; }
  .body { padding: 26px 30px; font-size: 15px; color: #1f2937; line-height: 1.6; }
  .lang { border-top: 1px solid #e5e7eb; margin-top: 22px; padding-top: 18px; }
  .cta { margin: 20px 0 8px; }
  .cta a { background: #5b4bc4; color: #fff; text-decoration: none; padding: 12px 26px; border-radius: 6px; font-weight: bold; display: inline-block; }
  .footer { background: #f8f9fa; padding: 18px 30px; font-size: 12px; color: #6c757d; }
</style>
</head>
<body>
<div class="container">
  @if ($kind === \App\Mail\AccountSecurityNotice::KIND_SIGN_IN_PAUSED)
    <div class="header">
      <h1>Cubaan log masuk ke akaun anda</h1>
      <p style="margin:6px 0 0;opacity:0.9">Sign-in attempts on your account</p>
    </div>
    <div class="body">
      <p>Salam {{ $user->name }},</p>
      <p>Seseorang telah beberapa kali cuba log masuk ke akaun {{ config('app.name') }} anda dengan kata laluan yang salah. Untuk melindungi akaun anda, log masuk dihentikan seketika selama {{ $minutes }} minit.</p>
      <p>Jika itu anda, tiada apa yang perlu dibuat: tunggu sebentar dan cuba lagi, atau tetapkan semula kata laluan anda sekarang.</p>
      <p><strong>Jika itu bukan anda,</strong> tetapkan semula kata laluan anda dan beritahu pentadbir kelab anda.</p>
      <div class="cta"><a href="{{ $resetUrl }}">Tetapkan semula kata laluan</a></div>

      <div class="lang" lang="en">
        <p>Hello {{ $user->name }},</p>
        <p>Someone tried several times to sign in to your {{ config('app.name') }} account with the wrong password. To protect your account, signing in is paused for {{ $minutes }} minutes.</p>
        <p>If it was you, there is nothing to do: wait a little and try again, or reset your password now.</p>
        <p><strong>If it was not you,</strong> reset your password and tell your club admin.</p>
        <div class="cta"><a href="{{ $resetUrl }}">Reset password</a></div>
      </div>
    </div>
  @else
    <div class="header">
      <h1>Kata laluan anda telah ditukar</h1>
      <p style="margin:6px 0 0;opacity:0.9">Your password was changed</p>
    </div>
    <div class="body">
      <p>Salam {{ $user->name }},</p>
      <p>Kata laluan akaun {{ config('app.name') }} anda baru sahaja ditukar ({{ now()->format('d/m/Y H:i') }}).</p>
      <p>Jika itu anda, tiada apa yang perlu dibuat.</p>
      <p><strong>Jika itu bukan anda,</strong> hubungi pentadbir kelab anda segera, dan tetapkan semula kata laluan anda.</p>
      <div class="cta"><a href="{{ $resetUrl }}">Tetapkan semula kata laluan</a></div>

      <div class="lang" lang="en">
        <p>Hello {{ $user->name }},</p>
        <p>The password of your {{ config('app.name') }} account was just changed ({{ now()->format('d/m/Y H:i') }}).</p>
        <p>If it was you, there is nothing to do.</p>
        <p><strong>If it was not you,</strong> contact your club admin straight away, and reset your password.</p>
        <div class="cta"><a href="{{ $resetUrl }}">Reset password</a></div>
      </div>
    </div>
  @endif
  <div class="footer">
    Ini e-mel keselamatan automatik. Kami tidak akan sekali-kali meminta kata laluan anda melalui e-mel. /
    This is an automatic security email. We will never ask for your password by email.
  </div>
</div>
</body>
</html>
