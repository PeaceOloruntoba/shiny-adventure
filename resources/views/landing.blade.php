<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Shiny Adventure') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased bg-white text-gray-900">
<header class="border-b">
    <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
        <a href="/" class="font-semibold text-xl">{{ config('app.name', 'Shiny Adventure') }}</a>
        <nav class="space-x-4">
            @guest
                <a class="text-gray-700 hover:text-indigo-600" href="{{ route('login') }}">Login</a>
                <a class="px-3 py-2 rounded bg-indigo-600 text-white hover:bg-indigo-700" href="{{ route('register') }}">Sign up</a>
            @endguest
            @auth
                <a class="text-gray-700 hover:text-indigo-600" href="{{ route('applications.index') }}">My Applications</a>
                <a class="px-3 py-2 rounded bg-indigo-600 text-white hover:bg-indigo-700" href="{{ route('app') }}">Open App</a>
            @endauth
        </nav>
    </div>
</header>

<main>
    <section class="bg-gradient-to-br from-indigo-50 to-white">
        <div class="max-w-7xl mx-auto px-6 py-20 grid md:grid-cols-2 gap-10 items-center">
            <div>
                <h1 class="text-4xl md:text-5xl font-bold leading-tight">Land your next job faster with AI‑crafted applications</h1>
                <p class="mt-4 text-lg text-gray-600">Upload your resume and notes, and let our AI generate polished, personalized cover letters and application documents in seconds — ready to download as DOCX and email to recruiters.</p>
                <div class="mt-8 flex gap-4">
                    @guest
                        <a href="{{ route('register') }}" class="px-6 py-3 rounded-lg bg-indigo-600 text-white font-medium hover:bg-indigo-700">Get Started Free</a>
                        <a href="{{ route('login') }}" class="px-6 py-3 rounded-lg border border-gray-300 text-gray-800 hover:bg-gray-50">Log in</a>
                    @endguest
                    @auth
                        <a href="{{ route('app') }}" class="px-6 py-3 rounded-lg bg-indigo-600 text-white font-medium hover:bg-indigo-700">Open the App</a>
                        <a href="{{ route('applications.index') }}" class="px-6 py-3 rounded-lg border border-gray-300 text-gray-800 hover:bg-gray-50">My Applications</a>
                    @endauth
                </div>
                <p class="mt-3 text-sm text-gray-500">Multilingual support (EN/DE). Your history is saved. Download in DOCX/PDF soon.</p>
            </div>
            <div class="relative">
                <div class="absolute -inset-4 bg-indigo-100 blur-3xl opacity-40 rounded-3xl"></div>
                <div class="relative p-6 bg-white border rounded-2xl shadow-lg">
                    <div class="text-sm text-gray-500">Preview</div>
                    <div class="mt-3 space-y-2 text-sm">
                        <div class="h-3 w-5/6 bg-gray-200 rounded"></div>
                        <div class="h-3 w-2/3 bg-gray-200 rounded"></div>
                        <div class="h-3 w-3/4 bg-gray-200 rounded"></div>
                        <div class="h-3 w-1/2 bg-gray-200 rounded"></div>
                    </div>
                    <div class="mt-5 flex gap-2">
                        <span class="px-2 py-1 text-xs rounded bg-emerald-100 text-emerald-700">AI Generated</span>
                        <span class="px-2 py-1 text-xs rounded bg-indigo-100 text-indigo-700">DOCX</span>
                        <span class="px-2 py-1 text-xs rounded bg-amber-100 text-amber-700">History</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-16 border-t">
        <div class="max-w-7xl mx-auto px-6 grid md:grid-cols-3 gap-8">
            <div class="p-6 rounded-xl border">
                <h3 class="font-semibold text-lg">AI that fits your profile</h3>
                <p class="mt-2 text-sm text-gray-600">We tailor each application to your inputs and attachments for maximum relevance and impact.</p>
            </div>
            <div class="p-6 rounded-xl border">
                <h3 class="font-semibold text-lg">Your history, saved</h3>
                <p class="mt-2 text-sm text-gray-600">Access and download your previously generated documents anytime.</p>
            </div>
            <div class="p-6 rounded-xl border">
                <h3 class="font-semibold text-lg">Professional formats</h3>
                <p class="mt-2 text-sm text-gray-600">Download in DOCX with our template. PDF export coming soon.</p>
            </div>
        </div>
    </section>
</main>

<footer class="border-t">
    <div class="max-w-7xl mx-auto px-6 py-8 text-sm text-gray-500 flex items-center justify-between">
        <div>© {{ date('Y') }} {{ config('app.name', 'Shiny Adventure') }}</div>
        <div class="space-x-4">
            <a href="{{ route('billing.index') }}" class="hover:text-gray-700">Pricing</a>
            <a href="#" class="hover:text-gray-700">Contact</a>
        </div>
    </div>
</footer>
</body>
</html>
