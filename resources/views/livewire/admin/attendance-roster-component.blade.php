{{-- Staff & Security → Attendance → Roster: a page of its own, opened in a new tab from Attendance. --}}
@php use App\Services\Attendance\AttendanceAnalyzer; @endphp
<div class="clinic-ui ui-page">
    <div class="w-full">
        @php
            $rosterDays = array_values($rosterGrid['days']);
            $month = $rosterSpan === 'month';
            $codes = $rosterGrid['codes'];
            $codeOf = collect($codes)->keyBy('name');
            $fileQuery = ['department' => $rosterUnit, 'from' => $rosterGrid['first']->format('Y-m-d'), 'to' => $rosterGrid['last']->format('Y-m-d')];
        @endphp
        {{-- Title on the left, what can be done with the roster on the right, on one line. --}}
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <a href="{{ route('admin.attendance') }}" class="text-xs font-medium text-teal-700 underline">‹ Attendance</a>
                <h1 class="!mb-0 text-xl font-semibold text-slate-900">Roster</h1>
                <p class="!mb-0 text-sm text-slate-500">Who works which shift, one department at a time. Click a day to change it.</p>
            </div>
            @if($shifts->isNotEmpty())
                <div class="flex flex-wrap items-center gap-2">
                    @if($hospital)
                        <button type="button" class="ui-button ui-button-secondary" wire:click="togglePattern"><i class="fas fa-sync-alt" aria-hidden="true"></i>Fill from a pattern</button>
                        <button type="button" class="ui-button ui-button-secondary" wire:click="toggleSwap"><i class="fas fa-exchange-alt" aria-hidden="true"></i>Swap two people</button>
                    @endif
                    <button type="button" class="ui-button ui-button-secondary" wire:click="$toggle('showRosterUpload')"><i class="fas fa-file-excel" aria-hidden="true"></i>Excel</button>
                    <a class="ui-button ui-button-secondary" href="{{ route('admin.attendance.roster', ['format' => 'pdf'] + $fileQuery) }}" target="_blank" rel="noopener"><i class="fas fa-print" aria-hidden="true"></i>Print</a>
                </div>
            @endif
        </div>
        <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
            {{-- Which department and which days, and finding someone: one row, all the same height. --}}
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 px-4 py-3">
                @if($rosterGrid['units'])
                    <select class="ui-input !w-56 !py-1.5 !text-sm font-semibold" wire:model.live="rosterUnit" aria-label="Department">
                        @foreach($rosterGrid['units'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                @endif
                <div class="inline-flex items-stretch overflow-hidden rounded-lg border border-slate-300 bg-white">
                    <button type="button" class="px-3 text-slate-600 hover:bg-slate-50" wire:click="shiftRosterWeek(-1)" aria-label="Earlier"><i class="fas fa-chevron-left text-xs" aria-hidden="true"></i></button>
                    <span class="border-x border-slate-200 px-3 py-1.5 text-center text-sm font-semibold text-slate-800" style="min-width:15.5rem">{{ $month ? $rosterGrid['first']->format('F Y') : $rosterDays[0]->format('j M').' – '.$rosterDays[6]->format('j M Y') }}</span>
                    <button type="button" class="px-3 text-slate-600 hover:bg-slate-50" wire:click="shiftRosterWeek(1)" aria-label="Later"><i class="fas fa-chevron-right text-xs" aria-hidden="true"></i></button>
                </div>
                <div class="inline-flex overflow-hidden rounded-lg border border-slate-300" role="group" aria-label="Span">
                    @foreach(['week' => 'Week', 'month' => 'Month'] as $key => $label)
                        <button type="button" wire:click="$set('rosterSpan', '{{ $key }}')" @class(['px-3 py-1.5 text-sm font-medium', 'bg-slate-800 text-white' => $rosterSpan === $key, 'bg-white text-slate-600 hover:bg-slate-50' => $rosterSpan !== $key]) aria-pressed="{{ $rosterSpan === $key ? 'true' : 'false' }}">{{ $label }}</button>
                    @endforeach
                </div>
                @if($search !== '' || count($rosterGrid['rows']) > 25)
                    <input type="search" class="ui-input !w-56 !py-1.5 !text-sm sm:ml-auto" wire:model.live.debounce.400ms="search" placeholder="Find by name or ID" aria-label="Find a person">
                @endif
            </div>
            <div class="attendance-roster space-y-3 p-4">
                @if($codes)
                    <div class="flex flex-wrap items-center gap-1.5 text-xs text-slate-600">
                        @foreach($codes as $code)<span class="rounded px-1.5 py-0.5 font-semibold {{ $code['class'] }}">{{ $code['code'] }}</span><span class="mr-2">{{ $code['name'] }}</span>@endforeach
                        <span class="rounded bg-slate-100 px-1.5 py-0.5 font-semibold text-slate-500">Off</span><span class="mr-2">Day off</span>
                        <span class="rounded bg-white px-1.5 py-0.5 text-slate-500 ring-1 ring-inset ring-slate-300">Hrs</span><span class="mr-2">Usual hours</span>
                        <span class="rounded bg-indigo-100 px-1.5 py-0.5 font-semibold text-indigo-800">Lv</span><span class="mr-2">On leave</span>
                        <span class="text-slate-500">A ringed box is set on the roster for that day. Click a box to change it.</span>
                    </div>
                @endif
                @if($showRosterUpload)
                    <div wire:key="roster-excel">
                    <div class="att-overlay" data-sheet-overlay x-data x-on:click="dismissLocal($el.nextElementSibling, $wire, { showRosterUpload: false })" aria-hidden="true"></div>
                    <aside class="att-drawer" data-sheet role="dialog" aria-modal="true" aria-label="Roster from Excel" tabindex="-1" x-data x-init="$el.focus()" x-on:keydown.escape.window="dismissLocal($el, $wire, { showRosterUpload: false })">
                        <header class="att-drawer-head"><h2>Roster from Excel</h2><button type="button" class="att-x" x-on:click="dismissLocal($el, $wire, { showRosterUpload: false })" aria-label="Close">&times;</button></header>
                        <div class="att-drawer-body">
                    <div class="space-y-2">
                        <p class="!mb-0 text-sm text-slate-600">
                            <a class="text-teal-700 underline" href="{{ route('admin.attendance.roster', ['format' => 'csv'] + $fileQuery) }}">Download {{ $month ? 'this month' : 'this week' }}{{ $rosterUnit !== '' && isset($rosterGrid['units'][$rosterUnit]) ? ' for '.$rosterGrid['units'][$rosterUnit] : '' }}</a>,
                            fill in each day with a code ({{ implode(', ', array_column($codes, 'code')) }}), OFF for a day off or USUAL for their usual days; leave a box empty to keep it. Then bring it back here. Days before today are not changed.
                        </p>
                        @unless($rosterPreview)
                            <form wire:submit="readRosterFile" class="flex flex-wrap items-center gap-2">
                                <input type="file" accept=".xlsx,.csv,text/csv" wire:model="rosterFile" class="text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200" aria-label="Roster file">
                                <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="readRosterFile,rosterFile">Read the file</x-ui.button>
                                @error('rosterFile')<p class="ui-error w-full" role="alert">{{ $message }}</p>@enderror
                            </form>
                        @else
                            <div class="rounded border border-slate-200 bg-white p-3 text-sm">
                                <p class="!mb-1"><strong>{{ count($rosterPreview['changes']) }}</strong> {{ count($rosterPreview['changes']) === 1 ? 'day changes' : 'days change' }} for <strong>{{ $rosterPreview['people'] }}</strong> {{ $rosterPreview['people'] === 1 ? 'person' : 'people' }}@if($rosterPreview['from']), {{ \Carbon\CarbonImmutable::parse($rosterPreview['from'])->format('j M') }} to {{ \Carbon\CarbonImmutable::parse($rosterPreview['to'])->format('j M Y') }}@endif.</p>
                                @foreach(array_slice($rosterPreview['problems'], 0, 15) as $problem)<p class="!mb-0 text-amber-800">{{ $problem }}</p>@endforeach
                                @if(count($rosterPreview['problems']) > 15)<p class="!mb-0 text-amber-800">And {{ count($rosterPreview['problems']) - 15 }} more.</p>@endif
                                @if($rosterPreview['changes'])
                                    <p class="!mb-0 mt-1 text-xs text-slate-500">For example: {{ collect($rosterPreview['changes'])->take(4)->map(fn ($change) => $change[1].' '.\Carbon\CarbonImmutable::parse($change[2])->format('D j M').': '.($change[3] === 'usual' ? 'as usual' : ($change[3] === 0 ? 'off' : ($codes[$change[3]]['name'] ?? '?'))))->implode('; ') }}</p>
                                @endif
                                <div class="mt-2 flex gap-2">
                                    @if($rosterPreview['changes'])<x-ui.button type="button" variant="primary" wire:click="applyRosterFile" wire:loading.attr="disabled">Make these changes</x-ui.button>@endif
                                    <x-ui.button type="button" wire:click="$set('rosterPreview', null)">Choose another file</x-ui.button>
                                </div>
                            </div>
                        @endunless
                    </div>
                        </div>
                    </aside>
                    </div>
                @endif
                @if($shifts->isEmpty())<p class="!mb-0 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">Add shifts first (Shifts button), then roster people onto them here.</p>@endif
                @if($showPattern)
                    <div wire:key="roster-pattern">
                    <div class="att-overlay" data-sheet-overlay x-data x-on:click="dismissLocal($el.nextElementSibling, $wire, { showPattern: false })" aria-hidden="true"></div>
                    <aside class="att-drawer" data-sheet role="dialog" aria-modal="true" aria-label="Fill from a rotation pattern" tabindex="-1" x-data x-init="$el.focus()" x-on:keydown.escape.window="dismissLocal($el, $wire, { showPattern: false })">
                        <header class="att-drawer-head"><h2>Fill from a rotation pattern</h2><button type="button" class="att-x" x-on:click="dismissLocal($el, $wire, { showPattern: false })" aria-label="Close">&times;</button></header>
                        <div class="att-drawer-body">
                    @php $shiftNames = $shifts->pluck('name', 'id'); @endphp
                    <div class="space-y-3">
                        <div class="flex flex-wrap items-end gap-2">
                            @if($rosterGrid['patterns']->isNotEmpty())
                                <div><label for="pattern-saved" class="mb-1 block text-xs font-semibold text-slate-600">Saved pattern</label>
                                    <select id="pattern-saved" class="ui-input !w-48 !py-1.5 !text-sm" wire:model.live="patternId"><option value="">New pattern</option>@foreach($rosterGrid['patterns'] as $pattern)<option value="{{ $pattern->id }}">{{ $pattern->name }}</option>@endforeach</select></div>
                            @endif
                            <div><label for="pattern-shift" class="mb-1 block text-xs font-semibold text-slate-600">Add to the cycle</label>
                                <select id="pattern-shift" class="ui-input !w-40 !py-1.5 !text-sm" wire:model="patternAddShift"><option value="">Choose</option>@foreach($shifts->where('is_active', true) as $shift)<option value="{{ $shift->id }}">{{ $shift->name }}</option>@endforeach<option value="0">Days off</option></select></div>
                            <div><label for="pattern-count" class="mb-1 block text-xs font-semibold text-slate-600">For how many days</label><input id="pattern-count" type="number" min="1" max="31" class="ui-input !w-24 !py-1.5 !text-sm" wire:model="patternAddCount"></div>
                            <x-ui.button type="button" wire:click="addPatternStep">Add</x-ui.button>
                            @if($patternDays)<x-ui.button type="button" wire:click="undoPatternStep">Undo last</x-ui.button>@endif
                        </div>
                        <div class="flex flex-wrap items-center gap-1 text-xs" aria-label="The cycle">
                            @forelse($patternDays as $i => $choice)
                                <span @class(['rounded px-1.5 py-0.5', 'bg-slate-200 text-slate-600' => $choice === '0', 'bg-white text-teal-900 ring-1 ring-inset ring-teal-300' => $choice !== '0']) wire:key="pattern-day-{{ $i }}">{{ $i + 1 }}. {{ $choice === '0' ? 'Off' : ($shiftNames[(int) $choice] ?? '?') }}</span>
                            @empty
                                <span class="text-slate-500">Nothing in the cycle yet. For example: Morning for 2 days, Afternoon for 2, Night for 2, days off for 2.</span>
                            @endforelse
                            @if($patternDays)<span class="ml-1 text-slate-500">then it repeats ({{ count($patternDays) }} days)</span>@endif
                        </div>
                        <div class="flex flex-wrap items-end gap-2">
                            <div><label for="pattern-from" class="mb-1 block text-xs font-semibold text-slate-600">First day</label><input id="pattern-from" type="date" class="ui-input !w-40 !py-1.5 !text-sm" wire:model="patternFrom"></div>
                            <div><label for="pattern-to" class="mb-1 block text-xs font-semibold text-slate-600">Last day</label><input id="pattern-to" type="date" class="ui-input !w-40 !py-1.5 !text-sm" wire:model="patternTo"></div>
                            <div><label for="pattern-stagger" class="mb-1 block text-xs font-semibold text-slate-600">Each next person starts this many days further in</label><input id="pattern-stagger" type="number" min="0" max="62" class="ui-input !w-24 !py-1.5 !text-sm" wire:model="patternStagger"></div>
                        </div>
                        <fieldset>
                            <legend class="mb-1 text-xs font-semibold text-slate-600">For these people (in name order)</legend>
                            <div class="flex max-h-32 flex-wrap gap-x-4 gap-y-1 overflow-y-auto rounded border border-slate-200 bg-white p-2">
                                @foreach($rosterGrid['rows'] as $row)
                                    <label class="flex items-center gap-1.5 text-sm text-slate-800" wire:key="pattern-person-{{ $row['staff']->id }}"><input type="checkbox" value="{{ $row['staff']->id }}" wire:model="patternPeople"> {{ $row['staff']->name }}</label>
                                @endforeach
                            </div>
                        </fieldset>
                        @foreach(['patternAddShift', 'patternAddCount', 'patternDays', 'patternFrom', 'patternTo', 'patternPeople', 'patternStagger', 'patternName'] as $field)@error($field)<p class="ui-error" role="alert">{{ $message }}</p>@enderror @endforeach
                        <div class="flex flex-wrap items-end justify-between gap-2">
                            <div class="flex flex-wrap items-end gap-2">
                                <div><label for="pattern-name" class="mb-1 block text-xs font-semibold text-slate-600">Keep this pattern as</label><input id="pattern-name" type="text" maxlength="60" class="ui-input !w-48 !py-1.5 !text-sm" wire:model="patternName" placeholder="e.g. Ward 2-2-2"></div>
                                <x-ui.button type="button" wire:click="savePattern">Save pattern</x-ui.button>
                                @if($patternId)<button type="button" class="pb-2 text-xs text-red-700 underline" wire:click="deletePattern" wire:confirm="Delete this saved pattern? Roster days already filled stay.">Delete pattern</button>@endif
                            </div>
                            <x-ui.button type="button" variant="primary" wire:click="applyPattern" wire:confirm="Fill the roster for the people ticked? Days already set for them in these dates are replaced." wire:loading.attr="disabled" wire:target="applyPattern">Fill the roster</x-ui.button>
                        </div>
                    </div>
                        </div>
                    </aside>
                    </div>
                @endif
                @if($showSwap)
                    <div wire:key="roster-swap">
                    <div class="att-overlay" data-sheet-overlay x-data x-on:click="dismissLocal($el.nextElementSibling, $wire, { showSwap: false })" aria-hidden="true"></div>
                    <aside class="att-drawer" data-sheet role="dialog" aria-modal="true" aria-label="Swap two people's shifts" tabindex="-1" x-data x-init="$el.focus()" x-on:keydown.escape.window="dismissLocal($el, $wire, { showSwap: false })">
                        <header class="att-drawer-head"><h2>Swap two people's shifts</h2><button type="button" class="att-x" x-on:click="dismissLocal($el, $wire, { showSwap: false })" aria-label="Close">&times;</button></header>
                        <div class="att-drawer-body">
                    <form wire:submit="swapRoster" class="flex flex-wrap items-end gap-2">
                        @foreach(['swapA' => 'This person', 'swapB' => 'With this person'] as $model => $label)
                            <div><label for="{{ $model }}" class="mb-1 block text-xs font-semibold text-slate-600">{{ $label }}</label>
                                <select id="{{ $model }}" class="ui-input !w-52 !py-1.5 !text-sm" wire:model="{{ $model }}"><option value="">Choose</option>@foreach($rosterGrid['rows'] as $row)<option value="{{ $row['staff']->id }}">{{ $row['staff']->name }}</option>@endforeach</select></div>
                        @endforeach
                        <div><label for="swap-from" class="mb-1 block text-xs font-semibold text-slate-600">Day</label><input id="swap-from" type="date" class="ui-input !w-40 !py-1.5 !text-sm" wire:model="swapFrom"></div>
                        <div><label for="swap-to" class="mb-1 block text-xs font-semibold text-slate-600">Last day (if more than one)</label><input id="swap-to" type="date" class="ui-input !w-40 !py-1.5 !text-sm" wire:model="swapTo"></div>
                        <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="swapRoster">Swap</x-ui.button>
                        <p class="!mb-0 w-full text-xs text-slate-500">Each takes the other's shift, or day off, on those days. Only you can do this; staff cannot swap by themselves.</p>
                        @foreach(['swapA', 'swapB', 'swapFrom', 'swapTo'] as $field)@error($field)<p class="ui-error w-full" role="alert">{{ $message }}</p>@enderror @endforeach
                    </form>
                        </div>
                    </aside>
                    </div>
                @endif
                <div class="relative" x-data="{ menu: null }" x-on:keydown.escape.stop="menu = null">
                    <div class="ui-table-wrap">
                        <table class="mb-0 w-full border-collapse text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-xs text-slate-500">
                                    <th class="sticky left-0 z-10 bg-white px-2 py-1.5 text-left font-semibold">Staff</th>
                                    @foreach($rosterDays as $day)
                                        <th @class(['px-0.5 py-1.5 text-center font-semibold', 'bg-slate-50' => $day->isWeekend()])>
                                            @if($month){{ $day->format('j') }}<span class="block text-[10px] font-normal">{{ substr($day->format('D'), 0, 2) }}</span>
                                            @else{{ $day->format('D j') }}@endif
                                            @if(isset($rosterGrid['holidays'][$day->format('Y-m-d')]))<span class="block text-[10px] font-normal text-violet-700" title="{{ $rosterGrid['holidays'][$day->format('Y-m-d')] }}">Hol</span>@endif
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rosterGrid['shown'] as $row)
                                    <tr wire:key="roster-{{ $row['staff']->id }}" class="border-b border-slate-100">
                                        <td class="sticky left-0 z-10 whitespace-nowrap bg-white px-2 py-1 font-medium text-slate-800">{{ $row['staff']->name }}</td>
                                        @foreach($rosterDays as $day)
                                            @php
                                                $key = $day->format('Y-m-d');
                                                $cell = $row['days'][$key];
                                                $due = $cell['hours'];
                                                $chosen = $rosterGrid['set'][$row['staff']->id][$key] ?? '';
                                                $swapped = $rosterGrid['notes'][$row['staff']->id][$key] ?? null;
                                                $warnings = $rosterGrid['warnings'][$row['staff']->id][$key] ?? [];
                                                $shift = $due ? ($codeOf[$due['shift'] ?? ''] ?? null) : null;
                                                [$label, $tone] = match (true) {
                                                    (bool) $cell['leave_type'] => ['Lv', 'bg-indigo-100 text-indigo-800'],
                                                    ! $due => ['Off', 'bg-slate-100 text-slate-500'],
                                                    (bool) $shift => [$shift['code'], $shift['class']],
                                                    default => ['Hrs', 'bg-white text-slate-500 ring-1 ring-inset ring-slate-300'],
                                                };
                                                $title = $row['staff']->name.', '.$day->format('D j M').': '.($cell['leave_type'] ? ($cell['note'] ?? 'On leave') : ($due ? ($due['shift'] ?? 'Usual hours').' '.\App\Services\Attendance\AttendanceAnalyzer::time($due['start']).' to '.\App\Services\Attendance\AttendanceAnalyzer::time($due['end']) : 'Day off'))
                                                    .($swapped ? '. '.$swapped : '').($warnings ? '. '.implode('. ', $warnings) : '');
                                            @endphp
                                            <td @class(['px-0.5 py-1 text-center', 'bg-slate-50' => $day->isWeekend()])>
                                                <button type="button" title="{{ $title }}" aria-label="{{ $title }}"
                                                        x-on:click="menu = { staff: {{ $row['staff']->id }}, date: '{{ $key }}', name: @js($row['staff']->name.' · '.$day->format('D j M')), x: $event.clientX, y: $event.clientY }"
                                                        @class(['relative inline-block rounded px-1 py-0.5 text-xs font-semibold tabular-nums', $tone, $month ? 'min-w-[1.9rem]' : 'min-w-[3rem]', 'outline outline-2 outline-teal-600' => $chosen !== ''])>
                                                    {{ $label }}
                                                    @if($warnings)<span class="absolute -right-0.5 -top-0.5 h-2 w-2 rounded-full bg-red-500"></span>@elseif($swapped)<span class="absolute -right-0.5 -top-0.5 h-2 w-2 rounded-full bg-indigo-500"></span>@endif
                                                </button>
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{-- The menu for the box clicked: where it was clicked, over the table. --}}
                    <div x-show="menu" x-cloak x-on:click.outside="menu = null" class="fixed z-50 w-56 rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg"
                         :style="menu && `left:${Math.min(menu.x, window.innerWidth - 240)}px;top:${Math.min(menu.y + 8, window.innerHeight - 320)}px`">
                        <p class="!mb-1 border-b border-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-500" x-text="menu?.name"></p>
                        <button type="button" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50" x-on:click="$wire.setRoster(menu.staff, menu.date, ''); menu = null">As usual</button>
                        @foreach($shifts->where('is_active', true) as $shiftOption)
                            <button type="button" class="flex w-full items-center gap-2 px-3 py-1.5 text-left hover:bg-slate-50" x-on:click="$wire.setRoster(menu.staff, menu.date, '{{ $shiftOption->id }}'); menu = null">
                                <span class="rounded px-1.5 py-0.5 text-xs font-semibold {{ $codes[$shiftOption->id]['class'] ?? '' }}">{{ $codes[$shiftOption->id]['code'] ?? '' }}</span>{{ $shiftOption->name }}
                            </button>
                        @endforeach
                        <button type="button" class="flex w-full items-center gap-2 px-3 py-1.5 text-left hover:bg-slate-50" x-on:click="$wire.setRoster(menu.staff, menu.date, '0'); menu = null"><span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-500">Off</span>Day off</button>
                    </div>
                </div>
                @include('livewire.admin.partials.attendance-pager', ['pager' => $rosterGrid['pager'], 'model' => 'rosterPage'])
                {{-- roster-extras --}}
                @if($rosterGrid['cover'])
                    <div>
                        <p class="!mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Cover
                            @if($rosterGrid['gaps'])<span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 normal-case text-red-800">{{ $rosterGrid['gaps'] }} {{ $rosterGrid['gaps'] === 1 ? 'shift is' : 'shifts are' }} short this week</span>
                            @else<span class="ml-1 rounded bg-emerald-50 px-1.5 py-0.5 normal-case text-emerald-800">Every shift is covered</span>
                            @endif
                        </p>
                        <div class="ui-table-wrap">
                            <table class="table ui-table ui-table-sm mb-0">
                                <thead><tr><th>Department · shift</th>@foreach($rosterDays as $day)<th class="text-center">{{ $month ? $day->format('j') : $day->format('D j') }}</th>@endforeach</tr></thead>
                                <tbody>
                                    @foreach($rosterGrid['cover'] as $line)
                                        <tr wire:key="cover-{{ $line['department'] }}-{{ $line['shift'] }}">
                                            <td class="whitespace-nowrap font-medium text-slate-800">{{ $line['department'] }} · {{ $line['shift'] }}</td>
                                            @foreach($line['days'] as $key => $count)
                                                <td class="text-center"><span @class(['rounded px-1.5 py-0.5 text-xs font-semibold tabular-nums', 'bg-red-100 text-red-800' => $count['have'] < $count['need'], 'bg-emerald-50 text-emerald-800' => $count['have'] >= $count['need']]) title="{{ $count['have'] }} rostered, {{ $count['need'] }} needed">{{ $month ? $count['have'] : $count['have'].' / '.$count['need'] }}</span></td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="!mb-0 mt-1 text-xs text-slate-500">People rostered on the shift against the minimum set for the department. Anyone on approved leave that day is not counted.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
