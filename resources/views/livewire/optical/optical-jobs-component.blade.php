<div class="clinic-ui ui-page space-y-5">
    <div class="ui-heading">
        <div>
            <h1>Job Tracking</h1>
            <p class="ui-muted">Jobs that are late or have stopped moving, and how long each lab takes.</p>
        </div>
    </div>

    @if(session()->has('success'))<div class="ui-panel p-3 text-emerald-800" role="status">{{ session('success') }}</div>@endif
    @error('release')<div class="ui-panel p-3 text-red-700" role="alert">{{ $message }}</div>@enderror

    <div class="ui-stats">
        <div class="ui-panel ui-stat"><p class="ui-muted">Overdue jobs</p><p class="ui-value {{ $overdue->isNotEmpty() ? 'text-red-700' : '' }}">{{ $overdue->count() }}</p></div>
        <div class="ui-panel ui-stat"><p class="ui-muted">Stuck over {{ $stuckDays }} {{ \Illuminate\Support\Str::plural('day', $stuckDays) }}</p><p class="ui-value {{ $stuck->isNotEmpty() ? 'text-amber-700' : '' }}">{{ $stuck->count() }}</p></div>
        <div class="ui-panel ui-stat"><p class="ui-muted">Lenses held by stuck jobs</p><p class="ui-value">{{ $stuck->sum(fn ($row) => $row['held']->count()) }}</p></div>
    </div>

    <section class="ui-panel" aria-labelledby="overdue-title">
        <div class="p-4 border-b border-slate-100"><h2 id="overdue-title" class="text-sm font-semibold text-slate-900">Overdue</h2>
            <p class="ui-muted text-xs">Not ready by the pickup date promised, or not back from the lab when expected.</p></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="p-3 text-left">Job</th><th class="p-3 text-left">Customer</th><th class="p-3 text-left">Status</th><th class="p-3 text-left">Why</th><th class="p-3 text-right">Due</th><th class="p-3 text-right">Days late</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($overdue as $row)
                        <tr wire:key="overdue-{{ $row['order']->id }}">
                            <td class="p-3 text-xs font-mono"><a class="text-teal-800 underline" href="{{ route('optical.orders', ['search' => $row['order']->order_id]) }}">{{ $row['order']->order_id }}</a></td>
                            <td class="p-3 text-xs">{{ $row['order']->display_customer_name }}<span class="block text-slate-500">{{ $row['order']->display_customer_phone }}</span></td>
                            <td class="p-3 text-xs">{{ \App\Support\Optical\OrderPresenter::badge($row['order']->status)[0] }}</td>
                            <td class="p-3 text-xs">{{ $row['reason'] }}</td>
                            <td class="p-3 text-right text-xs whitespace-nowrap">{{ $row['due']->format('d M Y') }}</td>
                            <td class="p-3 text-right text-xs font-semibold text-red-700">{{ $row['days'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-6 text-center ui-muted text-sm">No overdue jobs.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="ui-panel" aria-labelledby="stuck-title">
        <div class="p-4 border-b border-slate-100"><h2 id="stuck-title" class="text-sm font-semibold text-slate-900">Stuck jobs</h2>
            <p class="ui-muted text-xs">Status unchanged for over {{ $stuckDays }} {{ \Illuminate\Support\Str::plural('day', $stuckDays) }} (change this in Settings). Lenses held for a job cannot be sold; releasing them frees them, and the job takes a lens again at glazing if one is free.</p></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="p-3 text-left">Job</th><th class="p-3 text-left">Customer</th><th class="p-3 text-left">Status</th><th class="p-3 text-right">Unchanged for</th><th class="p-3 text-left">Lenses held</th><th></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($stuck as $row)
                        <tr wire:key="stuck-{{ $row['order']->id }}">
                            <td class="p-3 text-xs font-mono"><a class="text-teal-800 underline" href="{{ route('optical.orders', ['search' => $row['order']->order_id]) }}">{{ $row['order']->order_id }}</a></td>
                            <td class="p-3 text-xs">{{ $row['order']->display_customer_name }}</td>
                            <td class="p-3 text-xs">{{ \App\Support\Optical\OrderPresenter::badge($row['order']->status)[0] }}@if($row['order']->labSupplier)<span class="block text-slate-500">{{ $row['order']->labSupplier->name }}</span>@endif</td>
                            <td class="p-3 text-right text-xs font-semibold text-amber-800">{{ $row['days'] }} {{ \Illuminate\Support\Str::plural('day', $row['days']) }}</td>
                            <td class="p-3 text-xs">{{ $row['held']->isEmpty() ? '—' : $row['held']->map(fn ($line) => strtoupper($line->eye))->implode(', ') }}</td>
                            <td class="p-3 text-right">
                                @if($isManager && $row['held']->isNotEmpty())
                                    <button type="button" wire:click="releaseLenses({{ $row['order']->id }})" wire:confirm="Release the lenses held for {{ $row['order']->order_id }} so they can be sold?" class="ui-button ui-button-secondary text-xs">Release lenses</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-6 text-center ui-muted text-sm">No stuck jobs.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="ui-panel" aria-labelledby="turnaround-title">
        <div class="p-4 border-b border-slate-100 flex flex-wrap items-end justify-between gap-3">
            <div><h2 id="turnaround-title" class="text-sm font-semibold text-slate-900">Turnaround by lab</h2>
                <p class="ui-muted text-xs">Jobs that became ready in the period. On time means ready by the date due back from the lab, or else by the pickup date promised.</p></div>
            <div class="flex items-end gap-2">
                <label class="text-xs font-semibold">From<input type="date" wire:model.live="from" class="ui-input mt-1"></label>
                <label class="text-xs font-semibold">To<input type="date" wire:model.live="to" class="ui-input mt-1"></label>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="p-3 text-left">Lab</th><th class="p-3 text-right">Jobs</th><th class="p-3 text-right">Avg days order → ready</th><th class="p-3 text-right">Avg days at lab</th><th class="p-3 text-right">On time</th><th class="p-3 text-right">Late</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($turnaround as $row)
                        <tr>
                            <td class="p-3 text-xs font-semibold">{{ $row['lab'] }}</td>
                            <td class="p-3 text-right text-xs">{{ $row['jobs'] }}</td>
                            <td class="p-3 text-right text-xs">{{ number_format($row['avg_days'], 1) }}</td>
                            <td class="p-3 text-right text-xs">{{ $row['avg_lab_days'] === null ? '—' : number_format($row['avg_lab_days'], 1) }}</td>
                            <td class="p-3 text-right text-xs">{{ $row['on_time'] === null ? '—' : $row['on_time'].'%' }}</td>
                            <td class="p-3 text-right text-xs {{ $row['late'] ? 'text-red-700 font-semibold' : '' }}">{{ $row['late'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-6 text-center ui-muted text-sm">No jobs became ready in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
