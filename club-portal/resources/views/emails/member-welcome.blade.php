<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
  .container { max-width: 580px; margin: 0 auto; background: #fff; border-radius: 8px; overflow: hidden; }
  .header { background: #0d6efd; color: #fff; padding: 30px; text-align: center; }
  .header h1 { margin: 0; font-size: 22px; }
  .body { padding: 30px; }
  .creds { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; margin: 20px 0; font-family: monospace; }
  .creds div { margin-bottom: 8px; font-size: 14px; }
  .creds .label { color: #6c757d; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px; }
  .creds .value { font-size: 16px; font-weight: bold; color: #212529; }
  .warning { background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 12px 16px; font-size: 13px; color: #856404; margin: 20px 0; }
  .cta { text-align: center; margin: 24px 0; }
  .cta a { background: #0d6efd; color: #fff; text-decoration: none; padding: 12px 28px; border-radius: 6px; font-weight: bold; display: inline-block; }
  .footer { background: #f8f9fa; padding: 16px; text-align: center; font-size: 12px; color: #6c757d; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>Welcome to {{ $club->name }}</h1>
    <p style="margin:6px 0 0;opacity:0.85">Your account has been created</p>
  </div>
  <div class="body">
    <p>Hi <strong>{{ $member->name }}</strong>,</p>
    <p>An administrator has created your account on <strong>{{ config('app.name') }}</strong>. You can now log in and manage your club membership fees.</p>

    <div class="creds">
      <div>
        <div class="label">Email</div>
        <div class="value">{{ $member->email }}</div>
      </div>
      <div style="margin-top:14px">
        <div class="label">Temporary Password</div>
        <div class="value">{{ $temporaryPassword }}</div>
      </div>
    </div>

    <div class="warning">
      <strong>&#9888; Important:</strong> This is a temporary password. Please change it immediately after your first login.
    </div>

    <div class="cta">
      <a href="{{ route('login') }}">Login Now</a>
    </div>

    <p style="font-size:13px;color:#6c757d">
      If you did not expect this email, please contact your club administrator.
    </p>
  </div>
  <div class="footer">
    &copy; {{ date('Y') }} {{ config('app.name') }} &middot; Do not reply to this email.
  </div>
</div>
</body>
</html>
