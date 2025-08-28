<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('messages.email_subject') }}</title>
</head>
<body>
    <p>{{ __('messages.email_intro', ['name' => $name]) }}</p>

    <pre style="white-space:pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;">{{ $bodyText }}</pre>

    <p>{{ __('messages.email_outro') }}</p>
</body>
</html>
