<div class="clinic-ui ui-page">
<div>
<div class="w-full">

    {{-- Header --}}
    <div class="flex justify-between items-center mb-6">
        <div>
            <h3 class="mb-0 font-semibold" style="color:#2c3e50;">
                <i class="fas fa-book-open mr-2 text-teal-700"></i>Patient Account Ledger
            </h3>
            <small class="text-slate-500 uppercase font-semibold" style="letter-spacing:.05em;">
                Full financial history per patient
            </small>
        </div>
        @if($patientId)
            <button wire:click="printPdf" class="btn ui-button ui-button-secondary ui-button-sm">
                <i class="fas fa-file-pdf mr-1"></i>Export PDF
            </button>
        @endif
    </div>

    {{-- Search / Filter Bar --}}
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm border-0 mb-4">
        <div class="card-body p-4 py-2">
            <div class="flex flex-wrap -mx-2 items-end">
                <div class="w-full md:w-5/12 px-2 mb-2 md:mb-0">
                    <label class="text-sm font-semibold text-slate-500">PATIENT</label>
                    <div class="relative">
                        <input type="text"
                               wire:model.live.debounce.300ms="patientSearch"
                               class="form-control ui-input ui-input-sm"
                               placeholder="Search by name or PX number…"
                               autocomplete="off">
                        @if(!empty($patientResults))
                            <div class="overflow-hidden rounded-md border border-slate-200 bg-white absolute w-full shadow" style="z-index:9999; top:100%;">
                                @foreach($patientResults as $p)
                                    <button type="button"
                                            wire:click="selectPatient({{ $p['id'] }})"
                                            class="list-group-item block w-full border-b border-slate-100 text-left hover:bg-slate-50 py-1 px-2"
                                            style="font-size:.82rem;">
                                        <strong>{{ $p['name'] }}</strong>
                                        <small class="text-slate-500 ml-2">{{ $p['pxnumber'] }}</small>
                                        @if($p['contact'])
                                            <small class="text-slate-500 float-right">{{ $p['contact'] }}</small>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    @if($patientId)
                        <small>
                            <a href="#" wire:click.prevent="clearPatient" class="text-red-700">
                                <i class="fas fa-times mr-1"></i>Clear patient
                            </a>
                        </small>
                    @endif
                </div>
                <div class="w-full md:w-6/12 px-2"><label class="text-sm font-semibold text-slate-500 block">DATE RANGE</label><x-date-range from="fromDate" to="toDate" presets="finance" clearable /></div>
            </div>
        </div>
    </div>

    @if(!$patientId)
        <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm border-0">
            <div class="card-body p-4 text-center py-12 text-slate-500">
                <i class="fas fa-search fa-3x mb-4 block opacity-25"></i>
                <p class="mb-0">Search for a patient to view their account ledger.</p>
            </div>
        </div>
    @else
        {{-- Patient Info Card --}}
        @if($patient)
        <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm border-0 mb-4" style="border-left:4px solid #17a2b8 !important;">
            <div class="card-body p-4 py-2">
                <div class="flex flex-wrap -mx-2 items-center">
                    <div class="w-full md:w-4/12 px-2">
                        <strong>{{ $patient->name }}</strong>
                        <small class="text-slate-500 ml-2">{{ $patient->pxnumber }}</small>
                    </div>
                    <div class="w-full md:w-4/12 px-2 text-slate-500 text-sm">
                        {{ $patient->contact ?? '' }}
                        @if($patient->gender) &nbsp;&bull;&nbsp; {{ ucfirst($patient->gender) }} @endif
                    </div>
                    <div class="w-full md:w-4/12 px-2 text-right">
                        {{-- Summary badges --}}
                        @php $s = $summary; @endphp
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-red-100 text-red-800 mr-1" style="font-size:.8rem;">
                            Charges: {{ currency() }} {{ number_format($s['total_charges'], 2) }}
                        </span>
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-green-100 text-green-800 mr-1" style="font-size:.8rem;">
                            Paid: {{ currency() }} {{ number_format($s['total_payments'], 2) }}
                        </span>
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $s['balance'] > 0 ? 'warning' : 'secondary' }}" style="font-size:.8rem;">
                            Balance: {{ currency() }} {{ number_format($s['balance'], 2) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
        @endif

        {{-- Ledger Table --}}
        <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm border-0">
            <div class="card-body p-0">
                <div class="ui-table-wrap">
                    <table class="table ui-table mb-0" style="font-size:.85rem;">
                        <thead class="">
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Ref</th>
                                <th class="text-right text-red-700">Charge</th>
                                <th class="text-right text-green-700">Payment</th>
                                <th class="text-right">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($entries as $entry)
                                <tr class="{{ $entry['type'] === 'refund' ? 'bg-sky-50' : '' }}">
                                    <td>{{ \Carbon\Carbon::parse($entry['date'])->format('d M Y H:i') }}</td>
                                    <td>
                                        @if($entry['type'] === 'charge')
                                            <i class="fas fa-receipt text-red-700 mr-1"></i>
                                        @elseif($entry['type'] === 'payment')
                                            <i class="fas fa-money-bill-wave text-green-700 mr-1"></i>
                                        @elseif($entry['type'] === 'insurance')
                                            <i class="fas fa-shield-alt text-sky-700 mr-1"></i>
                                        @else
                                            <i class="fas fa-undo text-sky-700 mr-1"></i>
                                        @endif
                                        {{ $entry['label'] }}
                                    </td>
                                    <td>
                                        <small class="text-slate-500">{{ $entry['reference'] }}</small>
                                    </td>
                                    <td class="text-right font-semibold {{ $entry['debit'] > 0 ? 'text-red-700' : 'text-slate-500' }}">
                                        @if($entry['debit'] > 0)
                                            {{ currency() }} {{ number_format($entry['debit'], 2) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-right font-semibold {{ $entry['credit'] > 0 ? 'text-green-700' : 'text-slate-500' }}">
                                        @if($entry['credit'] > 0)
                                            {{ currency() }} {{ number_format($entry['credit'], 2) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-right font-semibold {{ $entry['balance'] > 0 ? 'text-amber-600' : 'text-green-700' }}">
                                        {{ currency() }} {{ number_format($entry['balance'], 2) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-slate-500 py-6">
                                        No transactions found for this patient{{ $fromDate || $toDate ? ' in the selected date range' : '' }}.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if($entries->isNotEmpty())
                            @php $s = $summary; @endphp
                            <tfoot class="bg-slate-50 font-semibold">
                                <tr>
                                    <td colspan="3" class="text-right">Totals</td>
                                    <td class="text-right text-red-700">{{ currency() }} {{ number_format($s['total_charges'], 2) }}</td>
                                    <td class="text-right text-green-700">{{ currency() }} {{ number_format($s['total_payments'], 2) }}</td>
                                    <td class="text-right {{ $s['balance'] > 0 ? 'text-amber-600' : 'text-green-700' }}">
                                        {{ currency() }} {{ number_format($s['balance'], 2) }}
                                    </td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
    @endif

</div>{{-- /container-fluid --}}
</div>{{-- /content --}}
</div>{{-- single Livewire root --}}
