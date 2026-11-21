@php
    $money = fn ($v) => currency().' '.number_format((float) $v, 2);
    $share = fn ($v) => $totals['billed'] > 0 ? round(100 * $v / $totals['billed']) : 0;
@endphp
<div class="clinic-ui ui-page space-y-6">
    <x-ui.page-header title="All branches" :description="$start->format('d M Y').($start->isSameDay($end) ? '' : ' – '.$end->format('d M Y')).' · every branch you can open, side by side'">
        <x-ui.button :href="route('admin.dashboard')"><i class="fas fa-arrow-left" aria-hidden="true"></i>Dashboard</x-ui.button>
    </x-ui.page-header>

    @if($single)
        <div class="ui-panel bg-white p-5 text-sm text-slate-600">This clinic has one branch you can open. Add another under <a href="{{ route('admin.branches') }}" class="font-semibold text-teal-700 underline">Branches</a> to compare them here.</div>
    @else
    <div class="ui-panel flex flex-wrap items-end gap-2 bg-white p-3" data-tour="branches-period">
        <div class="inline-flex overflow-hidden rounded-lg border border-slate-300 text-sm font-semibold" role="group" aria-label="Period">
            @foreach(\App\Livewire\Admin\BranchesOverviewComponent::PERIODS as $key => $label)
                <button type="button" wire:click="$set('period', '{{ $key }}')" aria-pressed="{{ $period === $key ? 'true' : 'false' }}"
                    class="px-3 py-2 {{ $period === $key ? 'bg-teal-700 text-white' : 'bg-white text-slate-700 hover:bg-slate-50' }} {{ ! $loop->first ? 'border-l border-slate-300' : '' }}">{{ $label }}</button>
            @endforeach
        </div>
        @if($period === 'custom')
            <label class="text-xs">From <input type="date" wire:model.live="from" class="ui-input !w-40 text-sm"></label>
            <label class="text-xs">To <input type="date" wire:model.live="to" class="ui-input !w-40 text-sm"></label>
        @endif
    </div>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-4" data-tour="branches-totals">
        @foreach([['Billed', $money($totals['billed'])], ['Cash taken', $money($totals['cash'])], ['Expenses', $money($totals['expenses'])], ['Visits', number_format($totals['visits'])]] as [$label, $value])
            <div class="ui-panel bg-white p-4"><p class="!mb-1 text-xs font-semibold uppercase text-slate-500">{{ $label }} · all branches</p><p class="!mb-0 text-xl font-bold">{{ $value }}</p></div>
        @endforeach
    </div>

    <section class="ui-panel bg-white" data-tour="branches-table">
        <div class="ui-table-wrap">
            <table class="ui-table w-full text-xs">
                <thead><tr><th>Branch</th><th class="ui-number">Billed</th><th class="ui-number">Share</th><th class="ui-number">Cash taken</th><th class="ui-number">Expenses</th><th class="ui-number">Cash − expenses</th><th class="ui-number">Visits</th><th class="ui-number">Owed now</th><th class="ui-number">Glasses ready</th><th></th></tr></thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr wire:key="branch-{{ $row['id'] }}">
                            <td class="whitespace-nowrap"><b>{{ $row['name'] }}</b>@if($row['id'] === $current)<span class="block text-[11px] font-semibold text-teal-800">open now</span>@endif</td>
                            <td class="ui-number">{{ $money($row['billed']) }}</td>
                            <td class="ui-number">
                                <span class="inline-block h-2 rounded bg-teal-600 align-middle" style="width: {{ max(2, intdiv($share($row['billed']), 2)) }}px" aria-hidden="true"></span> {{ $share($row['billed']) }}%
                            </td>
                            <td class="ui-number">{{ $money($row['cash']) }}</td>
                            <td class="ui-number">{{ $money($row['expenses']) }}</td>
                            <td class="ui-number {{ $row['cash'] - $row['expenses'] < 0 ? 'text-red-700' : '' }}">{{ $money($row['cash'] - $row['expenses']) }}</td>
                            <td class="ui-number">{{ number_format($row['visits']) }}</td>
                            <td class="ui-number">{{ $money($row['owed']) }}</td>
                            <td class="ui-number">{{ $row['ready'] }}</td>
                            <td class="text-right">
                                @if($row['id'] !== $current)
                                    <form method="POST" action="{{ route('tenant.branch.switch') }}" class="m-0">@csrf<input type="hidden" name="branch_id" value="{{ $row['id'] }}"><button type="submit" class="ui-button ui-button-secondary !min-h-8 text-xs">Open</button></form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot><tr class="font-bold"><td>All branches</td><td class="ui-number">{{ $money($totals['billed']) }}</td><td class="ui-number">100%</td><td class="ui-number">{{ $money($totals['cash']) }}</td><td class="ui-number">{{ $money($totals['expenses']) }}</td><td class="ui-number">{{ $money($totals['cash'] - $totals['expenses']) }}</td><td class="ui-number">{{ number_format($totals['visits']) }}</td><td class="ui-number">{{ $money($totals['owed']) }}</td><td class="ui-number">{{ $totals['ready'] }}</td><td></td></tr></tfoot>
            </table>
        </div>
    </section>
    @endif
    <p class="ui-muted text-xs">Billed is what each branch charged (before refunds). Cash taken is money received at that branch's till, including bills from other branches paid there. Reports, cash book and day close on the other pages are for the branch that is open; use Open to switch.</p>
</div>
