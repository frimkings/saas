<div class="clinic-ui ui-page">
    <div class="content">
        <div class="w-full">

            {{-- Pending Requests --}}
            <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white card-primary shadow-sm mb-6">
                <div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2">
                    <h3 class="!mb-0 text-base font-semibold">
                        <i class="fas fa-hourglass-half mr-2"></i>Pending POS Discount Requests
                    </h3>
                    <div class="ml-auto flex items-center gap-1">
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-teal-100 text-teal-800">{{ $pendingRequests->count() }} pending</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="ui-table-wrap">
                        <table class="table ui-table mb-0">
                            <thead class="">
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
                                            <small class="text-slate-500">{{ $request->created_at->format('h:i A') }}</small>
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
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-red-100 text-red-800 mt-1">Duplicate product discount</span>
                                            @endif
                                        </td>
                                        <td class="text-right">{{ currency() }} {{ number_format($request->gross_amount, 2) }}</td>
                                        <td class="text-center">
                                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800">
                                                @if($request->discount_type === 'percentage')
                                                    {{ number_format($request->discount_value, 0) }}%
                                                @else
                                                    {{ currency() }} {{ number_format($request->discount_value, 2) }}
                                                @endif
                                            </span>
                                            <div class="text-red-700 font-semibold">-{{ currency() }} {{ number_format($request->discount_amount, 2) }}</div>
                                        </td>
                                        <td class="text-right text-green-700 font-semibold">{{ currency() }} {{ number_format($request->final_amount, 2) }}</td>
                                        <td class="text-right">
                                            <button type="button"
                                                    wire:click="approveRequest({{ $request->id }})"
                                                    class="btn ui-button ui-button-sm ui-button-primary"
                                                    title="{{ ($pendingDuplicateProductIds[$request->id] ?? collect())->isNotEmpty() ? 'Approving this will reject duplicate pending requests for the same product.' : 'Approve discount request' }}">
                                                <i class="fas fa-check mr-1"></i>Approve
                                            </button>
                                            <button type="button" wire:click="rejectRequest({{ $request->id }})" class="btn ui-button ui-button-sm ui-button-danger">
                                                <i class="fas fa-times mr-1"></i>Reject
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center text-slate-500 py-6">
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
            <div class="flex flex-wrap -mx-2 mb-6">
                <div class="w-full md:w-4/12 px-2">
                    <div class="info-box shadow-sm">
                        <span class="info-box-icon bg-amber-400"><i class="fas fa-tag"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">Total Discounted Sales</span>
                            <span class="info-box-number">{{ number_format($summary->total_count ?? 0) }}</span>
                        </div>
                    </div>
                </div>
                <div class="w-full md:w-4/12 px-2">
                    <div class="info-box shadow-sm">
                        <span class="info-box-icon bg-red-600 text-white"><i class="fas fa-minus-circle"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">Total Discount Given</span>
                            <span class="info-box-number">{{ currency() }} {{ number_format($summary->total_discounted ?? 0, 2) }}</span>
                        </div>
                    </div>
                </div>
                <div class="w-full md:w-4/12 px-2">
                    <div class="info-box shadow-sm">
                        <span class="info-box-icon bg-sky-600 text-white"><i class="fas fa-calculator"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">Average Discount</span>
                            <span class="info-box-number">{{ currency() }} {{ number_format($summary->avg_discount ?? 0, 2) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Filters --}}
            <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white card-warning shadow-sm mb-6">
                <div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2">
                    <h3 class="!mb-0 text-base font-semibold"><i class="fas fa-filter mr-2"></i>Filters</h3>
                    <div class="ml-auto flex items-center gap-1">
                        @if($search || $dateFrom || $dateTo || $filterType || $filterApprover)
                            <button wire:click="clearFilters" class="btn ui-button ui-button-sm ui-button-secondary">
                                <i class="fas fa-times mr-1"></i>Clear Filters
                            </button>
                        @endif
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="flex flex-wrap -mx-2">
                        <div class="w-full md:w-4/12 px-2">
                            <input type="text"
                                   wire:model.live.debounce.400ms="search"
                                   class="form-control ui-input ui-input-sm"
                                   placeholder="Search by transaction ID or patient name...">
                        </div>
                        <div class="w-full md:w-2/12 px-2">
                            <input type="date"
                                   wire:model.live="dateFrom"
                                   class="form-control ui-input ui-input-sm"
                                   title="From date">
                        </div>
                        <div class="w-full md:w-2/12 px-2">
                            <input type="date"
                                   wire:model.live="dateTo"
                                   class="form-control ui-input ui-input-sm"
                                   title="To date">
                        </div>
                        <div class="w-full md:w-2/12 px-2">
                            <select wire:model.live="filterType" class="form-control ui-input ui-input-sm">
                                <option value="">All Types</option>
                                <option value="percentage">Percentage (%)</option>
                                <option value="fixed">Fixed Amount ({{ currency() }})</option>
                            </select>
                        </div>
                        <div class="w-full md:w-2/12 px-2">
                            <select wire:model.live="filterApprover" class="form-control ui-input ui-input-sm">
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
            <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2">
                    <h3 class="!mb-0 text-base font-semibold">
                        <i class="fas fa-list mr-2"></i>Approved Discount Records
                    </h3>
                    <div class="ml-auto flex items-center gap-1">
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800">{{ $sales->total() }} record(s)</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="ui-table-wrap">
                        <table class="table ui-table mb-0">
                            <thead class="">
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
                                            <code class="text-teal-700">{{ $sale->transaction_id }}</code>
                                        </td>
                                        <td>
                                            <small>{{ $sale->created_at->format('M d, Y') }}</small><br>
                                            <small class="text-slate-500">{{ $sale->created_at->format('h:i A') }}</small>
                                        </td>
                                        <td>
                                            {{ $sale->patient->name ?? '<span class="text-slate-500">Walk-in</span>' }}
                                        </td>
                                        <td class="text-right text-slate-500">
                                            <small><s>{{ currency() }} {{ number_format($gross, 2) }}</s></small>
                                        </td>
                                        <td class="text-center">
                                            @if($sale->discount_type === 'percentage')
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800">
                                                    {{ number_format($sale->discount_value, 0) }}%
                                                </span>
                                            @else
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800">
                                                    {{ currency() }} {{ number_format($sale->discount_value, 2) }} fixed
                                                </span>
                                            @endif
                                        </td>
                                        <td class="text-right text-red-700 font-semibold">
                                            -{{ currency() }} {{ number_format($sale->discount_amount, 2) }}
                                        </td>
                                        <td class="text-right text-green-700 font-semibold">
                                            {{ currency() }} {{ number_format($sale->total_amount, 2) }}
                                        </td>
                                        <td>
                                            @if($sale->approvedBy)
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-green-100 text-green-800">
                                                    <i class="fas fa-check-circle mr-1"></i>{{ $sale->approvedBy->name }}
                                                </span>
                                            @else
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-100 text-slate-700">System</span>
                                            @endif
                                        </td>
                                        <td>
                                            <small>{{ $sale->user->name ?? '—' }}</small>
                                        </td>
                                        <td class="text-center">
                                            @if($sale->payment_status === 'paid')
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-green-100 text-green-800">Paid</span>
                                            @elseif($sale->payment_status === 'partial')
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800">Partial</span>
                                            @else
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-100 text-slate-700">{{ ucfirst($sale->payment_status) }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="11" class="text-center py-12">
                                            <i class="fas fa-tag fa-3x text-slate-500 mb-4 block"></i>
                                            <p class="text-slate-500 mb-0">No discount records found.</p>
                                            @if($search || $dateFrom || $dateTo || $filterType || $filterApprover)
                                                <small class="text-slate-500">Try clearing your filters.</small>
                                            @endif
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if($sales->hasPages())
                    <div class="border-t border-slate-200 bg-slate-50 px-4 py-2">
                        {{ $sales->links() }}
                    </div>
                @endif
            </div>

        </div>
    </div>

    {{-- Loading overlay --}}
    <div wire:loading.delay wire:target="search,dateFrom,dateTo,filterType,filterApprover"
         class="fixed" style="top:50%; left:50%; transform:translate(-50%,-50%); z-index:9999;">
        <div class="inline-block h-5 w-5 animate-spin rounded-full border-2 border-current border-r-transparent text-amber-600" role="status" style="width:3rem; height:3rem;">
            <span class="sr-only">Loading...</span>
        </div>
    </div>
</div>
