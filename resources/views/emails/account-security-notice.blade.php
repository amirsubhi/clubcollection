{{-- One language: the recipient's (sent under their locale; English sentences are the keys of lang/<code>.json). --}}
<!DOCTYPE html>
<html lang="{{ \App\Support\UiLang::htmlLang(app()->getLocale()) }}">
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
  .container { max-width: 580px; margin: 0 auto; background: #fff; border-radius: 8px; overflow: hidden; }
  .header { background: #5b4bc4; color: #fff; padding: 26px 30px; }
  .header h1 { margin: 0; font-size: 20px; }
  .body { padding: 26px 30px; font-size: 15px; color: #1f2937; line-height: 1.6; }
  .cta { margin: 20px 0 8px; }
  .cta a { background: #5b4bc4; color: #fff; text-decoration: none; padding: 12px 26px; border-radius: 6px; font-weight: bold; display: inline-block; }
  .footer { background: #f8f9fa; padding: 18px 30px; font-size: 12px; color: #6c757d; }
</style>
</head>
<body>
<div class="container">
  @if ($kind === \App\Mail\AccountSecurityNotice::KIND_SIGN_IN_PAUSED)
    <div class="header">
      <h1>{{ __('Sign-in attempts on your account') }}</h1>
    </div>
    <div class="body">
      <p>{{ \App\Support\MailText::greeting('Hello **:name**,', $user->name) }}</p>
      <p>{{ __('Someone tried several times to sign in to your :portal account with the wrong password. To protect your account, signing in is paused for :minutes minutes.', ['portal' => config('app.name'), 'minutes' => $minutes]) }}</p>
      <p>{{ __('If it was you, there is nothing to do: wait a little and try again, or reset your password now.') }}</p>
      <p>{{ \App\Support\MailText::bold('**If it was not you,** reset your password and tell your club admin.') }}</p>
      <div class="cta"><a href="{{ $resetUrl }}">{{ __('Reset password') }}</a></div>
    </div>
  @else
    <div class="header">
      <h1>{{ __('Your password was changed') }}</h1>
    </div>
    <div class="body">
      <p>{{ \App\Support\MailText::greeting('Hello **:name**,', $user->name) }}</p>
      <p>{{ __('The password of your :portal account was just changed (:time).', ['portal' => config('app.name'), 'time' => now()->format('d/m/Y H:i')]) }}</p>
      <p>{{ __('If it was you, there is nothing to do.') }}</p>
      <p>{{ \App\Support\MailText::bold('**If it was not you,** contact your club admin straight away, and reset your password.') }}</p>
      <div class="cta"><a href="{{ $resetUrl }}">{{ __('Reset password') }}</a></div>
    </div>
  @endif
  <div class="footer">
    {{ __('This is an automatic security email. We will never ask for your password by email.') }}
  </div>
</div>
</body>
</html>
