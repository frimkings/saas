<?php

namespace App\Livewire;

use App\Models\AuditTrail;
use App\Models\PaymentTransaction;
use App\Models\RefundLog;
use App\Models\Sales;
use App\Models\SaleItem;
use App\Services\NotificationService;
use Carbon\Carbon;
use DB;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Livewire\WithPagination;

class ReportsComponent extends Component
{
    use WithPagination;

    /* Filters */
    public $fromDate;
    public $toDate;
    public $searchQuery = '';
    public $perPage = 25;
    public $showRefunded = false;
    public $activeTab = 'today'; // today, week, month, range, history, trash

    /* Analytics view */
    public $analyticsView = 'overview'; // overview, items, categories, transactions

    /* Chart */
    public $chartPeriod = 'daily';

    /* Payment status filter */
    public $paymentStatus = '';
    public $purchaseType = '';

    /* Refund */
    public $refundReason = '';
    public $refundingSale = null;

    /* View Items */
    public $viewingSale = null;

    /* Refund Details */
    public $viewingRefundSale = null;

    protected $paginationTheme = 'bootstrap';

    protected $rules = [
        'refundReason' => 'required|string|min:10|max:500',
    ];

    /* ---------------- MOUNT ---------------- */

    public function mount()
    {
        $user = auth()->user();
        abort_if(!$user?->hasRole('Super Admin') && !$user?->can('manage billing'), 403);
        AuditTrail::record('report.accessed', 'Accessed sales reports page');
        $this->setDateRangeForTab('today');
    }

    /* ---------------- TAB SWITCHING ---------------- */

    public function switchTab($tab)
    {
        $this->activeTab = $tab;
        $this->setDateRangeForTab($tab);
        $this->resetPage();

        $this->dispatchChart();
    }

    public function switchAnalyticsView($view)
    {
        $this->analyticsView = $view;
        if ($view === 'overview') {
            $this->dispatchChart();
        }
    }

    public function loadChart(): void
    {
        $this->dispatchChart();
    }

    protected function setDateRangeForTab($tab)
    {
        switch ($tab) {
            case 'today':
                $this->fromDate = now()->format('Y-m-d');
                $this->toDate   = now()->format('Y-m-d');
                break;

            case 'week':
                $this->fromDate = now()->startOfWeek()->format('Y-m-d');
                $this->toDate   = now()->endOfWeek()->format('Y-m-d');
                break;

            case 'month':
                $this->fromDate = now()->startOfMonth()->format('Y-m-d');
                $this->toDate   = now()->endOfMonth()->format('Y-m-d');
                break;

            case 'range':
                if (!$this->fromDate) {
                    $this->fromDate = now()->subDays(30)->format('Y-m-d');
                }
                if (!$this->toDate) {
                    $this->toDate = now()->format('Y-m-d');
                }
                break;

            case 'history':
                $this->fromDate = now()->subYear()->format('Y-m-d');
                $this->toDate   = now()->format('Y-m-d');
                break;

            case 'trash':
                $this->fromDate  = now()->subYears(5)->format('Y-m-d');
                $this->toDate    = now()->format('Y-m-d');
                $this->showRefunded = true;
                break;
        }
    }

    /* ---------------- UPDATED LISTENERS ---------------- */

    public function updatedFromDate()
    {
        if ($this->fromDate > $this->toDate) {
            $this->toDate = $this->fromDate;
        }
        $this->resetPage();
        $this->dispatchChart();
    }

    public function updatedToDate()
    {
        if ($this->toDate < $this->fromDate) {
            $this->fromDate = $this->toDate;
        }
        $this->resetPage();
        $this->dispatchChart();
    }

    public function updatedSearchQuery()
    {
        $this->resetPage();
    }

    public function updatedPerPage()
    {
        $this->resetPage();
    }

    public function updatedShowRefunded()
    {
        $this->resetPage();
        $this->dispatchChart();
    }

    public function updatedPaymentStatus()
    {
        $this->resetPage();
    }

    public function updatedPurchaseType()
    {
        if (!in_array($this->purchaseType, ['', 'patient', 'direct'], true)) {
            $this->purchaseType = '';
        }
        $this->resetPage();
        $this->dispatchChart();
    }

    /* ---------------- BASE QUERY ---------------- */

