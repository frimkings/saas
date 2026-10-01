<div>
  {{-- Page Header --}}
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2 align-items-center">
        <div class="col-sm-6">
          <h1 class="m-0"><i class="fas fa-money-check-alt mr-2 text-info"></i>Insurer Payments</h1>
          <small class="text-muted">Record what insurers pay and the claims each payment settles.</small>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.insurance.claims') }}">Insurance</a></li>
            <li class="breadcrumb-item active">Payments</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="content">
    <div class="container-fluid">

      <div class="card card-outline card-info shadow-sm">
        <div class="card-header flex-wrap" style="gap:8px;">
          <div class="d-flex align-items-center flex-wrap w-100" style="gap:8px;">
            <select wire:model.live="insurerFilter" class="form-control form-control-sm" style="max-width:220px;">
              <option value="">All insurers</option>
              @foreach($insurerList as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
              @endforeach
            </select>
            <div class="ml-auto">
              <button wire:click="openForm('{{ $insurerFilter }}')" class="btn btn-info btn-sm">
                <i class="fas fa-plus mr-1"></i>Record payment
              </button>
            </div>
          </div>
        </div>

        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
              <thead class="thead-light">
                <tr>
                  <th>Date paid</th>
                  <th>Receipt</th>
                  <th>Insurer</th>
                  <th>Method / reference</th>
                  <th class="text-center">Claims</th>
                  <th class="text-right">Amount</th>
                  <th>Recorded by</th>
                  <th></th>
                </tr>
              </thead>
              <tbody wire:loading.class="opacity-50">
                @forelse($payments as $p)
                <tr class="{{ $viewPaymentId === $p->id ? 'table-active' : '' }}">
                  <td class="text-nowrap">{{ $p->paid_on->format('d M Y') }}</td>
                  <td class="small">{{ $p->receipt_number }}</td>
                  <td class="font-weight-bold">{{ $p->insurer?->name }}</td>
                  <td class="small">
                    {{ $methods[$p->payment_method] ?? $p->payment_method }}
                    @if($p->reference)<br><span class="text-muted">{{ $p->reference }}</span>@endif
                  </td>
                  <td class="text-center">{{ $p->allocations_count }}</td>
                  <td class="text-right font-weight-bold">{{ currency() }} {{ number_format((float) $p->amount, 2) }}</td>
                  <td class="small">{{ $p->receiver?->name ?? '—' }}</td>
                  <td class="text-right">
                    <button wire:click="viewPayment({{ $p->id }})" class="btn btn-xs btn-outline-secondary">
                      {{ $viewPaymentId === $p->id ? 'Hide' : 'Details' }}
                    </button>
                  </td>
                </tr>
                @if($viewPayment && $viewPayment->id === $p->id)
                <tr>
                  <td colspan="8" class="bg-light">
                    <table class="table table-sm table-borderless mb-0 small">
                      <thead><tr><th>Claim</th><th>Patient</th><th>Bill</th><th class="text-right">Applied</th><th>Claim status</th></tr></thead>
                      <tbody>
                        @foreach($viewPayment->allocations as $allocation)
                        <tr>
                          <td>#{{ $allocation->insurance_claim_id }}</td>
                          <td>{{ $allocation->claim?->patient?->name ?? '—' }}</td>
                          <td>{{ $allocation->claim?->sale?->transaction_id ?? '—' }}</td>
                          <td class="text-right">{{ currency() }} {{ number_format((float) $allocation->amount, 2) }}</td>
                          <td>
                            @if($allocation->claim)
                              <span class="badge {{ $allocation->claim->statusBadgeClass() }}">{{ $allocation->claim->statusLabel() }}</span>
                              @if((float) $allocation->claim->shortfall_amount > 0)
                                <span class="text-muted">— shortfall {{ currency() }} {{ number_format((float) $allocation->claim->shortfall_amount, 2) }}
                                  {{ $allocation->claim->shortfall_action === 'write_off' ? 'written off' : 'billed to patient' }}</span>
                              @endif
                            @endif
                          </td>
                        </tr>
                        @endforeach
                      </tbody>
                    </table>
                    @if($viewPayment->notes)<div class="small text-muted mt-1">Notes: {{ $viewPayment->notes }}</div>@endif
                  </td>
                </tr>
                @endif
                @empty
                <tr>
                  <td colspan="8" class="text-center py-5 text-muted">
                    <i class="fas fa-money-check-alt fa-3x mb-3 d-block text-secondary"></i>
                    No insurer payments recorded yet.
                  </td>
                </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
        @if($payments->hasPages())
        <div class="card-footer">{{ $payments->links() }}</div>
        @endif
      </div>

    </div>
  </div>

  {{-- Record payment --}}
  @if($showForm)
  <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background:rgba(0,0,0,.5);overflow-y:auto;">
    <div class="modal-dialog modal-xl" role="document">
      <div class="modal-content">
        <div class="modal-header bg-info text-white">
          <h5 class="modal-title"><i class="fas fa-money-check-alt mr-2"></i>Record insurer payment</h5>
          <button wire:click="closeForm" type="button" class="close text-white"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group col-md-4">
              <label>Insurer <span class="text-danger">*</span></label>
              <select wire:model.live="insurerId" class="form-control @error('insurerId') is-invalid @enderror">
                <option value="">Choose…</option>
                @foreach($insurerList as $id => $name)
                  <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
              </select>
              @error('insurerId')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-group col-md-2">
              <label>Amount paid <span class="text-danger">*</span></label>
              <input wire:model.live.debounce.400ms="payment.amount" type="number" min="0" step="0.01"
                     class="form-control @error('payment.amount') is-invalid @enderror" placeholder="0.00">
              @error('payment.amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-group col-md-2">
              <label>Date paid <span class="text-danger">*</span></label>
              <input wire:model="payment.paid_on" type="date" class="form-control @error('payment.paid_on') is-invalid @enderror">
              @error('payment.paid_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-group col-md-2">
              <label>Method</label>
              <select wire:model="payment.payment_method" class="form-control">
                @foreach($methods as $value => $label)
                  <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
              </select>
            </div>
            <div class="form-group col-md-2">
              <label>Reference</label>
              <input wire:model="payment.reference" type="text" maxlength="100" class="form-control" placeholder="Bank ref / cheque no.">
            </div>
          </div>

          @if($insurerId !== '')
            <div class="d-flex align-items-center mb-2" style="gap:8px;">
              <h6 class="font-weight-bold mb-0">Claims this payment covers</h6>
              @if($claims->isNotEmpty())
                <button type="button" wire:click="autoAllocate" class="btn btn-xs btn-outline-info">Fill oldest first</button>
              @endif
              @php $difference = round((float) ($payment['amount'] ?: 0) - $allocated, 2); @endphp
              <span class="ml-auto small {{ abs($difference) < 0.005 ? 'text-success' : 'text-danger' }}">
                Applied {{ currency() }} {{ number_format($allocated, 2) }} of {{ number_format((float) ($payment['amount'] ?: 0), 2) }}
                @if(abs($difference) >= 0.005) — {{ $difference > 0 ? number_format($difference, 2) . ' left to apply' : number_format(-$difference, 2) . ' too much' }} @endif
              </span>
            </div>
            @error('allocations')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror

            <div class="table-responsive" style="max-height:50vh;overflow-y:auto;">
              <table class="table table-sm table-hover mb-0">
                <thead class="thead-light" style="position:sticky;top:0;">
                  <tr>
                    <th>Claim</th>
                    <th>Patient</th>
                    <th>Status</th>
                    <th class="text-right">Claimed</th>
                    <th class="text-right">Approved</th>
                    <th class="text-right">Received</th>
                    <th class="text-right">Outstanding</th>
                    <th style="width:130px;">Apply</th>
                    <th class="text-center" title="Close the claim: the insurer will pay nothing more">Final</th>
                  </tr>
                </thead>
                <tbody>
                  @forelse($claims as $claim)
                  <tr wire:key="alloc-{{ $claim->id }}">
                    <td class="small">
                      #{{ $claim->id }}
                      @if($claim->sale)<br><span class="text-muted">{{ $claim->sale->transaction_id }}</span>@endif
                    </td>
                    <td class="small">{{ $claim->patient?->name ?? '—' }}<br><span class="text-muted">{{ ($claim->submission_date ?? $claim->created_at)->format('d M Y') }}</span></td>
                    <td><span class="badge {{ $claim->statusBadgeClass() }}">{{ $claim->statusLabel() }}</span></td>
                    <td class="text-right">{{ number_format((float) $claim->claim_amount, 2) }}</td>
                    <td class="text-right">{{ $claim->approved_amount !== null ? number_format((float) $claim->approved_amount, 2) : '—' }}</td>
                    <td class="text-right">{{ number_format((float) $claim->amount_received, 2) }}</td>
                    <td class="text-right font-weight-bold">{{ number_format($claim->outstandingAmount(), 2) }}</td>
                    <td>
                      <input wire:model.live.debounce.400ms="allocations.{{ $claim->id }}.amount" type="number" min="0" step="0.01"
                             class="form-control form-control-sm @error('allocations.' . $claim->id . '.amount') is-invalid @enderror" placeholder="0.00">
                    </td>
                    <td class="text-center">
                      <input wire:model="allocations.{{ $claim->id }}.settle" type="checkbox">
                    </td>
                  </tr>
                  @empty
                  <tr><td colspan="9" class="text-center text-muted py-4">This insurer has no submitted or approved claims waiting for payment.</td></tr>
                  @endforelse
                </tbody>
              </table>
            </div>
            <small class="text-muted d-block mt-2">
              A claim is marked paid when it is fully paid, or when you tick <strong>Final</strong>. Whatever the insurer did not pay on a closed claim is
              handled by the insurer's setting: added to the patient's balance, or written off.
            </small>
          @endif

          <div class="form-group mt-3 mb-0">
            <label>Notes</label>
            <textarea wire:model="payment.notes" class="form-control" rows="2" maxlength="500" placeholder="Optional"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button wire:click="closeForm" class="btn btn-secondary">Cancel</button>
          <button wire:click="save" wire:loading.attr="disabled" class="btn btn-info">
            <i class="fas fa-save mr-1"></i>Save payment
          </button>
        </div>
      </div>
    </div>
  </div>
  @endif
</div>
