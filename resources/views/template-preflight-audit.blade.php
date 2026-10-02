@php
    $record = $getRecord();
    $slots = $get('slots') ?? ($record?->slots ?? []);
    $subject = $get('subject') ?? ($record?->subject ?? '');
    
    $result = null;
    if (class_exists(\Odden\MailBuilder\MailBuilder::class)) {
        if (!empty($slots) && is_array($slots)) {
            $result = \Odden\MailBuilder\MailBuilder::audit($slots, ['subject' => $subject]);
        } elseif ($record?->body_html) {
            $result = \Odden\MailBuilder\MailBuilder::audit($record->body_html, ['subject' => $subject]);
        }
    }
@endphp

@if ($result)
    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 flex flex-col gap-6">
        {{-- Header Summary Banner --}}
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between pb-5 border-b border-slate-100 dark:border-slate-800 gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <span class="text-2xl font-black {{ $result->score >= 85 ? 'text-emerald-600 dark:text-emerald-400' : ($result->score >= 60 ? 'text-amber-500' : 'text-rose-600') }}">
                        {{ $result->score }}%
                    </span>
                    <div>
                        <h3 class="text-base font-bold text-slate-900 dark:text-slate-100">
                            Deliverability & Compliance Health
                        </h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Audit against Google/Yahoo 2024 Bulk Sender rules, CAN-SPAM, and Gmail 102KB clipping.
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold {{ $result->status === 'healthy' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800' : ($result->status === 'needs_attention' ? 'bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-200 dark:border-amber-800' : 'bg-rose-50 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300 border border-rose-200 dark:border-rose-800') }}">
                    @if ($result->status === 'healthy')
                        <x-filament::icon icon="heroicon-m-check-badge" class="w-4 h-4 text-emerald-500" />
                        <span>Ready to Deliver</span>
                    @elseif ($result->status === 'needs_attention')
                        <x-filament::icon icon="heroicon-m-exclamation-triangle" class="w-4 h-4 text-amber-500" />
                        <span>Needs Optimization</span>
                    @else
                        <x-filament::icon icon="heroicon-m-x-circle" class="w-4 h-4 text-rose-500" />
                        <span>Critical Issues</span>
                    @endif
                </span>
                <span class="text-xs font-mono px-2.5 py-1 rounded bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                    {{ round($result->htmlSizeBytes / 1024, 1) }} KB Payload
                </span>
            </div>
        </div>

        {{-- Checks Checklist --}}
        <div class="space-y-3">
            @foreach ($result->checks as $check)
                <div class="flex items-start gap-3 p-3.5 rounded-lg border {{ $check->isPassing() ? 'border-slate-100 bg-slate-50/50 dark:border-slate-800/80 dark:bg-slate-800/30' : ($check->isWarning() ? 'border-amber-200/80 bg-amber-50/30 dark:border-amber-900/50 dark:bg-amber-950/20' : 'border-rose-200/80 bg-rose-50/30 dark:border-rose-900/50 dark:bg-rose-950/20') }}">
                    <div class="mt-0.5 shrink-0">
                        @if ($check->isPassing())
                            <x-filament::icon icon="heroicon-s-check-circle" class="w-5 h-5 text-emerald-500" />
                        @elseif ($check->isWarning())
                            <x-filament::icon icon="heroicon-s-exclamation-triangle" class="w-5 h-5 text-amber-500" />
                        @else
                            <x-filament::icon icon="heroicon-s-x-circle" class="w-5 h-5 text-rose-500" />
                        @endif
                    </div>
                    <div class="flex-1 text-xs">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-bold text-slate-800 dark:text-slate-200 text-sm">
                                {{ $check->title }}
                            </span>
                            <span class="text-[11px] font-semibold uppercase tracking-wider {{ $check->isPassing() ? 'text-emerald-600 dark:text-emerald-400' : ($check->isWarning() ? 'text-amber-600 dark:text-amber-400' : 'text-rose-600 dark:text-rose-400') }}">
                                {{ $check->status }}
                            </span>
                        </div>
                        <p class="text-slate-600 dark:text-slate-400 mt-0.5">
                            {{ $check->message }}
                        </p>
                        @if ($check->recommendation)
                            <p class="text-slate-500 dark:text-slate-400 mt-1 italic text-[11px]">
                                <strong>Tip:</strong> {{ $check->recommendation }}
                            </p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@else
    <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-slate-500 dark:border-slate-800 dark:bg-slate-900">
        <x-filament::icon icon="heroicon-o-shield-check" class="w-8 h-8 mx-auto text-slate-400 mb-2" />
        <p class="text-sm font-semibold text-slate-700 dark:text-slate-300">No Content to Audit</p>
        <p class="text-xs text-slate-400 mt-1">Add visual slots or subject text to see live deliverability scoring.</p>
    </div>
@endif
