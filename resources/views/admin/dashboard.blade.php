<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased bg-gray-50 text-gray-900">
<div class="max-w-5xl mx-auto p-6">
    <h1 class="text-2xl font-semibold mb-6">Admin Dashboard</h1>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="p-4 bg-white shadow rounded">
            <div class="text-sm text-gray-500">Users</div>
            <div class="text-3xl font-bold">{{ $stats['users'] }}</div>
        </div>
        <div class="p-4 bg-white shadow rounded">
            <div class="text-sm text-gray-500">Applications</div>
            <div class="text-3xl font-bold">{{ $stats['applications'] }}</div>
        </div>
        <div class="p-4 bg-white shadow rounded">
            <div class="text-sm text-gray-500">Earnings</div>
            <div class="text-3xl font-bold">€{{ number_format($stats['earnings_cents'] / 100, 2) }}</div>
        </div>
    </div>

    <div class="mt-8">
        <a href="{{ route('home') }}" class="text-indigo-600 hover:underline">← Back to App</a>
    </div>
</div>
</body>
</html>
