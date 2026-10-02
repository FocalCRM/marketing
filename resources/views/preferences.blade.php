<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Preference Center | {{ config('app.name', 'Odden') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900 antialiased min-h-screen flex flex-col justify-between">
    <header class="bg-white border-b border-slate-200 py-4">
        <div class="max-w-xl mx-auto px-4 flex justify-between items-center">
            <span class="text-xl font-bold tracking-tight text-slate-900">{{ config('app.name', 'Odden') }}</span>
        </div>
    </header>

    <main class="max-w-xl mx-auto px-4 py-10 flex-grow w-full">
        @if(session('success'))
            <div class="mb-6 rounded-md bg-emerald-50 p-4 border border-emerald-200">
                <p class="text-sm font-medium text-emerald-800">{{ session('success') }}</p>
            </div>
        @endif

        <div class="bg-white p-8 rounded-xl shadow-sm border border-slate-200">
            <h1 class="text-2xl font-bold text-slate-900 mb-2">Communication Preferences</h1>
            <p class="text-sm text-slate-500 mb-6">
                Manage the topics and updates you receive from {{ config('app.name', 'Odden') }} for
                <span class="font-medium text-slate-800">{{ $contact?->email ?? 'your email' }}</span>.
            </p>

            <form method="POST" action="{{ route('odden.marketing.preferences.update', $token) }}" class="space-y-6">
                @csrf
                <div class="space-y-4 divide-y divide-slate-100">
                    @foreach($topics as $key => $topic)
                        <div class="pt-4 first:pt-0 flex items-start">
                            <div class="flex items-center h-5">
                                <input
                                    id="topic_{{ $key }}"
                                    name="topics[]"
                                    type="checkbox"
                                    value="{{ $key }}"
                                    @checked(in_array($key, $currentTopics, true) && !$isSuppressed)
                                    class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-slate-300 rounded"
                                >
                            </div>
                            <div class="ml-3 text-sm">
                                <label for="topic_{{ $key }}" class="font-medium text-slate-800">{{ $topic['name'] }}</label>
                                <p class="text-xs text-slate-500">{{ $topic['description'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="pt-6 border-t border-slate-200">
                    <div class="flex items-start mb-6">
                        <div class="flex items-center h-5">
                            <input
                                id="opt_out_all"
                                name="opt_out_all"
                                type="checkbox"
                                value="1"
                                @checked($isSuppressed)
                                class="h-4 w-4 text-red-600 focus:ring-red-500 border-slate-300 rounded"
                            >
                        </div>
                        <div class="ml-3 text-sm">
                            <label for="opt_out_all" class="font-medium text-red-700">Unsubscribe from all marketing communications</label>
                            <p class="text-xs text-slate-500">You will still receive critical account and transactional security notices.</p>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2.5 px-4 rounded-md transition shadow">
                        Save Preferences
                    </button>
                </div>
            </form>
        </div>
    </main>

    <footer class="bg-white border-t border-slate-200 py-6 text-center text-xs text-slate-500">
        &copy; {{ date('Y') }} {{ config('app.name', 'Odden') }}. All rights reserved.
    </footer>
</body>
</html>
