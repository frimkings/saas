<?php

namespace App\Livewire\Cashier;

use App\Models\RefundLog;
use App\Models\Sales;
use App\Models\AuditTrail;
use App\Services\NotificationService;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Carbon\Carbon;

class SalesRecordsComponent extends Component
{
    use WithPagination;
    #[\Livewire\Attributes\Locked]
    public string $businessLine = 'clinic';

    public $searchTerm = '';
    public $fromDate;
    public $toDate;
    public $filterRefunded = null;
    public $sortColumn = 'created_at';
    public $sortDirection = 'desc';

    public $selectedSale;
    /** Optical Sales Records: sale shown in the side panel. */
    public ?int $panelSaleId = null;

    public $initiatingRefundSale = null;
    public $initiateRefundReason = '';
    public $initiateRefundReasonCode = '';
    public $initiateRefundType = RefundLog::TYPE_REFUND;
    public array $initiateRefundItemIds = [];


    protected $rules = [
        'initiateRefundReason' => 'required|string|min:10|max:500',
        'initiateRefundReasonCode' => 'required|in:customer_return,wrong_item,defective_item,duplicate_charge,payment_error,service_cancelled,other',
        'initiateRefundType' => 'required|in:refund,void',
    ];

    protected $queryString = [
        'searchTerm' => ['except' => ''],
        'fromDate' => ['except' => ''],
        'toDate' => ['except' => ''],
        'filterRefunded' => ['except' => null],
        'page' => ['except' => 1],
    ];

    /** $line is only for embedding/tests; on a page the route decides. */
    public function mount(?string $line = null)
    {
        $this->businessLine = request()->routeIs('optical.*') || $line === 'optical' ? 'optical' : 'clinic';
        // Optical sales follow the optical roles; clinic sales keep their own.
        abort_if($this->businessLine === 'optical'
            ? ! \App\Support\OpticalAccess::can(auth()->user(), 'optical.sales')
            : ! auth()->user()?->hasRole(['Secretary', 'Cashier', 'Manager', 'Super Admin']), 403);
        $this->normalizeFilters();
    }

    public function updatedSearchTerm()
    {
        $this->resetPage();
    }

    public function updatedFromDate()
    {
        $this->normalizeFilters();
        $this->resetPage();
    }

    public function updatedToDate()
    {
        $this->normalizeFilters();
        $this->resetPage();
    }

    public function updatedFilterRefunded()
    {
        $this->normalizeFilters();
        $this->resetPage();
    }

    private function normalizeFilters()
    {
        if (!$this->fromDate) {
            $this->fromDate = Carbon::today()->format('Y-m-d');
        }
        if (!$this->toDate) {
            $this->toDate = Carbon::today()->format('Y-m-d');
        }

        $this->fromDate = $this->normalizeDate($this->fromDate);
        $this->toDate = $this->normalizeDate($this->toDate);

        if ($this->filterRefunded === '' || $this->filterRefunded === 'null') {
            $this->filterRefunded = null;
        } elseif ($this->filterRefunded !== null) {
            $this->filterRefunded = (int) $this->filterRefunded;
        }

        if (!in_array($this->sortColumn, ['created_at', 'total_amount', 'transaction_id'], true)) {
            $this->sortColumn = 'created_at';
        }

        if (!in_array($this->sortDirection, ['asc', 'desc'], true)) {
            $this->sortDirection = 'desc';
        }
    }

    private function normalizeDate($date)
    {
        try {
            return Carbon::parse($date)->format('Y-m-d');
        } catch (\Exception $e) {
            return Carbon::today()->format('Y-m-d');
        }
    }

    private function dateRange()
    {
        $from = Carbon::parse($this->fromDate)->startOfDay();
        $to = Carbon::parse($this->toDate)->endOfDay();

        if ($from->gt($to)) {
            $oldFrom = $from;
            $from = $to->copy()->startOfDay();
            $to = $oldFrom->copy()->endOfDay();
        }

        return [$from, $to];
    }

