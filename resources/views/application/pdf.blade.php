<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 20px; margin-bottom: 0; }
        .muted { color: #666; font-size: 11px; }
        .section { margin-top: 12px; }
        .content p { margin: 0 0 8px; line-height: 1.45; }
    </style>
</head>
<body>
    <h1>{{ $name }}</h1>
    <div class="muted">{{ $date }}</div>

    <div class="section content">
        {!! $body !!}
    </div>
</body>
</html>
