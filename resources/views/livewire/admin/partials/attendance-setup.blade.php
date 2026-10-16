{{-- First steps on the Attendance page (App\Support\Guides\AttendanceSetup): each ticks itself once it is done. $setup = ['steps', 'extras', 'done', 'total']. --}}
<section class="mb-4 rounded-xl border border-teal-200 bg-white shadow-sm" aria-labelledby="attendance-setup-title" data-tour="att-checklist">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-teal-100 bg-teal-50/60 px-4 py-3">
        <div>
            <h2 id="attendance-setup-title" class="!mb-0 text-sm font-semibold text-slate-900"><i class="fas fa-flag-checkered mr-1 text-teal-700" aria-hidden="true"></i>Getting started with Attendance</h2>
            <p class="!mb-0 text-xs text-slate-600">{{ $setup['done'] }} of {{ $setup['total'] }} done. Each step ticks itself once it is done.</p>
        </div>
        <div class="flex items-center gap-3">
            <div class="h-2 w-32 overflow-hidden rounded-full bg-teal-100" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $setup['total'] }}" aria-valuenow="{{ $setup['done'] }}" aria-label="Steps done">
                <div class="h-2 rounded-full bg-teal-600" style="width: {{ round($setup['done'] / $setup['total'] * 100) }}%"></div>
            </div>
            <button type="button" class="text-xs text-slate-500 underline hover:text-slate-700" wire:click="hideSetup" wire:confirm="Hide the getting-started list? The Set up menu still has everything in it.">Hide</button>
        </div>
    </div>
    <ol class="!mb-0 divide-y divide-slate-100 !pl-0">
        @foreach($setup['steps'] as $step)
            <li class="flex items-start gap-3 px-4 py-3" wire:key="setup-{{ $step['key'] }}">
                @if($step['done'])
                    <span class="mt-0.5 flex h-6 w-6 flex-none items-center justify-center rounded-full bg-emerald-100 text-emerald-700"><i class="fas fa-check text-xs" aria-hidden="true"></i><span class="sr-only">Done:</span></span>
                @else
                    <span class="mt-0.5 flex h-6 w-6 flex-none items-center justify-center rounded-full border border-slate-300 text-xs font-semibold text-slate-500">{{ $loop->iteration }}</span>
                @endif
                <div class="min-w-0 flex-1">
                    <p @class(['!mb-0 text-sm font-semibold', 'text-slate-400 line-through' => $step['done'], 'text-slate-900' => ! $step['done']])>{{ $step['title'] }}</p>
                    @unless($step['done'])<p class="!mb-0 text-xs text-slate-600">{{ $step['body'] }}</p>@endunless
                </div>
                @if(! $step['done'] && $step['action'])
                    <button type="button" class="ui-button ui-button-secondary !py-1 !text-xs flex-none" wire:click="{{ $step['action'] }}">{{ $step['button'] }}</button>
                @endif
            </li>
        @endforeach
    </ol>
    @if($setup['extras'])
        <details class="border-t border-slate-100" wire:ignore.self>
            <summary class="cursor-pointer px-4 py-2 text-xs font-semibold text-slate-600">Also worth setting up ({{ collect($setup['extras'])->where('done', false)->count() }} to do)</summary>
            <ul class="!mb-0 !pl-0 pb-2">
                @foreach($setup['extras'] as $extra)
                    <li class="flex items-center gap-3 px-4 py-1.5 text-sm" wire:key="setup-extra-{{ $extra['key'] }}">
                        <i @class(['fas w-4 text-center', 'fa-check text-emerald-600' => $extra['done'], 'fa-circle text-[6px] text-slate-300' => ! $extra['done']]) aria-hidden="true"></i>
                        <span class="min-w-0 flex-1"><strong @class(['font-medium', 'text-slate-400' => $extra['done'], 'text-slate-800' => ! $extra['done']])>{{ $extra['title'] }}</strong>@unless($extra['done'])<span class="text-xs text-slate-500"> · {{ $extra['body'] }}</span>@endunless</span>
                        @if(! $extra['done'] && $extra['action'])<button type="button" class="ui-button ui-button-secondary !py-0.5 !text-xs flex-none" wire:click="{{ $extra['action'] }}">{{ $extra['button'] }}</button>@endif
                        @if(! $extra['done'] && ($extra['url'] ?? null))<a href="{{ $extra['url'] }}" target="_blank" rel="noopener" class="ui-button ui-button-secondary !py-0.5 !text-xs flex-none">Open</a>@endif
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</section>
