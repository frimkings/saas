<?php

namespace App\Livewire\Cashier;

use App\Models\CashierPatientClearance;
use App\Models\Sales;
use Carbon\Carbon;
use Livewire\Component;

class CashierDashboardComponent extends Component
{
    // Row 1 — Top KPIs
    public $todaySales;
    public $transactionsToday;
    public $outstandingCount;
    public $queueSize;

    // Row 2 — Secondary stats
    public $monthSales;
    public $paidToday;
    public $partialToday;

    // Chart — 7-day daily sales
    public $chartLabels;
    public $chartData;

    // Table — today's pending queue
    public $todayQueue;

    public function mount(): void
    {
        abort_if(
            !auth()->user()?->hasAnyRole(['Cashier', 'Secretary', 'Manager', 'Super Admin']),
            403
        );

        $today      = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd   = Carbon::now()->endOfMonth();

        // ── Row 1 ────────────────────────────────────────────────────────
        $this->todaySales = Sales::clinicSales()->whereDate('created_at', $today)
            ->where('is_refunded', false)
            ->sum('total_amount');

        $this->transactionsToday = Sales::clinicSales()->whereDate('created_at', $today)->count();

        $this->outstandingCount = Sales::clinicSales()->where('payment_status', 'partial')
            ->where('is_refunded', false)
            ->count();

        $this->queueSize = CashierPatientClearance::where('doctor_status', 0)
            ->whereDate('clearance_date', $today)
            ->count();

        // ── Row 2 ────────────────────────────────────────────────────────
        $this->monthSales = Sales::clinicSales()->whereBetween('created_at', [$monthStart, $monthEnd])
            ->where('is_refunded', false)
            ->sum('total_amount');

        $this->paidToday = Sales::clinicSales()->whereDate('created_at', $today)
            ->where('payment_status', 'paid')
            ->count();

        $this->partialToday = Sales::clinicSales()->whereDate('created_at', $today)
            ->where('payment_status', '!=', 'paid')
            ->where('is_refunded', false)
            ->count();

        // ── Chart — daily sales past 7 days ──────────────────────────────
        $labels  = [];
        $totals  = [];
        for ($i = 6; $i >= 0; $i--) {
            $date     = Carbon::today()->subDays($i);
            $labels[] = $date->format('D d');
            $totals[] = (float) Sales::clinicSales()->whereDate('created_at', $date)
                ->where('is_refunded', false)
                ->sum('total_amount');
        }
        $this->chartLabels = $labels;
        $this->chartData   = $totals;

        // ── Table ─────────────────────────────────────────────────────────
        $this->todayQueue = CashierPatientClearance::where('doctor_status', 0)
            ->whereDate('clearance_date', $today)
            ->with('patient:id,name,pxnumber')
            ->latest()
            ->limit(8)
            ->get();
    }

    public function render()
    {
        return view('livewire.cashier.cashier-dashboard-component')
            ->layout('layouts.admin.admin-layout');
    }
}