    protected function salesBaseQuery()
    {
        return Sales::where('business_line', 'clinic')
            ->when($this->activeTab === 'trash', function ($q) {
                $q->where('is_refunded', true);
            }, function ($q) {
                if (!$this->showRefunded) {
                    $q->where('is_refunded', false);
                }
            })
            ->whereBetween('created_at', [
                $this->fromDate . ' 00:00:00',
                $this->toDate   . ' 23:59:59',
            ])
            ->when($this->searchQuery, function ($q) {
                $q->where(function ($query) {
                    $query->where('transaction_id', 'like', '%' . $this->searchQuery . '%')
                          ->orWhere('customer_name', 'like', '%' . $this->searchQuery . '%')
                          ->orWhereHas('patient', fn ($p) =>
                              $p->where('name', 'like', '%' . $this->searchQuery . '%')
                          );
                });
            })
            ->when($this->purchaseType === 'patient', fn ($q) => $q->whereNotNull('patient_id'))
            ->when($this->purchaseType === 'direct', fn ($q) => $q->whereNull('patient_id'))
            ->when($this->paymentStatus, fn ($q) => $q->where('payment_status', $this->paymentStatus));
    }

    protected function salesQuery()
    {
        return $this->salesBaseQuery()->with('items.product', 'patient', 'user');
    }

    private function constrainJoinedSales($query)
    {
        $query->where('sales.business_line', 'clinic');
        $context = app(\App\Support\Tenancy\TenantContext::class);
        if ($context->clinicId() !== null) {
            $query->where('sales.clinic_id', $context->clinicId());
        }
        if ($context->branchId() !== null) {
            $query->where('sales.branch_id', $context->branchId());
        }

        return $query;
    }

    /* ---------------- SUMMARY ---------------- */

