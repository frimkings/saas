<?php

namespace App\Livewire\Secretary;

use App\Models\Appointments;
use App\Models\CashierPatientClearance;
use App\Models\LensOrder;
use App\Models\Patient;
use App\Models\Sales;
use Carbon\Carbon;
use Livewire\Component;

class SecretaryDashboardComponent extends Component
{
    // Row 1 — Top KPIs
    public $patientsRegisteredToday;
    public $appointmentsToday;
    public $clearancesToday;
    public $todaySales;

    // Row 2 — Secondary stats
    public $totalPatients;
    public $outstandingBalances;
    public $awaitingDoctor;
    public $renewalsDue;
    public $spectaclesReady;

    // Chart — patient registrations past 7 days
    public $chartLabels;
    public $chartData;

    // Tables
    public $todayQueue;
    public $upcomingAppointments;

    public function mount(): void
    {
        $today      = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd   = Carbon::now()->endOfMonth();

        // ── Row 1 ────────────────────────────────────────────────────────
        $this->patientsRegisteredToday = Patient::whereDate('created_at', $today)->count();

        $this->appointmentsToday = Appointments::whereDate('scheduled_at', $today)
            ->whereNotIn('status', ['cancelled'])
            ->count();

        $this->clearancesToday = CashierPatientClearance::whereDate('clearance_date', $today)->count();

        $this->todaySales = Sales::whereDate('created_at', $today)
            ->where('is_refunded', false)
            ->sum('total_amount');

        // ── Row 2 ────────────────────────────────────────────────────────
        $this->totalPatients = Patient::count();

        $this->outstandingBalances = Sales::where('payment_status', 'partial')
            ->where('is_refunded', false)
            ->count();

        $this->awaitingDoctor = CashierPatientClearance::where('doctor_status', 0)
            ->whereDate('clearance_date', $today)
            ->count();

        $this->renewalsDue = LensOrder::where('status', 'Collected')
            ->whereNotNull('renewal_date')
            ->whereDate('renewal_date', '<=', Carbon::now()->addDays(30)->toDateString())
            ->whereNull('renewal_reminder_sent_at')
            ->count();

        $this->spectaclesReady = LensOrder::where('status', 'Ready')->count();

        // ── Chart — new patients past 7 days (single grouped query) ──────
        $chartStart = Carbon::today()->subDays(6)->startOfDay();
        $rawCounts  = Patient::whereBetween('created_at', [$chartStart, Carbon::now()])
            ->selectRaw('DATE(created_at) as reg_date, COUNT(*) as cnt')
            ->groupBy('reg_date')
            ->pluck('cnt', 'reg_date');

        $labels = [];
        $counts = [];
        for ($i = 6; $i >= 0; $i--) {
            $date     = Carbon::today()->subDays($i);
            $labels[] = $date->format('D d');
            $counts[] = (int) $rawCounts->get($date->format('Y-m-d'), 0);
        }
        $this->chartLabels = $labels;
        $this->chartData   = $counts;

        // ── Tables ───────────────────────────────────────────────────────
        $this->todayQueue = CashierPatientClearance::whereDate('clearance_date', $today)
            ->with('patient:id,name,pxnumber,contact')
            ->latest()
            ->limit(8)
            ->get();

        $this->upcomingAppointments = Appointments::whereDate('scheduled_at', $today)
            ->whereNotIn('status', ['cancelled'])
            ->with('patient:id,name,pxnumber')
            ->orderBy('scheduled_at')
            ->limit(8)
            ->get();
    }

    public function render()
    {
        return view('livewire.secretary.secretary-dashboard-component');
    }
}
