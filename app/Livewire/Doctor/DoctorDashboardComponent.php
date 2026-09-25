<?php

namespace App\Livewire\Doctor;

use App\Models\Appointments;
use App\Models\CashierPatientClearance;
use App\Models\Consultations;
use App\Models\Patient;
use App\Models\Referral;
use Carbon\Carbon;
use Livewire\Component;

class DoctorDashboardComponent extends Component
{
    // Row 1 — KPI Cards
    public $awaitingToday;
    public $consultationsToday;
    public $consultationsMonth;
    public $totalPatients;

    // Row 2 — Activity Cards
    public $seenToday;
    public $pendingPrescriptions;
    public $referralsMonth;

    // Row 3 — Chart (past 7 days)
    public $chartLabels;
    public $chartData;

    // Row 4 — Tables
    public $recentConsultations;
    public $todayQueue;

    public function mount(): void
    {
        $doctorId   = auth()->id();
        $today      = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd   = Carbon::now()->endOfMonth();

        // ── Row 1 ─────────────────────────────────────────────────────────
        $this->awaitingToday = CashierPatientClearance::where('doctor_status', 0)
            ->whereDate('clearance_date', $today)
            ->count();

        $this->consultationsToday = Consultations::where('user_id', $doctorId)
            ->whereDate('created_at', $today)
            ->count();

        $this->consultationsMonth = Consultations::whereBetween('created_at', [$monthStart, $monthEnd])
            ->count();

        $this->totalPatients = Patient::count();

        // ── Row 2 ─────────────────────────────────────────────────────────
        $this->seenToday = CashierPatientClearance::where('doctor_status', 1)
            ->whereDate('clearance_date', $today)
            ->count();

        $this->pendingPrescriptions = Consultations::where('user_id', $doctorId)
            ->whereNotNull('prescribed_products')
            ->whereDoesntHave('sale')
            ->count();

        $this->referralsMonth = Referral::where('referred_by', $doctorId)
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->count();

        // ── Chart — my consultations past 7 days ──────────────────────────
        $labels = [];
        $counts = [];
        for ($i = 6; $i >= 0; $i--) {
            $date     = Carbon::today()->subDays($i);
            $labels[] = $date->format('D d');
            $counts[] = Consultations::where('user_id', $doctorId)
                ->whereDate('created_at', $date)
                ->count();
        }
        $this->chartLabels = $labels;
        $this->chartData   = $counts;

        // ── Row 4 — Recent consultations ──────────────────────────────────
        $this->recentConsultations = Consultations::where('user_id', $doctorId)
            ->with('patient:id,name,pxnumber')
            ->latest()
            ->limit(5)
            ->get();

        // ── Row 4 — Today's queue (awaiting) ──────────────────────────────
        $this->todayQueue = CashierPatientClearance::where('doctor_status', 0)
            ->whereDate('clearance_date', $today)
            ->with('patient:id,name,pxnumber,contact')
            ->select('id', 'patient_id', 'clearance_date', 'created_at')
            ->oldest()
            ->limit(8)
            ->get();
    }

    public function render()
    {
        return view('livewire.doctor.doctor-dashboard-component');
    }
}
