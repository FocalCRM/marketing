<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $form->title }} | Focal CRM</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-xl w-full bg-white rounded-xl shadow-lg border border-slate-200 overflow-hidden">
        <div class="p-6 bg-slate-900 text-white">
            <h1 class="text-2xl font-bold">{{ $form->title }}</h1>
            @if ($form->description)
                <p class="text-slate-300 text-sm mt-1">{{ $form->description }}</p>
            @endif
        </div>

        <form action="{{ route('focal.marketing.forms.submit', $form->slug) }}" method="POST" class="p-6 space-y-4">
            @csrf

            @if (isset($contact) && $contact !== null)
                <input type="hidden" name="contact" value="{{ $contactToken }}" />
                <div class="p-3 bg-sky-50 border border-sky-200 text-sky-900 rounded-lg text-xs flex justify-between items-center">
                    <span>Welcome back, <strong>{{ $contact->first_name ?: $contact->email }}</strong>!</span>
                    <a href="{{ route('focal.marketing.forms.show', $form->slug) }}" class="underline text-sky-700 hover:text-sky-900">Not you?</a>
                </div>
            @endif

            @if ($errors->any())
                <div class="p-4 bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg space-y-1">
                    @foreach ($errors->all() as $error)
                        <div>• {{ $error }}</div>
                    @endforeach
                </div>
            @endif

            @php
                $activeFields = $fields ?? $form->fields_schema ?? [];
            @endphp

            @foreach ($activeFields as $field)
                @php
                    $name = $field['name'] ?? '';
                    $label = $field['label'] ?? ucfirst(str_replace('_', ' ', $name));
                    $type = $field['type'] ?? 'text';
                    $required = !empty($field['required']);
                @endphp

                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label for="{{ $name }}" class="block text-sm font-semibold text-slate-700">
                            {{ $label }}
                            @if ($required)
                                <span class="text-red-500">*</span>
                            @endif
                        </label>
                        @if (!empty($field['is_progressive']))
                            <span class="text-[10px] font-semibold uppercase tracking-wider text-sky-600 bg-sky-50 px-2 py-0.5 rounded border border-sky-200">
                                Smart Question
                            </span>
                        @endif
                    </div>

                    @if ($type === 'textarea')
                        <textarea
                            id="{{ $name }}"
                            name="{{ $name }}"
                            rows="4"
                            {{ $required ? 'required' : '' }}
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-sky-500"
                        >{{ old($name) }}</textarea>
                    @elseif ($type === 'select')
                        <select
                            id="{{ $name }}"
                            name="{{ $name }}"
                            {{ $required ? 'required' : '' }}
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-sky-500"
                        >
                            <option value="">Select option...</option>
                            @foreach ($field['options'] ?? [] as $opt)
                                <option value="{{ $opt }}" @selected(old($name) === $opt)>{{ $opt }}</option>
                            @endforeach
                        </select>
                    @else
                        <input
                            type="{{ $type }}"
                            id="{{ $name }}"
                            name="{{ $name }}"
                            value="{{ old($name) }}"
                            {{ $required ? 'required' : '' }}
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-sky-500"
                        />
                    @endif
                </div>
            @endforeach

            <div class="pt-4">
                <button
                    type="submit"
                    class="w-full py-2.5 px-4 bg-sky-600 hover:bg-sky-700 text-white font-semibold rounded-lg shadow transition"
                >
                    {{ $form->submit_button_text ?: 'Submit' }}
                </button>
            </div>
        </form>
    </div>
</body>
</html>
