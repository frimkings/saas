<div>
  {{-- Page Header --}}
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2 align-items-center">
        <div class="col-sm-6">
          <h1 class="m-0"><i class="fas fa-hand-holding-usd mr-2 text-info"></i>Insurer Receivables</h1>
          <small class="text-muted">The insurer's share of insured bills, until the claim is marked paid.</small>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.insurance.claims') }}">Insurance</a></li>
            <li class="breadcrumb-item active">Receivables</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="content">
    <div class="container-fluid">

      {{-- Aging by insurer --}}
      <div class="card card-outline card-info shadow-sm">
        <div class="card-header">
          <h3 class="card-title mb-0">Owed by insurer</h3>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead class="thead-light">
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
                  <td class="font-weight-bold">{{ $row->insurer_name }}</td>
                  <td class="text-center">{{ $row->bills }}</td>
                  <td class="text-right">{{ number_format((float) $row->d0_30, 2) }}</td>
                  <td class="text-right">{{ number_format((float) $row->d31_60, 2) }}</td>
                  <td class="text-right {{ (float) $row->d61_90 > 0 ? 'text-warning font-weight-bold' : '' }}">{{ number_format((float) $row->d61_90, 2) }}</td>
                  <td class="text-right {{ (float) $row->d90_plus > 0 ? 'text-danger font-weight-bold' : '' }}">{{ number_format((float) $row->d90_plus, 2) }}</td>
                  <td class="text-right font-weight-bold">{{ currency() }} {{ number_format((float) $row->total, 2) }}</td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center text-muted py-4">No insurer owes the clinic anything right now.</td></tr>
                @endforelse
              </tbody>
              @if($aging->count() > 1)
              <tfoot>
                <tr class="font-weight-bold">
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
        <div class="card-footer text-danger small">
          <i class="fas fa-exclamation-triangle mr-1"></i>
          {{ $rejectedCount }} rejected claim{{ $rejectedCount === 1 ? '' : 's' }} — see the Rejected tab for what was billed to patients or written off.
        </div>
        @endif
      </div>

      {{-- Bills --}}
      <div class="card shadow-sm">
        <div class="card-header flex-wrap" style="gap:8px;">
          <div class="d-flex align-items-center flex-wrap w-100" style="gap:8px;">
            <div class="btn-group btn-group-sm">
              <button wire:click="switchView('open')" class="btn {{ $view === 'open' ? 'btn-info' : 'btn-outline-info' }}">Awaiting payment</button>
              <button wire:click="switchView('rejected')" class="btn {{ $view === 'rejected' ? 'btn-danger' : 'btn-outline-danger' }}">
                Rejected @if($rejectedCount)<span class="badge badge-light ml-1">{{ $rejectedCount }}</span>@endif
              </button>
            </div>
            <div class="input-group input-group-sm" style="max-width:260px;">
              <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div>
              <input wire:model.live.debounce.300ms="search" type="text" class="form-control" placeholder="Patient, ID or bill number…">
            </div>
            <select wire:model.live="insurerFilter" class="form-control form-control-sm" style="max-width:200px;">
              <option value="">All insurers</option>
              @foreach($insurerList as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
              @endforeach
            </select>
            <a href="{{ route('admin.insurance.claims') }}" class="btn btn-sm btn-outline-secondary ml-auto">
              <i class="fas fa-file-medical mr-1"></i>Manage claims
            </a>
            @if(auth()->user()?->hasRole('Super Admin') || auth()->user()?->can(\App\Models\InsurerPayment::PERMISSION))
              <a href="{{ route('admin.insurance.payments', array_filter(['insurer' => $insurerFilter])) }}" class="btn btn-sm btn-info">
                <i class="fas fa-money-check-alt mr-1"></i>Record payment
              </a>
            @endif
          </div>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
              <thead class="thead-light">
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
                  <td class="text-nowrap">
                    {{ $sale->created_at->format('d M Y') }}
                    <br><small class="{{ $age > 90 ? 'text-danger' : ($age > 60 ? 'text-warning' : 'text-muted') }}">{{ $age }} day{{ $age === 1 ? '' : 's' }}</small>
                  </td>
                  <td class="small">{{ $sale->transaction_id }}</td>
                  <td>
                    {{ $sale->patient?->name ?? '—' }}
                    @if($sale->patient?->pxnumber)<br><small class="text-muted">{{ $sale->patient->pxnumber }}</small>@endif
                  </td>
                  <td>{{ $sale->insurer?->name ?? '—' }}</td>
                  <td class="text-right">{{ number_format((float) $sale->total_amount, 2) }}</td>
                  <td class="text-right font-weight-bold">
                    @if($view === 'rejected')
                      {{ currency() }} {{ number_format((float) ($claim?->shortfall_amount ?? 0), 2) }}
                      <br><small class="text-muted font-weight-normal">{{ $claim?->shortfall_action === 'write_off' ? 'Written off' : 'Billed to patient' }}</small>
                    @else
                      {{ currency() }} {{ number_format(max(0, (float) $sale->insurer_amount - (float) ($claim?->amount_received ?? 0)), 2) }}
                      @if((float) ($claim?->amount_received ?? 0) > 0)
                        <br><small class="text-muted font-weight-normal">{{ number_format((float) $claim->amount_received, 2) }} received</small>
                      @endif
                    @endif
                  </td>
                  <td>
                    @if($claim)
                      <span class="badge {{ $claim->statusBadgeClass() }}">{{ $claim->statusLabel() }}</span>
                      @if($claim->status === 'rejected' && $claim->rejection_reason)
                        <br><small class="text-danger">{{ \Illuminate\Support\Str::limit($claim->rejection_reason, 60) }}</small>
                      @elseif($claim->submission_date)
                        <br><small class="text-muted">Sent {{ $claim->submission_date->format('d M Y') }}</small>
                      @endif
                    @else
                      <span class="badge badge-light border">No claim</span>
                    @endif
                  </td>
                </tr>
                @empty
                <tr>
                  <td colspan="7" class="text-center py-5 text-muted">
                    {{ $view === 'rejected' ? 'No rejected claims.' : 'Nothing awaiting payment from insurers.' }}
                  </td>
                </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
        @if($bills->hasPages())
        <div class="card-footer">{{ $bills->links() }}</div>
        @endif
      </div>

    </div>
  </div>
</div>