    public function sortBy($column)
    {
        if (!in_array($column, ['created_at', 'total_amount', 'transaction_id'], true)) {
            return;
        }

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function toggleRefundFilter()
    {
        $this->normalizeFilters();

        if ($this->filterRefunded === null) $this->filterRefunded = 0;
        elseif ($this->filterRefunded === 0) $this->filterRefunded = 1;
        else $this->filterRefunded = null;

        $this->resetPage();
    }

    public function resetFilters()
    {
        $this->reset(['searchTerm', 'filterRefunded', 'sortColumn', 'sortDirection']);
        $this->fromDate = Carbon::today()->format('Y-m-d');
        $this->toDate = Carbon::today()->format('Y-m-d');
        $this->resetPage();
    }

    public function viewSale($saleId)
    {
        \Log::info('viewSale called for sale ID: ' . $saleId);
        
        try {
            $this->selectedSale = Sales::where('business_line', $this->businessLine)->select('id', 'transaction_id', 'total_amount', 'is_refunded', 'patient_id', 'customer_name', 'user_id', 'created_at')
                ->with([
                    'items:id,sale_id,product_id,prescribed_quantity,dispensed_quantity,selling_price,subtotal',
                    'items.product:id,name',
                    'patient:id,name,contact,pxnumber',
                    'user:id,name'
                ])
                ->findOrFail($saleId);
            
            \Log::info('Sale found, dispatching modal event');
            
            $this->dispatch('show-viewSaleModal-form');
            
        } catch (\Exception $e) {
            \Log::error('Error in viewSale: ' . $e->getMessage());
            $this->dispatch('alert', ...[
                'type' => 'error',
                'message' => 'Error loading sale details'
            ]);
        }
    }

    public function openSalePanel(int $saleId): void
    {
        $this->panelSaleId = Sales::where('business_line', $this->businessLine)->findOrFail($saleId)->id;
    }

    public function closeSalePanel(): void
    {
        $this->panelSaleId = null;
    }

    public function printReceipt($saleId)
    {
        \Log::info('printReceipt called for sale ID: ' . $saleId);
        
        try {
            $sale = Sales::where('business_line', $this->businessLine)->select('id', 'transaction_id', 'total_amount', 'is_refunded', 'patient_id', 'customer_name', 'user_id', 'created_at')
                ->with([
                    'items:id,sale_id,product_id,prescribed_quantity,dispensed_quantity,selling_price,subtotal',
                    'items.product:id,name',
                    'patient:id,name,contact,pxnumber',
                    'user:id,name'
                ])
                ->findOrFail($saleId);
            
            \Log::info('Sale found for printing');
            
            // Convert to array for JavaScript
            $saleData = [
                'id' => $sale->id,
                'transaction_id' => $sale->transaction_id,
                'total_amount' => $sale->total_amount,
                'created_at' => $sale->created_at->toISOString(),
                'patient' => $sale->patient ? [
                    'name' => $sale->patient->name,
                    'contact' => $sale->patient->contact,
                    'pxnumber' => $sale->patient->pxnumber,
                ] : null,
                'customer_name' => $sale->customer_display_name,
                'user' => $sale->user ? [
                    'name' => $sale->user->name,
                ] : null,
                'items' => $sale->items->map(function($item) {
                    return [
                        'quantity' => $item->shown_quantity,
                        'selling_price' => $item->selling_price,
                        'subtotal' => $item->shown_subtotal,
                        'product' => [
                            'name' => $item->product->name ?? 'Unknown',
                        ]
                    ];
                })->toArray()
            ];
            
            \Log::info('Dispatching alert-receipt event with data');
            
            $this->dispatch('alert-receipt', ...[
                'sale' => $saleData
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error in printReceipt: ' . $e->getMessage());
            $this->dispatch('alert', ...[
                'type' => 'error',
                'message' => 'Unable to print receipt. Please try again or contact support.',
            ]);
        }
    }

    public function exportCSV()
    {
        $this->normalizeFilters();
        [$fromDate, $toDate] = $this->dateRange();

        $fileName = ucfirst($this->businessLine) . '_Sales_Report_' . $this->fromDate . '_to_' . $this->toDate . '.csv';
        
        $query = Sales::select('id', 'transaction_id', 'total_amount', 'amount_paid', 'payment_status', 'is_refunded', 'patient_id', 'customer_name', 'user_id', 'created_at')
            ->where('business_line', $this->businessLine)
            ->with(['patient:id,name', 'user:id,name'])
            ->whereBetween('created_at', [$fromDate, $toDate]);

        if ($this->filterRefunded !== null) {
            $query->where('is_refunded', $this->filterRefunded);
        }

        if (!empty($this->searchTerm)) {
            $query->where(function($q) {
                $q->where('transaction_id', 'like', '%' . $this->searchTerm . '%')
                  ->orWhere('customer_name', 'like', '%' . $this->searchTerm . '%')
                  ->orWhereHas('patient', function($p) {
                      $p->where('name', 'like', '%' . $this->searchTerm . '%');
                  });
            });
        }

        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$fileName",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $callback = function() use($query) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            
            fputcsv($file, ['Date', 'Transaction ID', 'Patient', 'Cashier', 'Amount', 'Status']);

            $query->chunkById(500, function($sales) use ($file) {
                foreach ($sales as $sale) {
                    fputcsv($file, [
                        $sale->created_at->format('Y-m-d H:i'),
                        $sale->transaction_id,
                        $sale->customer_display_name,
                        $sale->user->name ?? 'System',
                        currency() . ' ' . number_format($sale->total_amount, 2),
                        $sale->is_refunded ? 'Refunded' : 'Paid'
                    ]);
                }
            });
            
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function initiateRefund($saleId)
    {
        $sale = Sales::where('business_line', $this->businessLine)->with('items.product')->findOrFail($saleId);
        $user = auth()->user();
        abort_unless(
            $user?->hasAnyRole(['Manager', 'Super Admin']) || ($user?->hasRole('Cashier') && $sale->user_id === $user->id),
            403,
            'You may only request refunds for transactions you created.'
        );

        abort_if($sale->is_refunded, 422, 'This sale has already been refunded.');

        $alreadyPending = RefundLog::where('sale_id', $saleId)
            ->whereIn('status', [RefundLog::STATUS_PENDING, RefundLog::STATUS_APPROVED])
            ->exists();

        if ($alreadyPending) {
            $this->dispatch('notify', ...[
                'type'    => 'warning',
                'message' => 'A refund request for this sale is already awaiting approval.',
            ]);
            return;
        }

        // Today's consultation fee is an open visit bill until the end of the day.
        if (!$sale->finalizeForRefund()) {
            $this->dispatch('notify', ...[
                'type'    => 'warning',
                'message' => 'This bill still has a balance owing. Settle it under Outstanding Balances before requesting a refund.',
            ]);
            return;
        }

        $this->initiatingRefundSale   = $sale;
        $this->initiateRefundReason   = '';
        $this->initiateRefundReasonCode = '';
        $this->initiateRefundType = RefundLog::TYPE_REFUND;
        $this->initiateRefundItemIds = $sale->items
            ->filter(fn ($item) => $item->dispensed_quantity > $item->refunded_quantity)
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->resetErrorBag();
        $this->dispatch('show-initiateRefundModal');
    }

    public function submitRefundRequest()
    {
        $this->validate([
            'initiateRefundReason' => 'required|string|min:10|max:500',
            'initiateRefundReasonCode' => 'required|in:' . implode(',', array_keys(RefundLog::REASON_CODES)),
            'initiateRefundType' => 'required|in:refund,void',
            'initiateRefundItemIds' => 'required|array|min:1',
            'initiateRefundItemIds.*' => 'integer',
        ]);

        $sale = Sales::where('business_line', $this->businessLine)->with('items')->findOrFail($this->initiatingRefundSale->id);
        $user = auth()->user();
        abort_unless(
            $user?->hasAnyRole(['Manager', 'Super Admin']) || ($user?->hasRole('Cashier') && $sale->user_id === $user->id),
            403,
            'You may only request refunds for transactions you created.'
        );
        $allowedIds = $sale->items
            ->filter(fn ($item) => $item->dispensed_quantity > $item->refunded_quantity)
            ->pluck('id');
        $selectedIds = collect($this->initiateRefundItemIds)->map(fn ($id) => (int) $id)->unique();
        abort_unless($selectedIds->isNotEmpty() && $selectedIds->diff($allowedIds)->isEmpty(), 422, 'Invalid or already-refunded sale item selected.');

        $refund = RefundLog::create([
            'sale_id'      => $sale->id,
            'sale_item_ids'=> $selectedIds->values()->all(),
            'status'       => RefundLog::STATUS_PENDING,
            'initiated_by' => auth()->id(),
            'request_type' => $this->initiateRefundType,
            'reason_code' => $this->initiateRefundReasonCode,
            'reason'       => $this->initiateRefundReason,
            'initiated_at' => now(),
        ]);

        AuditTrail::record(
            'refund.requested',
            ucfirst($this->initiateRefundType) . " requested for sale {$sale->transaction_id}",
            $sale,
            [],
            [
                'request_type' => $this->initiateRefundType,
                'reason_code' => $this->initiateRefundReasonCode,
                'reason' => $this->initiateRefundReason,
                'sale_item_ids' => $selectedIds->values()->all(),
            ],
            $sale->patient_id,
            true
        );

        NotificationService::sendToRoles(
            ['Manager', 'Super Admin'],
            'refund_requested',
            'Refund Request Submitted',
            auth()->user()->name . " requested a refund for transaction #{$sale->transaction_id}.",
            'fas fa-undo',
            'text-warning',
            route('admin.refund-approvals'),
            null,
            auth()->id()
        );
        app(\App\Services\OwnerAlerts::class)->refundRequested($refund, $sale);

        $this->initiatingRefundSale = null;
        $this->initiateRefundReason = '';
        $this->initiateRefundReasonCode = '';
        $this->initiateRefundType = RefundLog::TYPE_REFUND;
        $this->initiateRefundItemIds = [];
        $this->resetErrorBag();
        $this->dispatch('hide-initiateRefundModal');
        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => "Refund request for #{$sale->transaction_id} submitted. Awaiting manager approval.",
        ]);
        // The optical layout has no toast listener; show it on the page instead.
        if ($this->businessLine === 'optical') {
            session()->flash('success', "Refund request for #{$sale->transaction_id} submitted. Awaiting manager approval.");
        }
    }

    /** Renderless close for the optical refund drawer, which the browser has already closed (dismissCall). */
    #[\Livewire\Attributes\Renderless]
    public function dismissRefundInitiation(): void
    {
        $this->cancelRefundInitiation();
    }

    public function cancelRefundInitiation()
    {
        $this->initiatingRefundSale = null;
        $this->initiateRefundReason = '';
        $this->initiateRefundReasonCode = '';
        $this->initiateRefundType = RefundLog::TYPE_REFUND;
        $this->initiateRefundItemIds = [];
        $this->resetErrorBag();
        $this->dispatch('hide-initiateRefundModal');
    }

    public function render()
    {
        $this->normalizeFilters();
        [$fromDate, $toDate] = $this->dateRange();

        $query = Sales::select('id', 'transaction_id', 'total_amount', 'amount_paid', 'payment_status', 'is_refunded', 'patient_id', 'customer_name', 'user_id', 'created_at')
            ->where('business_line', $this->businessLine)
            ->with([
                'items:id,sale_id,product_id,optical_product_id,prescribed_quantity,dispensed_quantity,selling_price,subtotal',
                'items.product:id,name',
                'items.opticalProduct:id,name',
                'patient:id,name',
                'user:id,name',
                'pendingRefundLog:id,sale_id,status',
            ])
            ->whereBetween('created_at', [$fromDate, $toDate]);

        if ($this->filterRefunded !== null) {
            $query->where('is_refunded', $this->filterRefunded);
        }

        if (!empty($this->searchTerm)) {
            $query->where(function($q) {
                $q->where('transaction_id', 'like', '%' . $this->searchTerm . '%')
                  ->orWhere('customer_name', 'like', '%' . $this->searchTerm . '%')
                  ->orWhereHas('patient', function($p) {
                      $p->where('name', 'like', '%' . $this->searchTerm . '%');
                  });
            });
        }

        $totalSales = Sales::where('business_line', $this->businessLine)->whereBetween('created_at', [$fromDate, $toDate])
            ->where('is_refunded', false)
            ->sum('total_amount');

        $totalReceipts = Sales::where('business_line', $this->businessLine)->whereBetween('created_at', [$fromDate, $toDate])->count();
        $refundCount = Sales::where('business_line', $this->businessLine)->whereBetween('created_at', [$fromDate, $toDate])->where('is_refunded', true)->count();
        $refundTotal = Sales::where('business_line', $this->businessLine)->whereBetween('created_at', [$fromDate, $toDate])->where('is_refunded', true)->sum('total_amount');

        if (!empty($this->searchTerm)) {
            $totalSales = Sales::where('business_line', $this->businessLine)->whereBetween('created_at', [$fromDate, $toDate])
                ->where('is_refunded', false)
                ->where(function($q) {
                    $q->where('transaction_id', 'like', '%' . $this->searchTerm . '%')
                      ->orWhere('customer_name', 'like', '%' . $this->searchTerm . '%')
                      ->orWhereHas('patient', function($p) {
                          $p->where('name', 'like', '%' . $this->searchTerm . '%');
                      });
                })
                ->sum('total_amount');
        }

        $layout = $this->businessLine === 'optical' ? 'layouts.optical' : 'layouts.clinic';

        $sales = $query->orderBy($this->sortColumn, $this->sortDirection)->paginate(10);
        // Optical draws every listed sale's panel, hidden, so View opens it in the browser without a call.
        $drawerIds = $this->businessLine === 'optical' ? $sales->pluck('id')->push($this->panelSaleId)->filter()->unique() : collect();
        $drawerSales = $drawerIds->isEmpty() ? collect() : Sales::where('business_line', 'optical')->whereIn('id', $drawerIds)
            ->with(['items.product', 'items.opticalProduct', 'patient', 'user', 'paymentTransactions', 'refundLogs', 'pendingRefundLog'])
            ->get()->sortBy(fn ($sale) => $drawerIds->search($sale->id))->values();

        return view($this->businessLine === 'optical' ? 'livewire.optical.optical-sales-component' : 'livewire.cashier.sales-records-component', [
            'sales' => $sales,
            'drawerSales' => $drawerSales,
            'saleOrders' => $drawerIds->isEmpty() ? collect() : \App\Models\LensOrder::whereIn('sale_id', $drawerIds)->get(['id', 'order_id', 'status', 'created_at', 'sale_id'])->groupBy('sale_id'),
            'totalSales' => $totalSales,
            'totalReceipts' => $totalReceipts,
            'refundCount' => $refundCount,
            'refundTotal' => $refundTotal,
        ])->layout($layout, ['menu' => 'reception']);
    }

    public function paginationView(): string
    {
        return 'livewire::tailwind';
    }
}
