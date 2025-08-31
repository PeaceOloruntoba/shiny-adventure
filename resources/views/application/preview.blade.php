<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Preview — {{ $application->name }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <style>
        body { font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji"; color: #111; margin: 0; }
        .bar { display:flex; align-items:center; justify-content:space-between; padding: 12px 16px; border-bottom: 1px solid #e5e7eb; position: sticky; top:0; background:#fff; }
        .btn { display:inline-block; padding: 8px 12px; font-size: 14px; border: 1px solid #d1d5db; border-radius: 8px; text-decoration: none; color: #111; background: #fff; }
        .btn:hover { background: #f9fafb; }
        .btn.primary { border-color:#111827; background:#111827; color:#fff; }
        .wrap { max-width: 900px; margin: 24px auto; padding: 0 16px 48px; }
        .muted { color: #6b7280; font-size: 12px; margin-bottom: 8px; }
        .doc .content p { margin: 0 0 10px; line-height: 1.6; }
        .frame { width: 100%; height: 80vh; border: 1px solid #e5e7eb; border-radius: 12px; }

        /* A4-like preview container to match PDF */
        .page { background:#fff; border:1px solid #e5e7eb; border-radius: 12px; padding: 20mm; width: 210mm; min-height: 297mm; margin: 0 auto; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .header { height: 40mm; border-radius: 6px; background: linear-gradient(135deg, #1f2937 0%, #111827 60%, #0b1220 100%); position: relative; margin-bottom: 10mm; }
        .header-inner { position:absolute; inset: 0; padding: 10mm; color:#fff; }
        .header-title { font-size: 16px; font-weight: 700; margin: 0; }
        .header-sub { font-size: 12px; opacity: .9; margin-top: 2mm; }
        .meta { color:#555; font-size:11px; margin-bottom: 4mm; }
        .content p { margin: 0 0 4mm; line-height: 1.55; }
        .signature { margin-top: 10mm; padding-top: 6mm; border-top: 1px solid #e5e7eb; }
        .signature-space { height: 18mm; }
        @media (prefers-color-scheme: dark) { body { background:#0b0f19; color:#e5e7eb } .doc{ background:#0f172a; border-color:#1f2937 } .btn{ border-color:#334155; color:#e5e7eb } .btn:hover{ background:#111827 } .btn.primary{ background:#2563eb; border-color:#2563eb } }
    </style>
</head>
<body>
    <div class="bar">
        <div>
            <strong>{{ $application->name }}</strong>
            <span class="muted">&middot; {{ $application->created_at->toDayDateTimeString() }}</span>
        </div>
        <div>
            <a class="btn" href="{{ route('applications.index') }}">Back</a>
            <a class="btn primary" href="{{ route('applications.pdf', $application) }}">Download PDF</a>
        </div>
    </div>
    <div class="wrap">
        @php($isFullHtml = stripos($body, '<html') !== false)
        @if($isFullHtml)
            <iframe class="frame" srcdoc="{!! htmlspecialchars($body, ENT_QUOTES) !!}"></iframe>
        @else
            <div class="page">
                <div class="header">
                    <div class="header-inner">
                        <h1 class="header-title">{{ $application->name }}</h1>
                        <div class="header-sub">{{ now()->format('Y-m-d') }}</div>
                    </div>
                </div>
                <div class="meta">Generated cover letter</div>
                <div class="content">{!! $body !!}</div>
                <div class="signature">
                    <div class="signature-space"></div>
                    <div><strong>{{ $application->name }}</strong></div>
                </div>
            </div>
        @endif
    </div>
</body>
</html>
