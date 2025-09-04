<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        /* Ensure A4 sizing for Dompdf */
        @page { size: A4; margin: 20mm; }
        html, body { height: 100%; }
        body { margin: 0; font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #111; }

        .page {
            width: 170mm; /* 210mm - 2*20mm margins */
            min-height: 257mm; /* 297mm - 2*20mm margins */
            margin: 0 auto;
        }

        .header {
            height: 40mm;
            border-radius: 6px;
            /* Default gradient header background. If you have a header image, replace background with background-image:url(...) */
            background: linear-gradient(135deg, #1f2937 0%, #111827 60%, #0b1220 100%);
            position: relative;
            margin-bottom: 10mm;
        }
        .header-inner { position:absolute; inset: 0; padding: 10mm; color: #fff; }
        .header-title { font-size: 16px; font-weight: 700; margin: 0; }
        .header-sub { font-size: 12px; opacity: .9; margin-top: 2mm; }

        .meta { color:#555; font-size:11px; margin-bottom: 4mm; }
        .subject { font-weight: 700; font-size: 14px; margin: 0 0 4mm 0; }
        .content p { margin: 0 0 4mm; line-height: 1.55; }

        .signature {
            margin-top: 10mm;
            padding-top: 6mm;
            border-top: 1px solid #e5e7eb;
        }
        .signature-space {
            height: 18mm; /* space to sign in printed PDF */
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="header" @if(!empty($headerBg)) style="background-image:url('{{ $headerBg }}'); background-size:cover; background-position:center;" @endif>
            <div class="header-inner">
                <h1 class="header-title">{{ $name }}</h1>
                <div class="header-sub">{{ $date }}</div>
            </div>
        </div>

        <div class="meta">Generated cover letter</div>
        <div class="content">
            {!! $body !!}
        </div>

        <div class="signature">
            <div class="signature-space"></div>
            <div><strong>{{ $name }}</strong></div>
        </div>
    </div>
</body>
</html>
