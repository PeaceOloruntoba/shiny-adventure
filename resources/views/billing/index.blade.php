<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Billing</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased bg-gray-50 text-gray-900">
<div class="max-w-2xl mx-auto p-6 space-y-6">
    <h1 class="text-2xl font-semibold">Billing (Placeholder)</h1>
    <p class="text-gray-600">Choose between subscription or pay-per-use. This is a placeholder screen; payments are not enforced yet.</p>

    @if(session('status'))
        <div class="p-3 rounded bg-green-100 text-green-800">{{ session('status') }}</div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="p-4 bg-white rounded shadow">
            <h2 class="text-lg font-medium">Monthly Subscription</h2>
            <p class="text-sm text-gray-600">Unlimited generations for a flat fee.</p>
            <form class="mt-3" method="POST" action="{{ route('billing.checkout') }}">
                @csrf
                <input type="hidden" name="plan" value="subscription">
                <button class="px-4 py-2 bg-indigo-600 text-white rounded">Subscribe</button>
            </form>
        </div>
        <div class="p-4 bg-white rounded shadow">
            <h2 class="text-lg font-medium">Pay Per Use</h2>
            <p class="text-sm text-gray-600">Pay only for what you generate.</p>
            <form class="mt-3" method="POST" action="{{ route('billing.checkout') }}">
                @csrf
                <input type="hidden" name="plan" value="ppu">
                <button class="px-4 py-2 bg-emerald-600 text-white rounded">Proceed</button>
            </form>
        </div>
    </div>

    <div>
        <a href="{{ route('home') }}" class="text-indigo-600 hover:underline">← Back to App</a>
    </div>
</div>
</body>
</html>
