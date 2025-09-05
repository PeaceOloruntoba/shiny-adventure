<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Preview — {{ $application->name }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <style>
        body { font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji"; color: #111; margin: 0; background: #fff; }
        .bar { display:flex; align-items:center; justify-content:space-between; padding: 12px 16px; border-bottom: 1px solid #e5e7eb; position: sticky; top:0; background:#fff; }
        .btn { display:inline-block; padding: 8px 12px; font-size: 14px; border: 1px solid #d1d5db; border-radius: 8px; text-decoration: none; color: #111; background: #fff; }
        .btn:hover { background: #f9fafb; }
        .btn.primary { border-color:#111827; background:#111827; color:#fff; }
        .wrap { max-width: 1100px; margin: 24px auto; padding: 0 16px 48px; }
        .muted { color: #6b7280; font-size: 12px; margin-bottom: 8px; }
        .frame { width: 100%; height: 85vh; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 6px 24px rgba(0,0,0,0.06); }
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
        @php
            $tplPath = base_path('doc/Vorlage-Zander-Rohan-html.html');
        @endphp
        @if(is_file($tplPath))
            {!! file_get_contents($tplPath) !!}
        @else
            <div class="muted">Template file not found at doc/Vorlage-Zander-Rohan-html.html</div>
        @endif
    </div>
</body>
</html>
