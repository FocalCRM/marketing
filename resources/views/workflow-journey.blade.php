<div class="flex flex-col gap-6 p-2">
    {{-- Header Stats Bar --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 bg-slate-50 dark:bg-slate-900 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
        <div>
            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Trigger Type</div>
            <div class="text-sm font-bold text-slate-800 dark:text-slate-200 truncate mt-0.5">
                {{ $workflow->trigger_type->label() }}
            </div>
        </div>
        <div>
            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Total Enrolled</div>
            <div class="text-lg font-extrabold text-sky-600 dark:text-sky-400">
                {{ number_format($workflow->enrollments_count) }}
            </div>
        </div>
        <div>
            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Completed</div>
            <div class="text-lg font-extrabold text-emerald-600 dark:text-emerald-400">
                {{ number_format($workflow->completed_count) }}
            </div>
        </div>
        <div>
            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Completion Rate</div>
            <div class="text-lg font-extrabold text-indigo-600 dark:text-indigo-400">
                {{ $workflow->enrollments_count > 0 ? round(($workflow->completed_count / $workflow->enrollments_count) * 100, 1) : 0 }}%
            </div>
        </div>
    </div>

    {{-- Interactive Flowchart Canvas --}}
    <div class="flex flex-col items-center py-4 bg-slate-100/70 dark:bg-slate-950 p-6 rounded-2xl border border-slate-200 dark:border-slate-800 min-h-[400px]">
        {{-- Trigger Node --}}
        <div class="w-full max-w-md bg-white dark:bg-slate-900 rounded-xl p-4 shadow-sm border-2 border-sky-500 flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-sky-50 dark:bg-sky-950/60 border border-sky-200 dark:border-sky-800 flex items-center justify-center shrink-0">
                <x-filament::icon icon="heroicon-m-bolt" class="w-5 h-5 text-sky-600 dark:text-sky-400" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-[10px] font-bold uppercase tracking-wider text-sky-600 dark:text-sky-400">Workflow Trigger</div>
                <div class="text-xs font-bold text-slate-900 dark:text-white truncate">{{ $workflow->trigger_type->label() }}</div>
                <div class="text-[11px] text-slate-500 truncate">
                    @if (!empty($workflow->trigger_config))
                        {{ collect($workflow->trigger_config)->map(fn ($v, $k) => "$k: $v")->implode(', ') }}
                    @else
                        Enrolls matching contacts automatically
                    @endif
                </div>
            </div>
        </div>

        {{-- Connecting Line --}}
        <div class="w-0.5 h-8 bg-slate-300 dark:bg-slate-700 relative">
            <div class="absolute -bottom-1 left-1/2 -translate-x-1/2 w-2 h-2 border-r-2 border-b-2 border-slate-400 dark:border-slate-600 rotate-45"></div>
        </div>

        {{-- Step Sequence --}}
        @forelse ($workflow->steps->sortBy('step_number') as $step)
            <div class="w-full max-w-md bg-white dark:bg-slate-900 rounded-xl p-4 shadow-sm border border-slate-200 dark:border-slate-800 flex flex-col gap-2 relative">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2.5">
                        <span class="w-6 h-6 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 text-xs font-bold flex items-center justify-center border border-slate-300 dark:border-slate-700">
                            {{ $step->step_number }}
                        </span>
                        <span class="text-xs font-bold text-slate-900 dark:text-slate-100">
                            {{ $step->type->label() }}
                        </span>
                    </div>
                    <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400 uppercase tracking-wider">
                        {{ $step->type->value }}
                    </span>
                </div>

                {{-- Step Details / Parameters --}}
                <div class="text-xs text-slate-600 dark:text-slate-400 pl-8 bg-slate-50 dark:bg-slate-800/50 p-2.5 rounded-lg border border-slate-100 dark:border-slate-800/80">
                    @if ($step->type->value === 'delay')
                        <span>⏱ Wait <strong>{{ $step->config['hours'] ?? ($step->config['days'] ? ($step->config['days'] * 24) : '24') }} hours</strong> before proceeding</span>
                    @elseif ($step->type->value === 'send_email')
                        <span>📧 Dispatch Email Template #{{ $step->config['template_id'] ?? 'Default' }}</span>
                    @elseif ($step->type->value === 'send_sms')
                        <span>💬 Send SMS: "{{ Str::limit($step->config['message'] ?? 'Reminder', 40) }}"</span>
                    @elseif ($step->type->value === 'condition')
                        <div class="flex flex-col gap-1">
                            <span>⚖️ Evaluate rule: {{ $step->config['rule'] ?? 'property check' }}</span>
                            <div class="flex items-center gap-2 mt-1">
                                <span class="text-[10px] bg-emerald-50 text-emerald-700 border border-emerald-200 px-1.5 py-0.5 rounded font-bold">
                                    True &rarr; Step {{ $step->next_step_on_true ?? ($step->step_number + 1) }}
                                </span>
                                <span class="text-[10px] bg-rose-50 text-rose-700 border border-rose-200 px-1.5 py-0.5 rounded font-bold">
                                    False &rarr; Step {{ $step->next_step_on_false ?? 'Exit' }}
                                </span>
                            </div>
                        </div>
                    @else
                        <span>Action: {{ collect($step->config ?? [])->map(fn ($v, $k) => "$k: $v")->implode(', ') ?: 'Standard execution' }}</span>
                    @endif
                </div>
            </div>

            {{-- Connecting Arrow --}}
            <div class="w-0.5 h-8 bg-slate-300 dark:bg-slate-700 relative">
                <div class="absolute -bottom-1 left-1/2 -translate-x-1/2 w-2 h-2 border-r-2 border-b-2 border-slate-400 dark:border-slate-600 rotate-45"></div>
            </div>
        @empty
            <div class="p-6 text-center text-xs text-slate-500 bg-white dark:bg-slate-900 rounded-xl border border-dashed border-slate-300 dark:border-slate-700 max-w-md w-full">
                No execution steps configured yet. Use the Steps tab to add actions, delays, and conditions.
            </div>

            <div class="w-0.5 h-8 bg-slate-300 dark:bg-slate-700 relative">
                <div class="absolute -bottom-1 left-1/2 -translate-x-1/2 w-2 h-2 border-r-2 border-b-2 border-slate-400 dark:border-slate-600 rotate-45"></div>
            </div>
        @endforelse

        {{-- Finish Node --}}
        <div class="w-full max-w-md bg-white dark:bg-slate-900 rounded-xl p-3 shadow-sm border-2 border-emerald-500 flex items-center justify-center gap-2">
            <x-filament::icon icon="heroicon-m-check-badge" class="w-5 h-5 text-emerald-500" />
            <span class="text-xs font-bold text-slate-800 dark:text-slate-200">Journey Completed (Goal Reached)</span>
        </div>
    </div>
</div>
