{{-- The patient's earlier prescriptions here and from the previous software (PatientRecordsComponent::getPreviousMedicationsProperty). --}}
@if($previousMedications->isNotEmpty())
    @php($canMatchNames = auth()->user()?->hasAnyRole(['Manager', 'Super Admin']))
    <div class="ui-panel mt-4" x-data="{ all: false }" aria-labelledby="previous-medications-title">
        <div class="ui-section-heading">
            <h6 class="mb-0 font-semibold" id="previous-medications-title">
                <i class="fas fa-history"></i> Previous medications
                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-100 text-slate-700 ml-2">{{ $previousMedications->count() }}</span>
            </h6>
            <small class="text-slate-500">Earlier prescriptions, newest first. "Add again" puts one on this prescription.</small>
        </div>
        <div class="ui-table-wrap">
            <table class="ui-table text-sm">
                <thead><tr><th scope="col">Date</th><th scope="col">Medication</th><th scope="col">Qty</th><th scope="col">Directions</th><th scope="col"><span class="sr-only">Action</span></th></tr></thead>
                <tbody>
                @foreach($previousMedications as $i => $med)
                    <tr wire:key="prev-med-{{ $med['key'] }}" @if($i >= 8) x-show="all" x-cloak @endif>
                        <td class="whitespace-nowrap">{{ $med['date']?->format('d M Y') ?? '—' }}</td>
                        <td>
                            <span class="font-semibold">{{ $med['item'] }}</span>
                            @if($med['previous_software'])<span class="ml-1 inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-semibold bg-amber-50 text-amber-800">Previous software</span>@endif
                            @if($med['branch'])<span class="ml-1 inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-semibold bg-sky-50 text-sky-800">{{ $med['branch'] }}</span>@endif
                        </td>
                        <td class="whitespace-nowrap">{{ $med['quantity'] ?? '—' }}</td>
                        <td class="text-slate-600">{{ $med['directions'] !== '' ? $med['directions'] : '—' }}</td>
                        <td class="whitespace-nowrap text-right">
                            @if($med['product_id'])
                                <button type="button" class="ui-button ui-button-sm ui-button-secondary" wire:click="repeatMedication('{{ $med['source'] }}', {{ $med['id'] }})"
                                    wire:loading.attr="disabled" wire:target="repeatMedication('{{ $med['source'] }}', {{ $med['id'] }})">
                                    <i class="fas fa-redo mr-1"></i> Add again
                                </button>
                            @else
                                <span class="text-xs text-slate-500" title="No product with this name in your product list">Not in product list</span>
                                @if($med['previous_software'] && $canMatchNames)
                                    <a href="{{ route('admin.legacy-medication-names', ['search' => $med['item']]) }}" class="ml-1 text-xs text-teal-700 underline" target="_blank" rel="noopener">Match it</a>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if($previousMedications->count() > 8)
            <div class="px-4 py-2 text-center">
                <button type="button" class="ui-button ui-button-sm ui-button-secondary" x-on:click="all = !all"
                    x-text="all ? 'Show fewer' : 'Show all {{ $previousMedications->count() }}'">Show all {{ $previousMedications->count() }}</button>
            </div>
        @endif
    </div>
@endif
