<?php

namespace App\Livewire\Admin;

use App\Models\Appointments;
use App\Models\Consultations;
use App\Models\DiscountApprovalRequest;
use App\Models\ClearanceRevokeLog;
use App\Models\Expense;
use App\Models\LoginLog;
use App\Models\Patient;
use App\Models\Product;
use App\Models\RefundLog;
use App\Models\Sales;
use App\Models\SaleItem;
use App\Models\User;
use App\Support\BusinessLine;
use App\Support\FinanceStatements;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class AdminDashboardComponent extends Component
{
    public const COMBINED = 'combined';

    /** Which business the money figures cover: clinic, optical, or combined. Chosen with ?line=. */
    #[Locked]
    public string $line = self::COMBINED;
    #[Locked]
    public array $lineOptions = [];

    // Row 1 — Top KPIs
    public $totalPatients;
    public $todayRevenue;
    public $todayAppointments;
    public $productsInStock;

    // Row 2 — Financial
    public $monthRevenue;
    public $outstandingCount;
    public $pendingDiscounts;
    public $pendingApprovals;
    public $monthExpenses;

    // Row 3 — Revenue chart (plain PHP arrays; @json renders them in JS)
    public $revenueChartLabels;
    public $revenueChartData;

    // Row 4 — Clinic
    public $newPatientsMonth;
    public $consultationsToday;
    public $pendingPrescriptions;

    // Row 5 — Inventory
    public $lowStockCount;
    public $outOfStockCount;
    public $expiringSoonCount;
    public $expiredCount;

    // Row 6 — Tables
    public $topProducts;
    public $recentLogins;

    // Row 7 — Bottom
    public $totalActiveUsers;

    /** Sales of the chosen business (or both). */
    private function lineSales()
    {
        return Sales::query()->when($this->line !== self::COMBINED, fn ($q) => $q->where('business_line', $this->line));
    }

    private function lineExpenses()
    {
        return Expense::query()->when($this->line !== self::COMBINED, fn ($q) => $q->where('business_line', $this->line));
    }

    public function mount(): void
    {
        // Single-business subscribers only see their own; both businesses default to the whole business.
        $lines = FinanceStatements::availableLines();
        $this->lineOptions = count($lines) === 1 ? $lines : [...$lines, self::COMBINED];
        $requested = (string) request()->query('line', '');
        $this->line = in_array($requested, $this->lineOptions, true) ? $requested
            : (count($this->lineOptions) === 1 ? $this->lineOptions[0] : self::COMBINED);

        $today      = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd   = Carbon::now()->endOfMonth();

        // ── Row 1 ────────────────────────────────────────────────────────
        $this->totalPatients = Patient::count();

        // total_amount = invoice value of sales (matches Reports page "Net Revenue")
        $this->todayRevenue = $this->lineSales()->whereDateIndexed('created_at', $today)
            ->where('is_refunded', false)
            ->sum('total_amount');

        $this->todayAppointments = Appointments::whereDateIndexed('scheduled_at', $today)
            ->whereNotIn('status', ['cancelled'])
            ->count();

        $this->productsInStock = Product::inStock()->count();

        // ── Row 2 ────────────────────────────────────────────────────────
        $this->monthRevenue = $this->lineSales()->whereBetween('created_at', [$monthStart, $monthEnd])
            ->where('is_refunded', false)
            ->sum('total_amount');

        $this->outstandingCount = $this->lineSales()->where('payment_status', 'partial')
            ->where('is_refunded', false)
            ->count();

        // Shared with the sidebar badge, so the page counts them once (cached a minute).
        $pending = \App\Support\ApprovalCounts::pending();
        $this->pendingDiscounts = $pending['discount'];
        $this->pendingApprovals = $pending['discount'] + $pending['refund'] + $pending['revoke'];

        $this->monthExpenses = $this->lineExpenses()->whereBetween('expense_date', [
            $monthStart->toDateString(),
            Carbon::now()->toDateString(),
        ])->sum('amount');

        // ── Chart — past 7 days (single GROUP BY query instead of 7 loops) ──
        $window = Carbon::today()->subDays(6)->startOfDay();
        $dailyRows = $this->lineSales()->selectRaw('DATE(created_at) as day, SUM(total_amount) as total')
            ->where('created_at', '>=', $window)
            ->where('is_refunded', false)
            ->groupBy('day')
            ->pluck('total', 'day');

        $labels = $revenues = [];
        for ($i = 6; $i >= 0; $i--) {
            $date       = Carbon::today()->subDays($i);
            $labels[]   = $date->format('D d');
            $revenues[] = (float) ($dailyRows[$date->toDateString()] ?? 0);
        }
        $this->revenueChartLabels = $labels;
        $this->revenueChartData   = $revenues;

        // ── Row 4 ────────────────────────────────────────────────────────
        $this->newPatientsMonth = Patient::whereBetween('created_at', [$monthStart, $monthEnd])
            ->count();

        $this->consultationsToday = Consultations::whereDateIndexed('created_at', $today)->count();

        // scopePendingDispensing references a non-existent 'products' column via scopeWithPrescriptions,
        // so query directly on the column that actually exists in the table.
        // Cached 5 min — whereDoesntHave generates a correlated subquery; expensive at scale.
        $this->pendingPrescriptions = Cache::remember(\App\Support\Tenancy\TenantCache::key('dashboard_pending_prescriptions', true), 300, fn () =>
            Consultations::whereNotNull('prescribed_products')
                ->whereDoesntHave('sale')
                ->count()
        );

        // ── Row 5 — inventory counts cached 5 min (stock levels don't change per-second) ──
        [
            $this->lowStockCount,
            $this->outOfStockCount,
            $this->expiringSoonCount,
            $this->expiredCount,
        ] = Cache::remember(\App\Support\Tenancy\TenantCache::key('dashboard_inventory_counts', true), 300, function () use ($today) {
            return [
                Product::lowStock()->count(),
                Product::outOfStock()->count(),
                Product::stocked()->whereNotNull('expiry_date')
                    ->whereDateIndexed('expiry_date', '>=', $today)
                    ->whereDateIndexed('expiry_date', '<=', Carbon::today()->addDays(90))
                    ->count(),
                Product::stocked()->whereNotNull('expiry_date')
                    ->whereDateIndexed('expiry_date', '<', $today)
                    ->count(),
            ];
        });

        // ── Row 6 ────────────────────────────────────────────────────────
        // Clinic items are products, optical items are optical products; merged for the whole business.
        $topItems = function (string $line, string $column, string $relation) use ($monthStart, $monthEnd) {
            return SaleItem::select(
                    $column,
                    DB::raw('SUM(dispensed_quantity) as total_qty'),
                    DB::raw('SUM(subtotal) as total_revenue')
                )
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->where('sales.business_line', $line)
                ->whereNotNull('sale_items.'.$column)
                ->whereNull('sales.deleted_at')
                ->where('sales.is_refunded', false)
                ->whereBetween('sales.created_at', [$monthStart, $monthEnd])
                ->whereNull('sale_items.deleted_at')
                ->groupBy($column)
                ->orderByDesc('total_revenue')
                ->limit(5)
                ->with($relation.':id,name')
                ->get()
                ->map(fn ($item) => ['name' => $item->{$relation}?->name ?? '—', 'total_qty' => (int) $item->total_qty,
                    'total_revenue' => (float) $item->total_revenue, 'line' => $line]);
        };
        $top = collect();
        if ($this->line !== BusinessLine::OPTICAL) $top = $top->concat($topItems(BusinessLine::CLINIC, 'product_id', 'product'));
        if ($this->line !== BusinessLine::CLINIC) $top = $top->concat($topItems(BusinessLine::OPTICAL, 'optical_product_id', 'opticalProduct'));
        $this->topProducts = $top->sortByDesc('total_revenue')->take(5)->values()->all();

        // 'role' is not a DB column — roles are Spatie pivot-based
        $this->recentLogins = LoginLog::with(['user' => fn($q) => $q->select('id', 'name')->with('roles:id,name')])
            ->latest('login_at')
            ->limit(5)
            ->get();

        // ── Row 7 ────────────────────────────────────────────────────────
        $this->totalActiveUsers = User::where('is_active', true)->count();
    }

    public function render()
    {
        $user = auth()->user();
        $optical = $this->line === BusinessLine::OPTICAL;

        return view('livewire.admin.admin-dashboard-component', [
            'lineLabels' => [BusinessLine::CLINIC => 'Clinic', BusinessLine::OPTICAL => 'Optical', self::COMBINED => 'Combined'],
            // Where each money card leads for the chosen business.
            'statementLink' => match (true) {
                $this->line === self::COMBINED && FinanceStatements::canViewCombined($user) => ['Combined Statement', route('admin.combined-statement')],
                $optical => ['Profit & Loss', route('optical.profit')],
                default => ['Income Statement', route('admin.income-statement')],
            },
            'expensesLink' => $optical ? route('optical.expenses') : route('admin.expenses'),
            'outstandingLink' => $optical ? route('optical.sales') : route('cashier.outstanding-balances'),
            // The cash summary is a clinic page; an optical-only shop uses its sales records.
            'todayLink' => \App\Support\OpticalMode::opticalOnly() ? route('optical.sales') : route('admin.daily-cash-summary', ['line' => $this->line]),
        ]);
    }
}
