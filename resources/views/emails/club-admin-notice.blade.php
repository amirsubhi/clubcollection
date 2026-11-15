<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
  .container { max-width: 580px; margin: 0 auto; background: #fff; border-radius: 8px; overflow: hidden; }
  .header { background: #5b4bc4; color: #fff; padding: 26px 30px; }
  .header h1 { margin: 0; font-size: 20px; }
  .body { padding: 26px 30px; font-size: 14px; color: #1f2937; line-height: 1.5; }
  .cta { margin: 24px 0 8px; }
  .cta a { background: #5b4bc4; color: #fff; text-decoration: none; padding: 12px 26px; border-radius: 6px; font-weight: bold; display: inline-block; }
  .footer { background: #f8f9fa; padding: 18px 30px; font-size: 12px; color: #6c757d; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>{{ $heading }}</h1>
    <p style="margin:6px 0 0;opacity:0.9">{{ $club->name }}</p>
  </div>
  <div class="body">
    @foreach($lines as $line)
      <p>{{ $line }}</p>
    @endforeach
    @if($actionUrl)
      <div class="cta"><a href="{{ $actionUrl }}">{{ $actionLabel ?? 'Open' }}</a></div>
    @endif
  </div>
  <div class="footer">
    You get this because you are an admin of {{ $club->name }}. If you did not expect this change,
    check the club's audit log and tell the super admin.
  </div>
</div>
</body>
</html>
