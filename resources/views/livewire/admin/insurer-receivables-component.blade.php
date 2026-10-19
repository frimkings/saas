<div class="clinic-ui ui-page">
  {{-- Page Header --}}
  <x-ui.page-header title="Receivables" description="What each insurer still owes the clinic." />

  <div class="content">
    <div class="w-full">

      {{-- Aging by insurer --}}
      <div class="ui-panel">
        <div class="ui-section-heading">
          <h3 class="font-semibold mb-0">Owed by insurer</h3>
        </div>
        <div class="">
          <div class="ui-table-wrap">
            <table class="ui-table ui-table-sm mb-0">
              <thead class="">
                <tr>
                  <th>Insurer</th>
                  <th class="text-center">Bills</th>
                  <th class="text-right">0–30 days</th>
                  <th class="text-right">31–60 days</th>
                  <th class="text-right">61–90 days</th>
                  <th class="text-right">Over 90 days</th>
                  <th class="text-right">Total owed</th>
                </tr>
              </thead>
              <tbody>
                @forelse($aging as $row)
                <tr>
                  <td class="font-semibold">{{ $row->insurer_name }}</td>
                  <td class="text-center">{{ $row->bills }}</td>
                  <td class="text-right">{{ number_format((float) $row->d0_30, 2) }}</td>
                  <td class="text-right">{{ number_format((float) $row->d31_60, 2) }}</td>
                  <td class="text-right {{ (float) $row->d61_90 > 0 ? 'text-amber-600 font-semibold' : '' }}">{{ number_format((float) $row->d61_90, 2) }}</td>
                  <td class="text-right {{ (float) $row->d90_plus > 0 ? 'text-red-700 font-semibold' : '' }}">{{ number_format((float) $row->d90_plus, 2) }}</td>
                  <td class="text-right font-semibold">{{ currency() }} {{ number_format((float) $row->total, 2) }}</td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center text-slate-500 py-6">No insurer owes the clinic anything right now.</td></tr>
                @endforelse
              </tbody>
              @if($aging->count() > 1)
              <tfoot>
                <tr class="font-semibold">
                  <td>All insurers</td>
                  <td class="text-center">{{ $aging->sum('bills') }}</td>
                  <td class="text-right">{{ number_format($aging->sum(fn ($r) => (float) $r->d0_30), 2) }}</td>
                  <td class="text-right">{{ number_format($aging->sum(fn ($r) => (float) $r->d31_60), 2) }}</td>
                  <td class="text-right">{{ number_format($aging->sum(fn ($r) => (float) $r->d61_90), 2) }}</td>
                  <td class="text-right">{{ number_format($aging->sum(fn ($r) => (float) $r->d90_plus), 2) }}</td>
                  <td class="text-right">{{ currency() }} {{ number_format($aging->sum(fn ($r) => (float) $r->total), 2) }}</td>
                </tr>
              </tfoot>
              @endif
            </table>
          </div>
        </div>
        @if($rejectedCount > 0)
        <div class="border-t border-slate-200 bg-slate-50 px-4 py-2 text-red-700 text-sm">
          <i class="fas fa-exclamation-triangle mr-1"></i>
          {{ $rejectedCount }} rejected claim{{ $rejectedCount === 1 ? '' : 's' }} — see the Rejected tab for what was billed to patients or written off.
        </div>
        @endif
      </div>

      {{-- Bills --}}
      <div class="ui-panel">
        <div class="ui-section-heading" style="gap:8px;">
          <div class="flex items-center flex-wrap w-full" style="gap:8px;">
            <div class="inline-flex flex-wrap gap-1">
              <button wire:click="switchView('open')" class="ui-button {{ $view === 'open' ? 'ui-button-primary' : 'ui-button-secondary' }}">Awaiting payment</button>
              <button wire:click="switchView('rejected')" class="ui-button {{ $view === 'rejected' ? 'ui-button-danger' : 'ui-button-danger' }}">
                Rejected @if($rejectedCount)<span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-50 text-slate-600 ml-1">{{ $rejectedCount }}</span>@endif
              </button>
            </div>
            <div class="flex items-stretch" style="max-width:260px;">
              <div class="flex"><span class="flex items-center border border-slate-300 bg-slate-50 px-2 text-sm text-slate-600"><i class="fas fa-search"></i></span></div>
              <input aria-label="Search" wire:model.live.debounce.300ms="search" type="text" class="ui-input" placeholder="Patient, ID or bill number…">
            </div>
            <select aria-label="Insurer" wire:model.live="insurerFilter" class="ui-input ui-input-sm" style="max-width:200px;">
              <option value="">All insurers</option>
              @foreach($insurerList as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
              @endforeach
            </select>
            <a href="{{ route('admin.insurance.claims') }}" class="ui-button ui-button-sm ui-button-secondary ml-auto">
              <i class="fas fa-file-medical mr-1"></i>Manage claims
            </a>
            @if(auth()->user()?->hasRole('Super Admin') || auth()->user()?->can(\App\Models\InsurerPayment::PERMISSION))
              <a href="{{ route('admin.insurance.payments', array_filter(['insurer' => $insurerFilter])) }}" class="ui-button ui-button-sm ui-button-primary">
                <i class="fas fa-money-check-alt mr-1"></i>Record payment
              </a>
            @endif
          </div>
        </div>
        <div class="">
          <div class="ui-table-wrap">
            <table class="ui-table ui-table-sm mb-0">
              <thead class="">
                <tr>
                  <th>Bill date</th>
                  <th>Bill</th>
                  <th>Patient</th>
                  <th>Insurer</th>
                  <th class="text-right">Bill total</th>
                  <th class="text-right">{{ $view === 'rejected' ? 'Not paid' : 'Insurer owes' }}</th>
                  <th>Claim</th>
                </tr>
              </thead>
              <tbody wire:loading.class="opacity-50">
                @forelse($bills as $sale)
                @php
                  $age = (int) $sale->created_at->startOfDay()->diffInDays(today());
                  $claim = $sale->insuranceClaim;
                @endphp
                <tr>
                  <td class="whitespace-nowrap">
                    {{ $sale->created_at->format('d M Y') }}
                    <br><small class="{{ $age > 90 ? 'text-red-700' : ($age > 60 ? 'text-amber-600' : 'text-slate-500') }}">{{ $age }} day{{ $age === 1 ? '' : 's' }}</small>
                  </td>
                  <td class="text-sm">{{ $sale->transaction_id }}</td>
                  <td>
                    {{ $sale->patient?->name ?? '—' }}
                    @if($sale->patient?->pxnumber)<br><small class="text-slate-500">{{ $sale->patient->pxnumber }}</small>@endif
                  </td>
                  <td>{{ $sale->insurer?->name ?? '—' }}</td>
                  <td class="text-right">{{ number_format((float) $sale->total_amount, 2) }}</td>
                  <td class="text-right font-semibold">
                    @if($view === 'rejected')
                      {{ currency() }} {{ number_format((float) ($claim?->shortfall_amount ?? 0), 2) }}
                      <br><small class="text-slate-500 font-normal">{{ $claim?->shortfall_action === 'write_off' ? 'Written off' : 'Billed to patient' }}</small>
                    @else
                      {{ currency() }} {{ number_format(max(0, (float) $sale->insurer_amount - (float) ($claim?->amount_received ?? 0)), 2) }}
                      @if((float) ($claim?->amount_received ?? 0) > 0)
                        <br><small class="text-slate-500 font-normal">{{ number_format((float) $claim->amount_received, 2) }} received</small>
                      @endif
                    @endif
                  </td>
                  <td>
                    @if($claim)
                      <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $claim->statusBadgeClass() }}">{{ $claim->statusLabel() }}</span>
                      @if($claim->status === 'rejected' && $claim->rejection_reason)
                        <br><small class="text-red-700">{{ \Illuminate\Support\Str::limit($claim->rejection_reason, 60) }}</small>
                      @elseif($claim->submission_date)
                        <br><small class="text-slate-500">Sent {{ $claim->submission_date->format('d M Y') }}</small>
                      @endif
                    @else
                      <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-50 text-slate-600 border border-slate-200">No claim</span>
                    @endif
                  </td>
                </tr>
                @empty
                <tr>
                  <td colspan="7" class="text-center py-12 text-slate-500">
                    {{ $view === 'rejected' ? 'No rejected claims.' : 'Nothing awaiting payment from insurers.' }}
                  </td>
                </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
        @if($bills->hasPages())
        <div class="border-t border-slate-200 bg-slate-50 px-4 py-2">{{ $bills->links() }}</div>
        @endif
      </div>

    </div>
  </div>
</div>
