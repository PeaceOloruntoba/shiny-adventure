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
            <div class="max-w-3xl mx-auto px-4 py-4">
                <h1 class="text-xl font-semibold">{{ __('messages.hero_title') }}</h1>
                <p class="text-gray-600">{{ __('messages.hero_subtitle') }}</p>
            </div>
        </header>

        <main class="flex-1">
            <div class="max-w-3xl mx-auto px-4 py-8">
                @if (session('status'))
                    <div class="mb-6 rounded-md bg-green-50 text-green-800 border border-green-200 p-4">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mb-6 rounded-md bg-red-50 text-red-800 border border-red-200 p-4">
                        <ul class="list-disc list-inside space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form action="{{ route('application.generate') }}" method="POST" enctype="multipart/form-data" class="space-y-6 bg-white rounded-lg shadow p-6">
                    @csrf

                    <div>
                        <label for="name" class="block text-sm font-medium">{{ __('messages.form_name') }}</label>
                        <input id="name" name="name" type="text" required value="{{ old('name') }}" class="mt-1 block w-full rounded-md p-2 border border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium">{{ __('messages.form_email') }}</label>
                        <input id="email" name="email" type="email" required value="{{ old('email') }}" class="mt-1 block w-full rounded-md p-2 border border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>

                    <div>
                        <label for="notes" class="block text-sm font-medium">{{ __('messages.form_notes') }}</label>
                        <textarea id="notes" name="notes" rows="6" placeholder="{{ __('messages.form_notes_ph') }}" class="mt-1 block w-full rounded-md p-2 border border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old('notes') }}</textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-medium">{{ __('messages.form_images') }}</label>
                        <input type="file" name="images[]" accept="image/*" multiple class="mt-1 block w-full text-sm" />
                        <p class="text-xs text-gray-500 mt-1">{{ __('messages.form_images_help') }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium">{{ __('messages.form_files') }}</label>
                        <input type="file" name="files[]" multiple class="mt-1 block w-full text-sm" />
                        <p class="text-xs text-gray-500 mt-1">{{ __('messages.form_files_help') }}</p>
                    </div>

                    <div class="flex items-start">
                        <input id="agree" name="agree" type="checkbox" value="1" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" required />
                        <label for="agree" class="ml-2 text-sm text-gray-700">{!! __('messages.form_agree_html') !!}</label>
                    </div>

                    <div class="pt-2">
                        <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            {{ __('messages.form_submit') }}
                        </button>
                    </div>
                </form>
            </div>
        </main>

        <footer class="border-t bg-white">
            <div class="max-w-3xl mx-auto px-4 py-6 text-xs text-gray-500">
                {{ __('messages.footer_note') }}
            </div>
        </footer>
    </div>
</body>
</html>
