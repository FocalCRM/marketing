<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank You | {{ $form->title }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full bg-white rounded-xl shadow-lg border border-slate-200 p-8 text-center">
        <div class="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
        </div>

        <h1 class="text-2xl font-bold text-slate-900 mb-2">Thank You!</h1>
        <p class="text-slate-600 mb-6">
            {{ $form->success_message ?: 'Your submission has been received. Our team will follow up with you shortly.' }}
        </p>

        <a href="{{ url('/') }}" class="inline-block px-4 py-2 bg-slate-900 hover:bg-slate-800 text-white font-medium rounded-lg text-sm transition">
            Return to Homepage
        </a>
    </div>
</body>
</html>
