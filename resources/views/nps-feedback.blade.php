<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank You for Your Feedback | Odden</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900 antialiased min-h-screen flex flex-col justify-between">
    <header class="bg-white border-b border-slate-200 py-4">
        <div class="max-w-xl mx-auto px-4 flex justify-between items-center">
            <span class="text-xl font-bold tracking-tight text-slate-900">Odden</span>
            <span class="text-xs text-slate-500">Customer Feedback</span>
        </div>
    </header>

    <main class="max-w-xl mx-auto px-4 py-12 flex-grow w-full">
        <div class="bg-white p-8 rounded-xl shadow-sm border border-slate-200 text-center">
            @php
                $isPromoter = $response->score >= 9;
                $isDetractor = $response->score <= 6;
            @endphp

            <div class="w-12 h-12 rounded-full {{ $isPromoter ? 'bg-emerald-100 text-emerald-600' : ($isDetractor ? 'bg-rose-100 text-rose-600' : 'bg-amber-100 text-amber-600') }} flex items-center justify-center mx-auto mb-4 font-bold text-lg">
                {{ $response->score }}
            </div>

            <h1 class="text-2xl font-bold text-slate-900 mb-2">Thank you for your rating!</h1>
            <p class="text-sm text-slate-600 mb-6">
                Your score of <strong class="text-slate-900">{{ $response->score }} / 10</strong> has been recorded for <strong class="text-slate-800">{{ $survey->name }}</strong>.
            </p>

            @if (session('success'))
                <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-800 text-sm mb-6">
                    {{ session('success') }}
                </div>
            @else
                <form action="{{ route('odden.marketing.nps.feedback', ['token' => $response->token]) }}" method="POST" class="text-left mt-6 pt-6 border-t border-slate-100">
                    @csrf
                    <label for="feedback" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">
                        What was the primary reason for your score? (Optional)
                    </label>
                    <textarea
                        id="feedback"
                        name="feedback"
                        rows="3"
                        placeholder="Tell us what you love or what we could improve..."
                        class="w-full text-sm rounded-lg border-slate-300 focus:ring-sky-500 focus:border-sky-500 p-3 mb-4"
                    >{{ old('feedback', $response->feedback) }}</textarea>

                    <button
                        type="submit"
                        class="w-full bg-slate-900 hover:bg-slate-800 text-white font-medium py-2 px-4 rounded-lg transition shadow-sm text-sm"
                    >
                        Submit Additional Comments
                    </button>
                </form>
            @endif
        </div>
    </main>

    <footer class="bg-white border-t border-slate-200 py-6 text-center text-xs text-slate-500">
        &copy; 2026 Odden. All rights reserved.
    </footer>
</body>
</html>
