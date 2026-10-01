<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $page->meta_title ?: $page->title }}</title>
    @if($page->meta_description)
        <meta name="description" content="{{ $page->meta_description }}">
    @endif
    @if($page->og_image_url)
        <meta property="og:image" content="{{ $page->og_image_url }}">
    @endif
    <meta property="og:title" content="{{ $page->meta_title ?: $page->title }}">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900 antialiased min-h-screen flex flex-col justify-between">
    <header class="bg-white border-b border-slate-200 py-4">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 flex justify-between items-center">
            <div class="text-xl font-bold tracking-tight text-slate-900">
                {{ config('app.name', 'Focal') }}
            </div>
        </div>
    </header>

    <main class="max-w-4xl mx-auto px-4 sm:px-6 py-12 flex-grow">
        @if(session('success'))
            <div class="mb-8 rounded-md bg-emerald-50 p-4 border border-emerald-200">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-emerald-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-emerald-800">{{ session('success') }}</p>
                    </div>
                </div>
            </div>
        @endif

        <div class="text-center mb-10">
            <h1 class="text-4xl font-extrabold text-slate-900 sm:text-5xl">
                {{ $page->headline ?: $page->title }}
            </h1>
            @if($page->subheadline)
                <p class="mt-4 text-xl text-slate-600 max-w-2xl mx-auto">
                    {{ $page->subheadline }}
                </p>
            @endif
        </div>

        @if($page->body_content)
            <div class="prose prose-slate max-w-none mb-10 bg-white p-8 rounded-xl shadow-sm border border-slate-100">
                {!! $page->body_content !!}
            </div>
        @endif

        @if($page->form)
            <div class="bg-white p-8 rounded-xl shadow-sm border border-slate-200 max-w-xl mx-auto">
                <h3 class="text-xl font-semibold mb-6 text-slate-800">{{ $page->form->title }}</h3>

                <form method="POST" action="{{ route('focal.marketing.landing-pages.submit', $page->slug) }}" class="space-y-4">
                    @csrf
                    @foreach($page->form->fields_schema as $field)
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">
                                {{ $field['label'] }} @if(!empty($field['required'])) <span class="text-red-500">*</span> @endif
                            </label>
                            @if(($field['type'] ?? 'text') === 'textarea')
                                <textarea name="{{ $field['name'] }}" rows="3" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none" @if(!empty($field['required'])) required @endif></textarea>
                            @else
                                <input type="{{ $field['type'] ?? 'text' }}" name="{{ $field['name'] }}" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none" @if(!empty($field['required'])) required @endif>
                            @endif
                        </div>
                    @endforeach

                    <!-- SMS Consent Checkbox -->
                    <div class="flex items-start pt-2">
                        <div class="flex items-center h-5">
                            <input id="sms_consent" name="sms_consent" type="checkbox" value="1" class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded">
                        </div>
                        <div class="ml-3 text-sm">
                            <label for="sms_consent" class="text-xs text-slate-500">I agree to receive text messages regarding product updates and special events. Msg &amp; data rates may apply.</label>
                        </div>
                    </div>

                    <button type="submit" class="w-full mt-4 bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2.5 px-4 rounded-md transition shadow">
                        {{ $page->form->submit_button_text ?: 'Submit' }}
                    </button>
                </form>
            </div>
        @endif
    </main>

    <footer class="bg-white border-t border-slate-200 py-6 text-center text-xs text-slate-500">
        &copy; {{ date('Y') }} {{ config('app.name', 'Focal') }}. All rights reserved.
    </footer>

    <!-- First-Party Web Inbound Tracking -->
    <script src="{{ route('focal.marketing.track.script') }}"></script>
</body>
</html>
