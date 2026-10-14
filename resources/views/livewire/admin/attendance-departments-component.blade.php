{{-- Staff & Security → Attendance → Departments: a page of its own; a department opens in a panel from the right. --}}
<div class="clinic-ui ui-page">
    <div class="w-full">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <a href="{{ route('admin.attendance') }}" class="text-xs font-medium text-teal-700 underline">‹ Attendance</a>
                <h1 class="!mb-0 text-xl font-semibold text-slate-900">Departments</h1>
                <p class="!mb-0 text-sm text-slate-500">Group staff into departments, choose who heads each, and set their cover and rules. Heads see and run only their own people.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($total > 8 || $search !== '')<input type="search" class="ui-input !w-56 !py-1.5 !text-sm" wire:model.live.debounce.300ms="search" placeholder="Find a department" aria-label="Find a department">@endif
                <button type="button" class="ui-button ui-button-primary" wire:click="openDepartments"><i class="fas fa-plus" aria-hidden="true"></i>Add department</button>
            </div>
        </div>

        @if($terminalLabels)
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm text-indigo-900">
                <span>The terminal already has {{ $terminalLabels }} department {{ $terminalLabels === 1 ? 'name' : 'names' }} for people who are in no department here.</span>
                <button type="button" class="ui-button ui-button-secondary !py-1.5" wire:click="departmentsFromTerminal" wire:loading.attr="disabled">Make departments from them</button>
            </div>
        @endif

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="ui-table-wrap">
                <table class="table ui-table mb-0">
                    <thead>
                        <tr><th>Department</th><th>Headed by</th><th class="text-right">People</th><th>Minimum cover</th><th>Rules</th><th><span class="sr-only">Open</span></th></tr>
                    </thead>
                    <tbody>
                        @forelse($departments as $unit)
                            @php $heads = collect($unit->heads ?? [])->map(fn ($id) => $headNames[$id] ?? null)->filter(); @endphp
                            <tr wire:key="department-{{ $unit->id }}" wire:click="editDepartment({{ $unit->id }})" class="cursor-pointer hover:bg-slate-50">
                                <td class="whitespace-nowrap font-semibold text-slate-900">{{ $unit->name }}</td>
                                <td>@if($heads->isNotEmpty()){{ $heads->implode(', ') }}@else<span class="rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-900 ring-1 ring-inset ring-amber-200">No head yet</span>@endif</td>
                                <td class="text-right ui-number">{{ $unit->staff_count }}</td>
                                <td class="text-sm text-slate-600">{{ ($covers[$unit->id] ?? collect())->map(fn ($cover) => ($cover->shift->name ?? '?').' '.$cover->minimum)->implode(' · ') ?: '–' }}</td>
                                <td class="text-sm text-slate-600">{{ $unit->hasOwnRules() ? 'Its own' : "The branch's" }}</td>
                                <td class="text-right"><i class="fas fa-chevron-right text-xs text-slate-400" aria-hidden="true"></i></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-8 text-center text-slate-500">{{ $search !== '' ? 'No department matches.' : 'No departments yet. Add one, or make them from the terminal\'s department names.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($unplaced)
                <p class="!mb-0 border-t border-slate-200 px-4 py-2 text-xs text-slate-500">{{ $unplaced }} followed {{ $unplaced === 1 ? 'person is' : 'people are' }} in no department. Open a department to add them.</p>
            @endif
        </div>
    </div>

    @if($showDepartments)
        <div wire:key="department-panel-{{ $departmentEditId ?? 'new' }}">
        <div class="att-overlay" data-sheet-overlay x-data x-on:click="dismissLocal($el.nextElementSibling, $wire, { showDepartments: false })" aria-hidden="true"></div>
        <aside class="att-drawer att-wide" data-sheet role="dialog" aria-modal="true" aria-labelledby="department-title" tabindex="-1" x-data x-init="$el.focus()"
               x-on:keydown.escape.window="dismissLocal($el, $wire, { showDepartments: false })">
            <header class="att-drawer-head">
                <div>
                    <h2 id="department-title">{{ $departmentEditId ? $departmentName : 'New department' }}</h2>
                    @if($departmentEditId)<div class="text-xs text-slate-500">{{ count($departmentMembers) }} {{ count($departmentMembers) === 1 ? 'person' : 'people' }} · {{ count($departmentHeads) ?: 'no' }} {{ count($departmentHeads) === 1 ? 'head' : 'heads' }}</div>@endif
                </div>
                <button type="button" class="att-x" x-on:click="dismissLocal($el, $wire, { showDepartments: false })" aria-label="Close">&times;</button>
            </header>
            <form wire:submit="saveDepartment" id="department-form" class="att-drawer-body">
                <div>
                    <label for="department-name" class="mb-1 block text-xs font-semibold text-slate-600">Name</label>
                    <input id="department-name" type="text" maxlength="60" wire:model="departmentName" placeholder="e.g. Nursing" class="ui-input !w-full">
                    @error('departmentName')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                </div>

                <section x-data="{ q: '' }">
                    <div class="mb-1 flex items-center justify-between gap-2">
                        <h3 class="att-section-title !mb-0">Headed by · {{ count($departmentHeads) }}</h3>
                        <input type="search" x-model="q" class="ui-input !w-48 !py-1 !text-xs" placeholder="Find a user" aria-label="Find a user">
                    </div>
                    <div class="max-h-40 overflow-y-auto rounded border border-slate-200 bg-white p-2" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(11rem,1fr));gap:.25rem .75rem">
                        @foreach($accounts as $account)
                            <label class="flex min-w-0 items-center gap-1.5 text-sm text-slate-800" wire:key="head-{{ $account->id }}" x-show="! q || @js(mb_strtolower($account->name)).includes(q.toLowerCase())"><input type="checkbox" value="{{ $account->id }}" wire:model.live="departmentHeads"> <span class="truncate">{{ $account->name }}</span></label>
                        @endforeach
                    </div>
                    <p class="!mb-0 mt-1 text-xs text-slate-500">Any user of yours, whatever their role. They find "Department attendance" and "Roster" in their menu.</p>
                </section>

                <section x-data="{ q: '' }">
                    <div class="mb-1 flex items-center justify-between gap-2">
                        <h3 class="att-section-title !mb-0">People in it · {{ count($departmentMembers) }}</h3>
                        <input type="search" x-model="q" class="ui-input !w-48 !py-1 !text-xs" placeholder="Find a person" aria-label="Find a person">
                    </div>
                    <div class="max-h-64 overflow-y-auto rounded border border-slate-200 bg-white p-2" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(11rem,1fr));gap:.25rem .75rem">
                        @forelse($departmentPeople as $person)
                            <label class="flex min-w-0 items-center gap-1.5 text-sm text-slate-800" wire:key="member-{{ $person->id }}" x-show="! q || @js(mb_strtolower($person->name.' '.$person->employee_no)).includes(q.toLowerCase())"
                                   title="{{ $person->unit && $person->attendance_department_id !== $departmentEditId ? 'Now in '.$person->unit->name : '' }}">
                                <input type="checkbox" value="{{ $person->id }}" wire:model.live="departmentMembers">
                                <span class="truncate">{{ $person->name }}</span>
                                @if($person->unit && $person->attendance_department_id !== $departmentEditId)<span class="shrink-0 text-[10px] text-slate-400">{{ $person->unit->name }}</span>@endif
                            </label>
                        @empty
                            <p class="!mb-0 text-sm text-slate-500">Nobody is followed yet.</p>
                        @endforelse
                    </div>
                    <p class="!mb-0 mt-1 text-xs text-slate-500">A person is in one department; ticking someone shown in another moves them here.</p>
                </section>

                @if($shifts->isNotEmpty())
                    <section>
                        <h3 class="att-section-title">Minimum cover</h3>
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(10rem,1fr));gap:.5rem">
                            @foreach($shifts->where('is_active', true) as $shift)
                                <div class="rounded border border-slate-200 bg-white p-2" wire:key="cover-form-{{ $shift->id }}">
                                    <p class="!mb-1 text-xs font-semibold text-slate-700">{{ $shift->name }}</p>
                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.375rem">
                                        <div><label for="cover-min-{{ $shift->id }}" class="block text-[11px] text-slate-500">Each day</label><input id="cover-min-{{ $shift->id }}" type="number" min="0" max="500" class="ui-input !w-full !py-1 !text-sm" wire:model="departmentCover.{{ $shift->id }}.min" placeholder="None"></div>
                                        <div><label for="cover-weekend-{{ $shift->id }}" class="block text-[11px] text-slate-500">Sat, Sun</label><input id="cover-weekend-{{ $shift->id }}" type="number" min="0" max="500" class="ui-input !w-full !py-1 !text-sm" wire:model="departmentCover.{{ $shift->id }}.weekend" placeholder="Same"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <p class="!mb-0 mt-1 text-xs text-slate-500">How many must be on each shift. The roster shows where the department is short, and the Today board when it is short right now. Empty is no minimum.</p>
                        @foreach(['departmentCover.*.min', 'departmentCover.*.weekend'] as $field)@error($field)<p class="ui-error !mb-0" role="alert">{{ $message }}</p>@enderror @endforeach
                    </section>
                @endif

                <section>
                    <h3 class="att-section-title">Rules</h3>
                    <label class="flex items-center gap-2 text-sm text-slate-800"><input type="checkbox" wire:model.live="departmentOwnRules"> This department has rules of its own</label>
                    @if($departmentOwnRules)
                        <div class="mt-2" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem;align-items:end">
                            <div><label for="department-grace" class="mb-1 block text-xs font-semibold text-slate-600">Grace (minutes)</label><input id="department-grace" type="number" min="0" max="240" class="ui-input !w-full !py-1.5 !text-sm" wire:model="departmentGrace" placeholder="Branch: {{ $settings->grace_minutes }}"></div>
                            <div><label for="department-break" class="mb-1 block text-xs font-semibold text-slate-600">Break allowed (minutes)</label><input id="department-break" type="number" min="0" max="480" class="ui-input !w-full !py-1.5 !text-sm" wire:model="departmentBreak" placeholder="Branch: {{ $settings->break_minutes ?? 60 }}"></div>
                            <div><label for="department-overtime" class="mb-1 block text-xs font-semibold text-slate-600">Overtime after (minutes)</label><input id="department-overtime" type="number" min="1" max="480" class="ui-input !w-full !py-1.5 !text-sm" wire:model="departmentOvertimeAfter" placeholder="Branch: {{ $settings->overtime_after_minutes ?? 30 }}"></div>
                        </div>
                        <p class="!mb-0 mt-1 text-xs text-slate-500">Leave one empty to follow the branch.</p>
                        @foreach(['departmentGrace', 'departmentBreak', 'departmentOvertimeAfter'] as $field)@error($field)<p class="ui-error !mb-0" role="alert">{{ $message }}</p>@enderror @endforeach
                        <label class="mt-2 flex items-center gap-2 text-sm text-slate-800"><input type="checkbox" wire:model.live="departmentOwnHours"> It works different hours from the branch</label>
                        @if($departmentOwnHours)
                            <div class="mt-2">@include('livewire.admin.partials.attendance-hours', ['model' => 'departmentHours', 'values' => $departmentHours])</div>
                            <p class="!mb-0 mt-1 text-xs text-slate-500">For people on shifts, the roster decides; these hours apply on days a person has no shift.</p>
                        @endif
                    @endif
                </section>
            </form>
            <footer class="att-drawer-foot" style="justify-content:space-between">
                <span>@if($departmentEditId)<button type="button" class="ui-button ui-button-danger" wire:click="removeDepartment({{ $departmentEditId }})" wire:confirm="Delete {{ $departmentName }}? Its people stay followed, in no department.">Delete</button>@endif</span>
                <span class="flex gap-2">
                    <button type="button" class="ui-button ui-button-secondary" x-on:click="dismissLocal($el, $wire, { showDepartments: false })">Cancel</button>
                    <button type="submit" form="department-form" class="ui-button ui-button-primary" wire:loading.attr="disabled" wire:target="saveDepartment">{{ $departmentEditId ? 'Save' : 'Add department' }}</button>
                </span>
            </footer>
        </aside>
        </div>
    @endif
</div>
