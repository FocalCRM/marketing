<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Confirmed | {{ config('app.name', 'Focal') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900 antialiased min-h-screen flex flex-col justify-between">
    <header class="bg-white border-b border-slate-200 py-4">
        <div class="max-w-xl mx-auto px-4 flex justify-between items-center">
            <span class="text-xl font-bold tracking-tight text-slate-900">{{ config('app.name', 'Focal') }}</span>
        </div>
    </header>

    <main class="max-w-xl mx-auto px-4 py-16 flex-grow w-full text-center">
        <div class="bg-white p-8 rounded-xl shadow-sm border border-slate-200">
            <div class="w-12 h-12 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mx-auto mb-4">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-slate-900 mb-2">Subscription Confirmed!</h1>
            <p class="text-sm text-slate-600 mb-6">
                Thank you for verifying <span class="font-medium text-slate-800">{{ $contact->email }}</span>. You are now officially subscribed to updates from {{ config('app.name', 'Focal') }}.
            </p>

            <a href="/" class="inline-block bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-6 rounded-md transition shadow text-sm">
                Return to Home
            </a>
        </div>
    </main>

    <footer class="bg-white border-t border-slate-200 py-6 text-center text-xs text-slate-500">
        &copy; {{ date('Y') }} {{ config('app.name', 'Focal') }}. All rights reserved.
    </footer>
</body>
</html>
