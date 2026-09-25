<div>
    {{-- Header --}}
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0">Discount Approvals</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="#">Dashboard</a></li>
                        <li class="breadcrumb-item active">Discount Approvals</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="content">
        <div class="container-fluid">

            {{-- Pending Requests --}}
            <div class="card card-outline card-primary shadow-sm mb-4">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-hourglass-half mr-2"></i>Pending POS Discount Requests
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-primary">{{ $pendingRequests->count() }} pending</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Requested</th>
                                    <th>Cashier</th>
                                    <th>Patient</th>
                                    <th>Items</th>
                                    <th class="text-right">Gross</th>
                                    <th class="text-center">Discount</th>
                                    <th class="text-right">Final</th>
                                    <th class="text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($pendingRequests as $request)
                                    <tr>
                                        <td>
                                            <small>{{ $request->created_at->format('M d, Y') }}</small><br>
                                            <small class="text-muted">{{ $request->created_at->format('h:i A') }}</small>
                                        </td>
                                        <td>{{ $request->cashier->name ?? 'Unknown' }}</td>
                                        <td>{{ $request->patient->name ?? 'Walk-in' }}</td>
                                        <td>
                                            @foreach(($request->cart_snapshot ?? []) as $item)
                                                <div>
                                                    <small>{{ $item['name'] ?? 'Item' }} x{{ $item['quantity'] ?? 1 }}</small>
                                                </div>
                                            @endforeach
                                            @if(($pendingDuplicateProductIds[$request->id] ?? collect())->isNotEmpty())
                                                <span class="badge badge-danger mt-1">Duplicate product discount</span>
                                            @endif
                                        </td>
                                        <td class="text-right">{{ currency() }} {{ number_format($request->gross_amount, 2) }}</td>
                                        <td class="text-center">
                                            <span class="badge badge-warning">
                                                @if($request->discount_type === 'percentage')
                                                    {{ number_format($request->discount_value, 0) }}%
                                                @else
                                                    {{ currency() }} {{ number_format($request->discount_value, 2) }}
                                                @endif
                                            </span>
                                            <div class="text-danger font-weight-bold">-{{ currency() }} {{ number_format($request->discount_amount, 2) }}</div>
                                        </td>
                                        <td class="text-right text-success font-weight-bold">{{ currency() }} {{ number_format($request->final_amount, 2) }}</td>
                                        <td class="text-right">
                                            <button type="button"
                                                    wire:click="approveRequest({{ $request->id }})"
                                                    class="btn btn-sm btn-success"
                                                    title="{{ ($pendingDuplicateProductIds[$request->id] ?? collect())->isNotEmpty() ? 'Approving this will reject duplicate pending requests for the same product.' : 'Approve discount request' }}">
                                                <i class="fas fa-check mr-1"></i>Approve
                                            </button>
                                            <button type="button" wire:click="rejectRequest({{ $request->id }})" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-times mr-1"></i>Reject
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">
                                            No pending discount requests.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Summary Cards --}}
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="info-box shadow-sm">
                        <span class="info-box-icon bg-warning"><i class="fas fa-tag"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">Total Discounted Sales</span>
                            <span class="info-box-number">{{ number_format($summary->total_count ?? 0) }}</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="info-box shadow-sm">
                        <span class="info-box-icon bg-danger"><i class="fas fa-minus-circle"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">Total Discount Given</span>
                            <span class="info-box-number">{{ currency() }} {{ number_format($summary->total_discounted ?? 0, 2) }}</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="info-box shadow-sm">
                        <span class="info-box-icon bg-info"><i class="fas fa-calculator"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">Average Discount</span>
                            <span class="info-box-number">{{ currency() }} {{ number_format($summary->avg_discount ?? 0, 2) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Filters --}}
            <div class="card card-outline card-warning shadow-sm mb-4">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-filter me-2"></i>Filters</h3>
                    <div class="card-tools">
                        @if($search || $dateFrom || $dateTo || $filterType || $filterApprover)
                            <button wire:click="clearFilters" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-times mr-1"></i>Clear Filters
                            </button>
                        @endif
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <input type="text"
                                   wire:model.live.debounce.400ms="search"
                                   class="form-control form-control-sm"
                                   placeholder="Search by transaction ID or patient name...">
                        </div>
                        <div class="col-md-2">
                            <input type="date"
                                   wire:model.live="dateFrom"
                                   class="form-control form-control-sm"
                                   title="From date">
                        </div>
                        <div class="col-md-2">
                            <input type="date"
                                   wire:model.live="dateTo"
                                   class="form-control form-control-sm"
                                   title="To date">
                        </div>
                        <div class="col-md-2">
                            <select wire:model.live="filterType" class="form-control form-control-sm">
                                <option value="">All Types</option>
                                <option value="percentage">Percentage (%)</option>
                                <option value="fixed">Fixed Amount ({{ currency() }})</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select wire:model.live="filterApprover" class="form-control form-control-sm">
                                <option value="">All Approvers</option>
                                @foreach($approvers as $approver)
                                    <option value="{{ $approver->id }}">{{ $approver->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Table --}}
            <div class="card shadow-sm">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list mr-2"></i>Approved Discount Records
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-warning">{{ $sales->total() }} record(s)</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>#</th>
                                    <th>Transaction</th>
                                    <th>Date</th>
                                    <th>Patient</th>
                                    <th class="text-right">Gross Amount</th>
                                    <th class="text-center">Discount</th>
                                    <th class="text-right">Discount ({{ currency() }})</th>
                                    <th class="text-right">Final Total</th>
                                    <th>Approved By</th>
                                    <th>Cashier</th>
                                    <th class="text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody wire:loading.class="text-muted">
                                @forelse($sales as $sale)
                                    @php
                                        $gross = (float) $sale->total_amount + (float) $sale->discount_amount;
                                    @endphp
                                    <tr>
                                        <td>{{ $loop->iteration + ($sales->currentPage() - 1) * $sales->perPage() }}</td>
                                        <td>
                                            <code class="text-primary">{{ $sale->transaction_id }}</code>
                                        </td>
                                        <td>
                                            <small>{{ $sale->created_at->format('M d, Y') }}</small><br>
                                            <small class="text-muted">{{ $sale->created_at->format('h:i A') }}</small>
                                        </td>
                                        <td>
                                            {{ $sale->patient->name ?? '<span class="text-muted">Walk-in</span>' }}
                                        </td>
                                        <td class="text-right text-muted">
                                            <small><s>{{ currency() }} {{ number_format($gross, 2) }}</s></small>
                                        </td>
                                        <td class="text-center">
                                            @if($sale->discount_type === 'percentage')
                                                <span class="badge badge-warning">
                                                    {{ number_format($sale->discount_value, 0) }}%
                                                </span>
                                            @else
                                                <span class="badge badge-warning">
                                                    {{ currency() }} {{ number_format($sale->discount_value, 2) }} fixed
                                                </span>
                                            @endif
                                        </td>
                                        <td class="text-right text-danger fw-bold">
                                            -{{ currency() }} {{ number_format($sale->discount_amount, 2) }}
                                        </td>
                                        <td class="text-right text-success fw-bold">
                                            {{ currency() }} {{ number_format($sale->total_amount, 2) }}
                                        </td>
                                        <td>
                                            @if($sale->approvedBy)
                                                <span class="badge badge-success">
                                                    <i class="fas fa-check-circle mr-1"></i>{{ $sale->approvedBy->name }}
                                                </span>
                                            @else
                                                <span class="badge badge-secondary">System</span>
                                            @endif
                                        </td>
                                        <td>
                                            <small>{{ $sale->user->name ?? '—' }}</small>
                                        </td>
                                        <td class="text-center">
                                            @if($sale->payment_status === 'paid')
                                                <span class="badge badge-success">Paid</span>
                                            @elseif($sale->payment_status === 'partial')
                                                <span class="badge badge-warning">Partial</span>
                                            @else
                                                <span class="badge badge-secondary">{{ ucfirst($sale->payment_status) }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="11" class="text-center py-5">
                                            <i class="fas fa-tag fa-3x text-muted mb-3 d-block"></i>
                                            <p class="text-muted mb-0">No discount records found.</p>
                                            @if($search || $dateFrom || $dateTo || $filterType || $filterApprover)
                                                <small class="text-muted">Try clearing your filters.</small>
                                            @endif
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if($sales->hasPages())
                    <div class="card-footer d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            Showing {{ $sales->firstItem() }} – {{ $sales->lastItem() }} of {{ $sales->total() }}
                        </small>
                        {{ $sales->links() }}
                    </div>
                @endif
            </div>

        </div>
    </div>

    {{-- Loading overlay --}}
    <div wire:loading.delay wire:target="search,dateFrom,dateTo,filterType,filterApprover"
         class="position-fixed" style="top:50%; left:50%; transform:translate(-50%,-50%); z-index:9999;">
        <div class="spinner-border text-warning" role="status" style="width:3rem; height:3rem;">
            <span class="sr-only">Loading...</span>
        </div>
    </div>
</div>
