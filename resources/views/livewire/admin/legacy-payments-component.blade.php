{{-- Payments from the clinic's previous software, for the whole clinic (App\Models\PatientLegacyPayment). History only: not sales. --}}
@php
    $money = fn ($amount) => currency().' '.number_format((float) $amount, 2);
    $filtered = $fromDate !== '' || $toDate !== '' || $method !== '' || $search !== '';
    $splitTotal = max(0.01, (float) $byMethod->sum('total'));
    $tones = ['#0f766e', '#2563eb', '#d97706', '#7c3aed', '#db2777', '#64748b'];
@endphp
<div class="clinic-ui ui-page space-y-4">
    <style>
        .lx-filters{display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;padding:12px 16px;border-bottom:1px solid var(--clinic-line)}
        .lx-filters .ui-field{margin:0}.lx-filters .lx-search{flex:1;min-width:200px}
        .lx-filters select.ui-input{min-width:170px}
        .lx-bar{display:flex;height:10px;border-radius:999px;overflow:hidden;background:#f1f5f9;margin:8px 0}
        .lx-legend{display:flex;flex-wrap:wrap;gap:4px 14px;font-size:12px}
        .lx-legend button{border:0;background:none;padding:0;color:var(--clinic-ink);cursor:pointer;display:inline-flex;align-items:center;gap:5px}
        .lx-legend button:hover{text-decoration:underline}.lx-legend b{font-weight:600;color:var(--clinic-muted)}
        .lx-dot{display:inline-block;width:9px;height:9px;border-radius:3px}
    </style>

    <header class="ui-heading">
        <div>
            <p class="ui-muted">Clinic workspace / Finance / Sales Records</p>
            <h1>Previous software payments</h1>
            <p class="ui-muted">What patients paid before the clinic moved here, brought over from its previous software. For reference only: these are not sales here and are not in receipts, the cash book, the income statement or the dashboard.</p>
        </div>
        <div class="ui-actions">
            @if($hasLegacyExpenses)<x-ui.button :href="route('admin.expenses.previous')">Previous software expenses</x-ui.button>@endif
            <x-ui.button :href="route('admin.sales-records')">Back to Sales Records</x-ui.button>
        </div>
    </header>

    <section class="ui-tiles" aria-label="Summary">
        <x-ui.stat-tile :label="$filtered ? 'Total in these filters' : 'Total'" :value="$money($total)" :hint="number_format($payments->total()).' '.Str::plural('payment', $payments->total())" />
        <x-ui.stat-tile label="Patients" :value="number_format($patients)" hint="Who paid in these filters" />
        <x-ui.stat-tile label="Covers" :value="$firstAt ? $firstAt->format('M Y').' – '.$lastAt->format('M Y') : '—'" :hint="number_format($allCount).' payments in all'" />
    </section>

    @if($byMethod->isNotEmpty())
        <x-ui.panel aria-label="Payments by method" style="padding:14px 16px">
            <h2 class="text-sm font-bold">By payment method</h2>
            <div class="lx-bar" aria-hidden="true">
                @foreach($byMethod as $i => $row)<span style="width:{{ $row->total / $splitTotal * 100 }}%;background:{{ $tones[$i % count($tones)] }}"></span>@endforeach
            </div>
            <div class="lx-legend">
                @foreach($byMethod as $i => $row)
                    <button type="button" wire:click="$set('method', @js($row->payment_method ?? '-'))" title="Show only {{ $row->payment_method ?? 'not recorded' }}">
                        <span class="lx-dot" style="background:{{ $tones[$i % count($tones)] }}"></span>{{ $row->payment_method ?? 'Not recorded' }} <b>{{ $money($row->total) }} · {{ number_format($row->n) }}</b>
                    </button>
                @endforeach
            </div>
        </x-ui.panel>
    @endif

    <x-ui.panel style="padding:0">
        <h2 class="sr-only">Previous software payment records</h2>
        <div class="lx-filters">
            <x-date-range from="fromDate" to="toDate" presets="finance" label="Dates" clearable />
            <x-ui.field label="Method" name="method" :options="['' => 'All methods'] + $methods->combine($methods)->all() + ['-' => 'Not recorded']" wire:model.live="method" />
            <div class="lx-search"><x-ui.field label="Search" name="search" wire:model.live.debounce.300ms="search" placeholder="Patient name, PX number, reference or invoice" /></div>
            @if($filtered)<x-ui.button wire:click="resetFilters">Reset filters</x-ui.button>@endif
            <span class="ui-muted text-xs" wire:loading role="status">Updating…</span>
        </div>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <caption class="sr-only">Payments from the previous software matching the selected filters.</caption>
                <thead><tr><th scope="col">Date</th><th scope="col">Patient</th><th scope="col">Method</th><th scope="col">Reference</th><th scope="col">Recorded by</th><th scope="col" class="ui-number">Amount</th></tr></thead>
                <tbody wire:loading.class="opacity-50">
                @forelse($payments as $payment)
                    <tr wire:key="legacy-payment-{{ $payment->id }}">
                        <td class="whitespace-nowrap">{{ $payment->paid_at->format('d M Y') }}<span class="block text-xs text-slate-500">{{ $payment->paid_at->format('H:i') }}</span></td>
                        <td>
                            @if($payment->patient)
                                <a href="{{ route('admin.patient-ledger', ['patient' => $payment->patient->id]) }}" class="font-semibold text-teal-800 hover:underline" title="Open {{ $payment->patient->name }}'s ledger">{{ $payment->patient->name }}</a>
                            @else
                                <span class="font-semibold">Unknown patient</span>
                            @endif
                            @if($payment->patient?->pxnumber)<span class="block text-xs text-slate-500">{{ $payment->patient->pxnumber }}</span>@endif
                            @if($payment->notes)<span class="block text-xs text-slate-600">{{ $payment->notes }}</span>@endif
                        </td>
                        <td class="whitespace-nowrap">{{ $payment->payment_method ?? '—' }}</td>
                        <td class="whitespace-nowrap text-slate-600">{{ $payment->reference ?? '—' }}@if($payment->invoice_number)<span class="block text-xs text-slate-500">Invoice {{ $payment->invoice_number }}</span>@endif</td>
                        <td class="whitespace-nowrap text-slate-600">{{ $payment->recorded_by ?? '—' }}</td>
                        <td class="ui-number whitespace-nowrap font-semibold">{{ $money($payment->amount) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="ui-empty">
                        @if($filtered)
                            <h2>No payments match these filters</h2><p class="ui-muted">Try other dates or clear the filters.</p>
                            <x-ui.button wire:click="resetFilters">Reset filters</x-ui.button>
                        @else
                            <h2>No payments from previous software</h2><p class="ui-muted">Payments appear here when the clinic's records are brought over from software it used before.</p>
                        @endif
                    </div></td></tr>
                @endforelse
                </tbody>
                @if($payments->isNotEmpty())<tfoot><tr><th colspan="5" scope="row" class="ui-number">{{ $payments->hasPages() ? 'Page total' : 'Total' }}</th><td class="ui-number font-bold">{{ $money($payments->sum('amount')) }}</td></tr></tfoot>@endif
            </table>
        </div>
        @if($payments->hasPages())
            <div class="ui-panel-heading" style="padding:10px 16px">
                <p class="ui-muted">{{ number_format($payments->total()) }} {{ Str::plural('payment', $payments->total()) }}</p>
                <div class="ui-actions">
                    <x-ui.button wire:click="previousPage" :disabled="$payments->onFirstPage()" aria-label="Previous page">Previous</x-ui.button>
                    <span class="ui-muted">Page {{ $payments->currentPage() }} of {{ $payments->lastPage() }}</span>
                    <x-ui.button wire:click="nextPage" :disabled="!$payments->hasMorePages()" aria-label="Next page">Next</x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.panel>
</div>
