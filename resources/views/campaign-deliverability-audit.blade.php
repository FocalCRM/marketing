<div class="flex flex-col gap-4 p-2">
    {{-- Score Header Banner --}}
    <div class="flex items-center justify-between p-4 rounded-xl border {{ $audit['score'] >= 85 ? 'bg-emerald-50 border-emerald-200 dark:bg-emerald-950/30 dark:border-emerald-800' : ($audit['score'] >= 60 ? 'bg-amber-50 border-amber-200 dark:bg-amber-950/30 dark:border-amber-800' : 'bg-red-50 border-red-200 dark:bg-red-950/30 dark:border-red-800') }}">
        <div class="flex items-center gap-3">
            <div class="w-12 h-12 rounded-full flex items-center justify-center font-extrabold text-lg {{ $audit['score'] >= 85 ? 'bg-emerald-600 text-white' : ($audit['score'] >= 60 ? 'bg-amber-500 text-white' : 'bg-red-600 text-white') }}">
                {{ $audit['score'] }}
            </div>
            <div>
                <div class="text-xs font-bold uppercase tracking-wider text-slate-500">Deliverability Health Score</div>
                <div class="text-base font-extrabold text-slate-900 dark:text-white">
                    {{ $audit['rating'] }}
                </div>
            </div>
        </div>

        <div class="text-right text-xs text-slate-500">
            Pre-flight algorithm check
        </div>
    </div>

    {{-- Inspection Checklist --}}
    <div class="flex flex-col gap-2.5">
        @foreach ($audit['checks'] as $check)
            <div class="flex items-start gap-3 p-3 rounded-lg border {{ $check['passed'] ? 'bg-white border-slate-200 dark:bg-slate-900 dark:border-slate-800' : ($check['severity'] === 'danger' ? 'bg-red-50/60 border-red-200 dark:bg-red-950/20 dark:border-red-900' : 'bg-amber-50/60 border-amber-200 dark:bg-amber-950/20 dark:border-amber-900') }}">
                <div class="mt-0.5 shrink-0">
                    @if ($check['passed'])
                        <x-filament::icon icon="heroicon-m-check-circle" class="w-5 h-5 text-emerald-500" />
                    @elseif ($check['severity'] === 'danger')
                        <x-filament::icon icon="heroicon-m-x-circle" class="w-5 h-5 text-red-500" />
                    @else
                        <x-filament::icon icon="heroicon-m-exclamation-triangle" class="w-5 h-5 text-amber-500" />
                    @endif
                </div>

                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-900 dark:text-slate-100">{{ $check['name'] }}</span>
                        <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded {{ $check['passed'] ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200' : ($check['severity'] === 'danger' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' : 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200') }}">
                            {{ $check['passed'] ? 'Passed' : ($check['severity'] === 'danger' ? 'Critical' : 'Warning') }}
                        </span>
                    </div>
                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-0.5">
                        {{ $check['message'] }}
                    </p>
                </div>
            </div>
        @endforeach
    </div>
</div>
