<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fail terlalu besar / Upload too large</title>
</head>
{{-- PostTooLargeException (S1): the request body was over PHP's post_max_size, so nothing was saved. Bilingual, no scripts; style attributes only (CSP). --}}
<body style="margin:0;font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:#f6f5fb;color:#1f1d2b">
<main style="max-width:32rem;margin:10vh auto;padding:2rem 1.5rem;background:#fff;border-radius:1rem;box-shadow:0 1px 3px rgba(0,0,0,.08)">
  <h1 style="font-size:1.35rem;margin:0 0 .75rem">Fail terlalu besar</h1>
  <p style="font-size:1rem;line-height:1.5;margin:0 0 1rem">
    Fail yang dihantar terlalu besar, jadi tiada apa-apa disimpan. Setiap fail mesti 10 MB atau kurang.
    Kembali dan cuba lagi dengan fail yang lebih kecil (contohnya tangkapan skrin slip, bukan foto resolusi penuh).
  </p>
  <hr style="border:0;border-top:1px solid #e5e3ef;margin:1.25rem 0">
  <h2 style="font-size:1.1rem;margin:0 0 .5rem;color:#5b5872">Upload too large</h2>
  <p style="font-size:.95rem;line-height:1.5;margin:0 0 1.25rem;color:#5b5872">
    What you sent was too large, so nothing was saved. Each file must be 10 MB or smaller.
    Go back and try again with smaller files (for example a screenshot of the slip, not a full-size photo).
  </p>
  <a href="{{ url()->previous() }}" style="display:inline-block;padding:.75rem 1.5rem;border-radius:999px;background:#5b4bc4;color:#fff;text-decoration:none;font-weight:600">Kembali / Go back</a>
</main>
</body>
</html>
