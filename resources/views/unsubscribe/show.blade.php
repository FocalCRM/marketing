<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unsubscribe Preferences | Odden CRM</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full bg-white rounded-xl shadow-lg border border-slate-200 p-8 text-center">
        <h1 class="text-2xl font-bold text-slate-900 mb-2">Email Preferences</h1>
        <p class="text-slate-600 text-sm mb-6">
            Are you sure you want to unsubscribe <strong class="text-slate-900">{{ $recipient->email }}</strong> from future marketing broadcasts?
        </p>

        <form action="{{ route('odden.marketing.unsubscribe.process', $recipient->unsubscribe_token) }}" method="POST">
            @csrf
            <button
                type="submit"
                class="w-full py-2.5 px-4 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-lg shadow text-sm transition"
            >
                Confirm Unsubscribe
            </button>
        </form>

        <div class="mt-4">
            <a href="{{ url('/') }}" class="text-xs text-slate-500 hover:text-slate-700">Cancel & Return to Homepage</a>
        </div>
    </div>
</body>
</html>
