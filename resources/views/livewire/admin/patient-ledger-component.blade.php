<div>
<div class="content p-3" style="background:#f0f2f5; min-height:100vh;">
<div class="container-fluid">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0 font-weight-bold" style="color:#2c3e50;">
                <i class="fas fa-book-open mr-2 text-info"></i>Patient Account Ledger
            </h3>
            <small class="text-muted text-uppercase font-weight-bold" style="letter-spacing:.05em;">
                Full financial history per patient
            </small>
        </div>
        @if($patientId)
            <button wire:click="printPdf" class="btn btn-dark btn-sm">
                <i class="fas fa-file-pdf mr-1"></i>Export PDF
            </button>
        @endif
    </div>

    {{-- Search / Filter Bar --}}
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2">
            <div class="row align-items-end">
                <div class="col-md-5 mb-2 mb-md-0">
                    <label class="small font-weight-bold text-muted">PATIENT</label>
                    <div class="position-relative">
                        <input type="text"
                               wire:model.live.debounce.300ms="patientSearch"
                               class="form-control form-control-sm"
                               placeholder="Search by name or PX number…"
                               autocomplete="off">
                        @if(!empty($patientResults))
                            <div class="list-group position-absolute w-100 shadow" style="z-index:9999; top:100%;">
                                @foreach($patientResults as $p)
                                    <button type="button"
                                            wire:click="selectPatient({{ $p['id'] }})"
                                            class="list-group-item list-group-item-action py-1 px-2"
                                            style="font-size:.82rem;">
                                        <strong>{{ $p['name'] }}</strong>
                                        <small class="text-muted ml-2">{{ $p['pxnumber'] }}</small>
                                        @if($p['contact'])
                                            <small class="text-muted float-right">{{ $p['contact'] }}</small>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    @if($patientId)
                        <small>
                            <a href="#" wire:click.prevent="clearPatient" class="text-danger">
                                <i class="fas fa-times mr-1"></i>Clear patient
                            </a>
                        </small>
                    @endif
                </div>
                <div class="col-md-6"><label class="small font-weight-bold text-muted d-block">DATE RANGE</label><x-date-range from="fromDate" to="toDate" presets="finance" clearable /></div>
            </div>
        </div>
    </div>

    @if(!$patientId)
        <div class="card shadow-sm border-0">
            <div class="card-body text-center py-5 text-muted">
                <i class="fas fa-search fa-3x mb-3 d-block opacity-25"></i>
                <p class="mb-0">Search for a patient to view their account ledger.</p>
            </div>
        </div>
    @else
        {{-- Patient Info Card --}}
        @if($patient)
        <div class="card shadow-sm border-0 mb-3" style="border-left:4px solid #17a2b8 !important;">
            <div class="card-body py-2">
                <div class="row align-items-center">
                    <div class="col-md-4">
                        <strong>{{ $patient->name }}</strong>
                        <small class="text-muted ml-2">{{ $patient->pxnumber }}</small>
                    </div>
                    <div class="col-md-4 text-muted small">
                        {{ $patient->contact ?? '' }}
                        @if($patient->gender) &nbsp;&bull;&nbsp; {{ ucfirst($patient->gender) }} @endif
                    </div>
                    <div class="col-md-4 text-right">
                        {{-- Summary badges --}}
                        @php $s = $summary; @endphp
                        <span class="badge badge-danger mr-1" style="font-size:.8rem;">
                            Charges: {{ currency() }} {{ number_format($s['total_charges'], 2) }}
                        </span>
                        <span class="badge badge-success mr-1" style="font-size:.8rem;">
                            Paid: {{ currency() }} {{ number_format($s['total_payments'], 2) }}
                        </span>
                        <span class="badge badge-{{ $s['balance'] > 0 ? 'warning' : 'secondary' }}" style="font-size:.8rem;">
                            Balance: {{ currency() }} {{ number_format($s['balance'], 2) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
        @endif

        {{-- Ledger Table --}}
        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:.85rem;">
                        <thead class="thead-light">
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Ref</th>
                                <th class="text-right text-danger">Charge</th>
                                <th class="text-right text-success">Payment</th>
                                <th class="text-right">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($entries as $entry)
                                <tr class="{{ $entry['type'] === 'refund' ? 'table-info' : '' }}">
                                    <td>{{ \Carbon\Carbon::parse($entry['date'])->format('d M Y H:i') }}</td>
                                    <td>
                                        @if($entry['type'] === 'charge')
                                            <i class="fas fa-receipt text-danger mr-1"></i>
                                        @elseif($entry['type'] === 'payment')
                                            <i class="fas fa-money-bill-wave text-success mr-1"></i>
                                        @else
                                            <i class="fas fa-undo text-info mr-1"></i>
                                        @endif
                                        {{ $entry['label'] }}
                                    </td>
                                    <td>
                                        <small class="text-muted">{{ $entry['reference'] }}</small>
                                    </td>
                                    <td class="text-right font-weight-bold {{ $entry['debit'] > 0 ? 'text-danger' : 'text-muted' }}">
                                        @if($entry['debit'] > 0)
                                            {{ currency() }} {{ number_format($entry['debit'], 2) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-right font-weight-bold {{ $entry['credit'] > 0 ? 'text-success' : 'text-muted' }}">
                                        @if($entry['credit'] > 0)
                                            {{ currency() }} {{ number_format($entry['credit'], 2) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-right font-weight-bold {{ $entry['balance'] > 0 ? 'text-warning' : 'text-success' }}">
                                        {{ currency() }} {{ number_format($entry['balance'], 2) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        No transactions found for this patient{{ $fromDate || $toDate ? ' in the selected date range' : '' }}.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if($entries->isNotEmpty())
                            @php $s = $summary; @endphp
                            <tfoot class="bg-light font-weight-bold">
                                <tr>
                                    <td colspan="3" class="text-right">Totals</td>
                                    <td class="text-right text-danger">{{ currency() }} {{ number_format($s['total_charges'], 2) }}</td>
                                    <td class="text-right text-success">{{ currency() }} {{ number_format($s['total_payments'], 2) }}</td>
                                    <td class="text-right {{ $s['balance'] > 0 ? 'text-warning' : 'text-success' }}">
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
