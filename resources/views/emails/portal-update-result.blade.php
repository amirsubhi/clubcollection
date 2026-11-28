<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
  .container { max-width: 580px; margin: 0 auto; background: #fff; border-radius: 8px; overflow: hidden; }
  .header { background: #5b4bc4; color: #fff; padding: 24px 30px; }
  .header h1 { margin: 0; font-size: 20px; }
  .body { padding: 24px 30px; font-size: 15px; line-height: 1.5; }
  pre { background: #f8f9fa; border-radius: 6px; padding: 12px; font-size: 12px; white-space: pre-wrap; word-break: break-word; }
  .footer { background: #f8f9fa; padding: 16px; text-align: center; font-size: 12px; color: #6c757d; }
</style>
</head>
<body>
<div class="container">
  <div class="header"><h1>{{ $text['title'] }}</h1></div>
  <div class="body">
    @foreach ($text['lines'] as $line)
      <p>{{ $line }}</p>
    @endforeach
    @if ($text['what_happened'])
      <p><strong>What happened:</strong> {{ $text['what_happened'] }}</p>
    @endif
    @if ($text['what_to_do'])
      <p><strong>What to do:</strong> {{ $text['what_to_do'] }}</p>
    @endif
    @if ($report !== '')
      <p><strong>Report</strong></p>
      <pre>{{ $report }}</pre>
    @endif
  </div>
  <div class="footer">{{ config('app.name') }}</div>
</div>
</body>
</html>