    public function getSummaryProperty()
    {
        $cacheKey = \App\Support\Tenancy\TenantCache::key('reports_summary_' . md5(
            auth()->id() . $this->activeTab . $this->fromDate . $this->toDate .
            ($this->showRefunded ? '1' : '0') . $this->paymentStatus .
            $this->purchaseType . $this->searchQuery
        ), true);

        return Cache::remember($cacheKey, now()->addMinutes(5), function () {
            $agg = $this->salesBaseQuery()
                ->selectRaw('
                    COUNT(*) as count,
                    COALESCE(SUM(total_amount), 0) as total_sales,
                    COALESCE(SUM(profit), 0)       as profit,
                    COALESCE(AVG(total_amount), 0) as avg_transaction
                ')
                ->first();

            $costOfSales = $this->constrainJoinedSales(SaleItem::join('sales', 'sale_items.sale_id', '=', 'sales.id'))
                ->join('products', function ($join) {
                    $join->on('sale_items.product_id', '=', 'products.id')
                        ->on('sale_items.clinic_id', '=', 'products.clinic_id');
                })
                ->when($this->activeTab === 'trash', fn ($q) => $q->where('sales.is_refunded', true),
                       fn ($q) => $this->showRefunded ? $q : $q->where('sales.is_refunded', false))
                ->whereBetween('sales.created_at', [
                    $this->fromDate . ' 00:00:00',
                    $this->toDate   . ' 23:59:59',
                ])
                ->when($this->purchaseType === 'patient', fn ($q) => $q->whereNotNull('sales.patient_id'))
                ->when($this->purchaseType === 'direct', fn ($q) => $q->whereNull('sales.patient_id'))
                ->whereNull('sale_items.deleted_at')
                ->whereNull('sales.deleted_at')
                ->sum(DB::raw('sale_items.dispensed_quantity * COALESCE(sale_items.unit_cost, products.cost_price, 0)'));

            $count      = (int) $agg->count;
            $totalSales = (float) $agg->total_sales;
            $profit     = (float) $agg->profit;
            $cost       = (float) $costOfSales;
            $gross      = $totalSales - $cost;

            return [
                'count'           => $count,
                'total_sales'     => $totalSales,
                'cost_of_sales'   => $cost,
                'gross_profit'    => $gross,
                'profit'          => $profit,
                'avg_transaction' => (float) $agg->avg_transaction,
                'margin'          => $totalSales > 0 ? ($gross / $totalSales) * 100 : 0,
            ];
        });
    }

    /* ---------------- SALES BY ITEM ---------------- */

    public function getSalesByItemsProperty()
    {
        return $this->constrainJoinedSales(SaleItem::select(
                'sale_items.product_id',
                DB::raw('SUM(sale_items.dispensed_quantity) as qty_sold'),
                DB::raw('SUM(sale_items.subtotal) as revenue'),
                DB::raw('SUM(sale_items.dispensed_quantity * COALESCE(sale_items.unit_cost, products.cost_price, 0)) as cost_of_sales')
            )
            ->join('products', function ($join) {
                $join->on('sale_items.product_id', '=', 'products.id')
                    ->on('sale_items.clinic_id', '=', 'products.clinic_id');
            })
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id'))
            ->when($this->activeTab === 'trash', function ($q) {
                $q->where('sales.is_refunded', true);
            }, function ($q) {
                if (!$this->showRefunded) {
                    $q->where('sales.is_refunded', false);
                }
            })
            ->whereBetween('sales.created_at', [
                $this->fromDate . ' 00:00:00',
                $this->toDate   . ' 23:59:59',
            ])
            ->when($this->purchaseType === 'patient', fn ($q) => $q->whereNotNull('sales.patient_id'))
            ->when($this->purchaseType === 'direct', fn ($q) => $q->whereNull('sales.patient_id'))
            ->when($this->searchQuery, function ($q) {
                $q->where('products.name', 'like', '%' . $this->searchQuery . '%');
            })
            ->whereNull('sale_items.deleted_at')
            ->whereNull('sales.deleted_at')
            ->groupBy('sale_items.product_id')
            ->with('product')
            ->orderByDesc('revenue')
            ->get()
            ->map(function ($item) {
                $item->gross_profit = $item->revenue - $item->cost_of_sales;
                $item->margin       = $item->revenue > 0
                    ? ($item->gross_profit / $item->revenue) * 100
                    : 0;
                return $item;
            });
    }

    /* ---------------- SALES BY CATEGORY ---------------- */

    public function getSalesByCategoryProperty()
    {
        return $this->constrainJoinedSales(SaleItem::select(
                'categories.id as category_id',
                'categories.name as category_name',
                DB::raw('SUM(sale_items.dispensed_quantity) as qty_sold'),
                DB::raw('SUM(sale_items.subtotal) as revenue'),
                DB::raw('SUM(sale_items.dispensed_quantity * COALESCE(sale_items.unit_cost, products.cost_price, 0)) as cost_of_sales'),
                DB::raw('COUNT(DISTINCT sale_items.sale_id) as transaction_count')
            )
            ->join('products', function ($join) {
                $join->on('sale_items.product_id', '=', 'products.id')
                    ->on('sale_items.clinic_id', '=', 'products.clinic_id');
            })
            ->join('categories', function ($join) {
                $join->on('products.category_id', '=', 'categories.id')
                    ->on('products.clinic_id', '=', 'categories.clinic_id');
            })
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id'))
            ->when($this->activeTab === 'trash', function ($q) {
                $q->where('sales.is_refunded', true);
            }, function ($q) {
                if (!$this->showRefunded) {
                    $q->where('sales.is_refunded', false);
                }
            })
            ->whereBetween('sales.created_at', [
                $this->fromDate . ' 00:00:00',
                $this->toDate   . ' 23:59:59',
            ])
            ->when($this->purchaseType === 'patient', fn ($q) => $q->whereNotNull('sales.patient_id'))
            ->when($this->purchaseType === 'direct', fn ($q) => $q->whereNull('sales.patient_id'))
            ->whereNull('sale_items.deleted_at')
            ->whereNull('sales.deleted_at')
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(function ($item) {
                $item->gross_profit = $item->revenue - $item->cost_of_sales;
                $item->margin       = $item->revenue > 0
                    ? ($item->gross_profit / $item->revenue) * 100
                    : 0;
                return $item;
            });
    }

    /* ---------------- PAYMENT METHODS ---------------- */

    public function getPaymentMethodsProperty()
    {
        $methodLabels = ['cash' => 'Cash', 'card' => 'Card', 'momo' => 'Mobile Money', 'code' => 'Hubtel Wallet'];
        $methodColors = ['cash' => '#28a745', 'card' => '#007bff', 'momo' => '#fd7e14', 'code' => '#6f42c1'];

        return PaymentTransaction::whereBetween('created_at', [
            $this->fromDate . ' 00:00:00',
            $this->toDate   . ' 23:59:59',
        ])
        ->whereHas('sale', function ($q) {
            $q->when($this->purchaseType === 'patient', fn ($sale) => $sale->whereNotNull('patient_id'))
              ->when($this->purchaseType === 'direct', fn ($sale) => $sale->whereNull('patient_id'));
        })
        ->selectRaw('payment_method, SUM(amount) as total, COUNT(*) as cnt')
        ->groupBy('payment_method')
        ->orderByDesc('total')
        ->get()
        ->map(function ($p) use ($methodLabels, $methodColors) {
            $p->label = $methodLabels[$p->payment_method] ?? strtoupper($p->payment_method);
            $p->color = $methodColors[$p->payment_method] ?? '#6c757d';
            return $p;
        });
    }

    /* ---------------- TOP PRODUCTS ---------------- */

    public function topProducts($limit = 5)
    {
        return SaleItem::select(
                'product_id',
                DB::raw('SUM(dispensed_quantity) as qty_sold'),
                DB::raw('SUM(subtotal) as revenue')
            )
            ->whereHas('sale', function ($q) {
                if ($this->activeTab === 'trash') {
                    $q->where('is_refunded', true);
                } else {
                    if (!$this->showRefunded) {
                        $q->where('is_refunded', false);
                    }
                }
                $q->whereBetween('created_at', [
                    $this->fromDate . ' 00:00:00',
                    $this->toDate   . ' 23:59:59',
                ]);
                $q->when($this->purchaseType === 'patient', fn ($sale) => $sale->whereNotNull('patient_id'))
                  ->when($this->purchaseType === 'direct', fn ($sale) => $sale->whereNull('patient_id'));
            })
            ->groupBy('product_id')
            ->with('product')
            ->orderByDesc('qty_sold')
            ->limit($limit)
            ->get();
    }

    /* ---------------- RESET / REFRESH ---------------- */

    public function resetFilters()
    {
        $this->searchQuery   = '';
        $this->showRefunded  = false;
        $this->paymentStatus = '';
        $this->purchaseType  = '';
        $this->perPage       = 25;
        $this->setDateRangeForTab($this->activeTab);
        $this->resetPage();

        $this->dispatchChart();

        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => 'Filters reset successfully!',
        ]);
    }

    public function refreshData()
    {
        $this->resetPage();
        $this->dispatchChart();

        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => 'Data refreshed successfully!',
        ]);
    }

    /* ---------------- CSV EXPORT ---------------- */

    public function exportCsv()
    {
        $filename = 'sales-report-' . $this->fromDate . '-to-' . $this->toDate . '.csv';
        $query    = $this->salesBaseQuery()->with(['patient:id,name']);

        return response()->streamDownload(function () use ($query) {
            $f = fopen('php://output', 'w');
            fputcsv($f, ['Transaction ID', 'Customer', 'Purchase Type', 'Date', 'Time', 'Total Amount', 'Amount Paid', 'Payment Status', 'Profit']);
            $query->chunkById(500, function ($chunk) use ($f) {
                foreach ($chunk as $sale) {
                    fputcsv($f, [
                        $sale->transaction_id,
                        $sale->customer_display_name,
                        $sale->patient_id ? 'Patient Purchase' : 'Direct Purchase',
                        $sale->created_at->format('Y-m-d'),
                        $sale->created_at->format('H:i:s'),
                        $sale->total_amount,
                        $sale->amount_paid,
                        $sale->payment_status,
                        $sale->profit,
                    ]);
                }
            });
            fclose($f);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /* ---------------- CHART ENGINE ---------------- */

    public function updatedChartPeriod()
    {
        if (!in_array($this->chartPeriod, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
            $this->chartPeriod = 'daily';
        }
        $this->dispatchChart();
    }

    protected function buildChartPayload(): array
    {
        if (!in_array($this->chartPeriod, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
            $this->chartPeriod = 'daily';
        }

        $cacheKey = \App\Support\Tenancy\TenantCache::key('reports_chart_' . md5(
            $this->chartPeriod . $this->activeTab . $this->fromDate . $this->toDate .
            ($this->showRefunded ? '1' : '0') . $this->purchaseType
        ), true);

        return Cache::remember($cacheKey, now()->addMinutes(5), function () {

        // "Today" is the default tab — use a natural window per period so the chart always has meaningful data.
        // Any other tab explicitly narrows the chart to that tab's date range.
        if ($this->activeTab === 'today') {
            [$start, $end] = match ($this->chartPeriod) {
                'weekly'  => [Carbon::now()->startOfMonth()->startOfDay(),            Carbon::now()->endOfMonth()->endOfDay()],
                'monthly' => [Carbon::now()->startOfYear()->startOfDay(),             Carbon::now()->endOfYear()->endOfDay()],
                'yearly'  => [Carbon::now()->subYears(4)->startOfYear()->startOfDay(), Carbon::now()->endOfYear()->endOfDay()],
                default   => [Carbon::now()->startOfWeek(Carbon::MONDAY)->startOfDay(), Carbon::now()->endOfWeek(Carbon::SUNDAY)->endOfDay()],
            };
        } else {
            $start = Carbon::parse($this->fromDate)->startOfDay();
            $end   = Carbon::parse($this->toDate)->endOfDay();
        }

        // Auto-downgrade daily chart for wide ranges — prevents thousands of loop iterations
        if ($this->chartPeriod === 'daily' && $start->diffInDays($end) > 90) {
            $this->chartPeriod = 'monthly';
        }

        $labels = $revenue = $profit = [];

        if ($this->chartPeriod === 'daily') {
            $rows = Sales::where('business_line', 'clinic')
                ->where('is_refunded', false)
                ->whereBetween('created_at', [$start, $end])
                ->when($this->purchaseType === 'patient', fn ($q) => $q->whereNotNull('patient_id'))
                ->when($this->purchaseType === 'direct', fn ($q) => $q->whereNull('patient_id'))
                ->select(DB::raw('DATE(created_at) as period'), DB::raw('SUM(total_amount) as revenue'), DB::raw('SUM(profit) as profit'))
                ->groupBy('period')
                ->orderBy('period')
                ->get()
                ->keyBy('period');

            $useDayNames = $start->copy()->diffInDays($end) <= 6;

            for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
                $key = $day->format('Y-m-d');
                $labels[]  = $useDayNames ? $day->format('D') : $day->format('M j');
                $revenue[] = (float) ($rows[$key]->revenue ?? 0);
                $profit[]  = (float) ($rows[$key]->profit  ?? 0);
            }

        } elseif ($this->chartPeriod === 'weekly') {
            $rows = Sales::where('business_line', 'clinic')
                ->where('is_refunded', false)
                ->whereBetween('created_at', [$start, $end])
                ->when($this->purchaseType === 'patient', fn ($q) => $q->whereNotNull('patient_id'))
                ->when($this->purchaseType === 'direct', fn ($q) => $q->whereNull('patient_id'))
                ->select(DB::raw('YEARWEEK(created_at, 3) as period'), DB::raw('SUM(total_amount) as revenue'), DB::raw('SUM(profit) as profit'))
                ->groupBy('period')
                ->orderBy('period')
                ->get()
                ->keyBy('period');

            $weekNum = 1;
            $current = $start->copy()->startOfWeek(Carbon::MONDAY);
            while ($current->lte($end)) {
                $key = (int) $current->format('oW'); // ISO YYYYWW — matches YEARWEEK(date, 3)
                $labels[]  = 'Week ' . $weekNum++;
                $revenue[] = (float) ($rows[$key]->revenue ?? 0);
                $profit[]  = (float) ($rows[$key]->profit  ?? 0);
                $current->addWeek();
            }

        } elseif ($this->chartPeriod === 'monthly') {
            $rows = Sales::where('business_line', 'clinic')
                ->where('is_refunded', false)
                ->whereBetween('created_at', [$start, $end])
                ->when($this->purchaseType === 'patient', fn ($q) => $q->whereNotNull('patient_id'))
                ->when($this->purchaseType === 'direct', fn ($q) => $q->whereNull('patient_id'))
                ->select(DB::raw("DATE_FORMAT(created_at,'%Y-%m') as period"), DB::raw('SUM(total_amount) as revenue'), DB::raw('SUM(profit) as profit'))
                ->groupBy('period')
                ->orderBy('period')
                ->get()
                ->keyBy('period');

            $current = $start->copy()->startOfMonth();
            while ($current->lte($end)) {
                $key = $current->format('Y-m');
                $labels[]  = $current->format('M Y');
                $revenue[] = (float) ($rows[$key]->revenue ?? 0);
                $profit[]  = (float) ($rows[$key]->profit  ?? 0);
                $current->addMonth();
            }

        } else {
            // yearly
            $rows = Sales::where('business_line', 'clinic')
                ->where('is_refunded', false)
                ->whereBetween('created_at', [$start, $end])
                ->when($this->purchaseType === 'patient', fn ($q) => $q->whereNotNull('patient_id'))
                ->when($this->purchaseType === 'direct', fn ($q) => $q->whereNull('patient_id'))
                ->select(DB::raw('YEAR(created_at) as period'), DB::raw('SUM(total_amount) as revenue'), DB::raw('SUM(profit) as profit'))
                ->groupBy('period')
                ->orderBy('period')
                ->get()
                ->keyBy('period');

            $current = $start->copy()->startOfYear();
            while ($current->lte($end)) {
                $key = $current->year;
                $labels[]  = (string) $key;
                $revenue[] = (float) ($rows[$key]->revenue ?? 0);
                $profit[]  = (float) ($rows[$key]->profit  ?? 0);
                $current->addYear();
            }
        }

        return compact('labels', 'revenue', 'profit');
        }); // end Cache::remember
    }

    protected function dispatchChart(): void
    {
        $this->dispatch('update-chart', ...$this->buildChartPayload());
    }


    /* ---------------- VIEW ITEMS MODAL ---------------- */

    public function showItemsModal($saleId)
    {
        $this->viewingSale = Sales::where('business_line', 'clinic')->with('items.product')->findOrFail($saleId);
        $this->dispatch('show-itemsModal-form');
    }

    public function closeItemsModal()
    {
        $this->viewingSale = null;
        $this->dispatch('hide-itemsModal-modal');
    }

    /* ---------------- REFUND (INITIATION) ---------------- */

    public function showRefundModal($saleId)
    {
        $sale = Sales::where('business_line', 'clinic')->with('items.product')->findOrFail($saleId);
        abort_if($sale->bill_status === 'open', 422, 'Open visit bills must be finalized before a refund can be requested.');

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

        $this->refundingSale = $sale;
        $this->refundReason  = '';
        $this->resetErrorBag();
        $this->dispatch('show-refundModal-form');
    }

    public function cancelRefund()
    {
        $this->refundingSale = null;
        $this->refundReason  = '';
        $this->resetErrorBag();
        $this->dispatch('hide-refundModal-modal');
    }

    public function processRefund()
    {
        $this->validate();

        $sale = $this->refundingSale;

        \DB::transaction(function () use ($sale) {
            RefundLog::create([
                'sale_id'      => $sale->id,
                'sale_item_ids'=> $sale->items
                    ->filter(fn ($item) => $item->dispensed_quantity > $item->refunded_quantity)
                    ->pluck('id')->values()->all(),
                'status'       => RefundLog::STATUS_PENDING,
                'initiated_by' => auth()->id(),
                'reason'       => $this->refundReason,
                'initiated_at' => now(),
            ]);
        });

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

        $transactionId       = $sale->transaction_id;
        $this->refundingSale = null;
        $this->refundReason  = '';
        $this->resetErrorBag();

        $this->dispatch('hide-refundModal-modal');
        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => "Refund request for #{$transactionId} submitted. Awaiting manager approval.",
        ]);

        $this->resetPage();
    }

    /* ---------------- REFUND DETAILS ---------------- */

    public function showRefundDetailsModal($saleId)
    {
        $this->viewingRefundSale = Sales::where('business_line', 'clinic')->with('refundedBy')->findOrFail($saleId);
        $this->dispatch('show-refundDetailsModal-form');
    }

    public function closeRefundDetailsModal()
    {
        $this->viewingRefundSale = null;
        $this->dispatch('hide-refundDetailsModal-modal');
    }

    public function getRefundLogProperty(): ?RefundLog
    {
        return $this->viewingRefundSale
            ? RefundLog::where('sale_id', $this->viewingRefundSale->id)->latest()->first()
            : null;
    }

    /* ---------------- RENDER ---------------- */

    public function render()
    {
        return view('livewire.reports-component', [
            'sales'           => $this->salesQuery()->latest()->paginate($this->perPage),
            'summary'         => $this->summary,
            'salesByItems'    => $this->analyticsView === 'items'      ? $this->salesByItems    : collect(),
            'salesByCategory' => $this->analyticsView === 'categories' ? $this->salesByCategory : collect(),
            'paymentMethods'  => $this->analyticsView === 'payments'   ? $this->paymentMethods  : collect(),
            'chartPayload'    => $this->buildChartPayload(),
        ])->layout('layouts.admin.admin-layout');
    }
}
