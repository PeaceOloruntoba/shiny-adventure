@php($title = __('messages.page_title'))
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
</head>
<body class="antialiased bg-gray-50 text-gray-900">
    <div class="min-h-screen flex flex-col">
        <header class="border-b bg-white">
            <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">{{ __('messages.hero_title') }}</h1>
                    <p class="text-gray-600">{{ __('messages.hero_subtitle') }}</p>
                </div>
                <div class="text-sm">
                    <a href="{{ route('home') }}" class="text-indigo-600 hover:underline">{{ __('Home') }}</a>
                </div>
            </div>
        </header>

        <main class="flex-1">
            <div class="max-w-5xl mx-auto px-4 py-8">
                @if (session('status'))
                    <div class="mb-6 rounded-md bg-green-50 text-green-800 border border-green-200 p-4">
                        {{ session('status') }}
                    </div>
                @endif

                <div class="bg-white rounded-lg shadow">
                    <div class="p-4 border-b font-medium">Your generated applications</div>
                    <div class="divide-y">
                        @forelse($applications as $app)
                            <div class="p-4 flex items-center justify-between gap-4">
                                <div class="min-w-0">
                                    <div class="font-semibold truncate flex items-center gap-2">
                                        <span>{{ $app->name }} <span class="text-gray-500">&lt;{{ $app->email }}&gt;</span></span>
                                        @php($status = data_get($app->meta, 'status', 'ready'))
                                        @if($status === 'processing')
                                            <span class="px-2 py-0.5 text-xs rounded-full bg-amber-100 text-amber-800">Processing…</span>
                                        @elseif($status === 'failed')
                                            <span class="px-2 py-0.5 text-xs rounded-full bg-rose-100 text-rose-800">Failed</span>
                                        @else
                                            <span class="px-2 py-0.5 text-xs rounded-full bg-emerald-100 text-emerald-800">Ready</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-gray-500">{{ $app->created_at->toDayDateTimeString() }}</div>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    @php($status = data_get($app->meta, 'status'))
                                    @php($canDownload = $status === 'ready')
                                    <a class="px-3 py-1.5 text-sm rounded-md border hover:bg-gray-50" href="{{ route('applications.preview', $app) }}">Preview</a>
                                    @if($canDownload && $app->docx_path)
                                        <a class="px-3 py-1.5 text-sm rounded-md border hover:bg-gray-50" href="{{ route('applications.download', [$app, 'docx']) }}">DOCX</a>
                                    @else
                                        <button class="px-3 py-1.5 text-sm rounded-md border text-gray-400 cursor-not-allowed" disabled>DOCX</button>
                                    @endif
                                    @php($pdfRel = data_get($app->meta, 'pdf_rel'))
                                    @if($canDownload && $pdfRel)
                                        <a class="px-3 py-1.5 text-sm rounded-md border hover:bg-gray-50" href="{{ route('applications.download', [$app, 'pdf']) }}">PDF</a>
                                    @else
                                        <button class="px-3 py-1.5 text-sm rounded-md border text-gray-400 cursor-not-allowed" disabled>PDF</button>
                                    @endif
                                    <form action="{{ route('applications.destroy', $app) }}" method="POST" onsubmit="return confirm('Delete this application?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="px-3 py-1.5 text-sm rounded-md border text-rose-700 hover:bg-rose-50">Delete</button>
                                    </form>
                                </div>
                            </div>
                        @empty
                            <div class="p-8 text-center text-gray-500">No applications yet.</div>
                        @endforelse
                    </div>
                </div>

                <div class="mt-6">{{ $applications->links() }}</div>
            </div>
        </main>

        <footer class="border-t bg-white">
            <div class="max-w-5xl mx-auto px-4 py-6 text-xs text-gray-500">
                {{ __('messages.footer_note') }}
            </div>
        </footer>
    </div>
</body>
</html>
