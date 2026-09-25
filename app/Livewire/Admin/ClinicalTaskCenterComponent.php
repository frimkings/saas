<?php

namespace App\Livewire\Admin;

use App\Models\Appointments;
use App\Models\Cart;
use App\Models\CashierPatientClearance;
use App\Models\ClearanceRevokeLog;
use App\Models\DiscountApprovalRequest;
use App\Models\LensOrder;
use App\Models\PasswordResetRequest;
use App\Models\Product;
use App\Models\RefundLog;
use App\Models\ReportDelivery;
use App\Models\Sales;
use App\Models\SmsLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class ClinicalTaskCenterComponent extends Component
{
    public function refreshTasks(): void
    {
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Task center refreshed.']);
    }

    public function render()
    {
        $today = Carbon::today();
        $now = now();

        $awaitingQuery = CashierPatientClearance::with(['patient', 'service'])
            ->where('payment_status', 'Paid')
            ->where('doctor_status', false);

        $overdueAppointmentsQuery = Appointments::with('patient')
            ->where('scheduled_at', '<', $now)
            ->whereNotIn('status', ['Seen', 'Done', 'Cancelled', 'Canceled', 'Missed']);

        $missedAppointmentsQuery = Appointments::with('patient')
            ->where(function ($query) {
                $query->where('status', 'Missed')->orWhereNotNull('missed_at');
            });

        $pendingPrescriptionQuery = Cart::with(['patient', 'product', 'consultation.doctor'])
            ->where('status', 'pending')
            ->where('purchased', false)
            ->whereNotNull('consultation_id')
            ->where('consultation_id', '>', 0);

        $outstandingQuery = Sales::with('patient')
            ->where('is_refunded', false)
            ->where(function ($query) {
                $query->whereIn('payment_status', ['partial', 'pending'])
                    ->orWhereColumn('amount_paid', '<', 'total_amount');
            });

        $lowStockQuery = Product::with('category')->where('quantity', '>', 0)->where('quantity', '<=', 10);
        $outOfStockQuery = Product::with('category')->where('quantity', 0);
        $expiredQuery = Product::with('category')->whereNotNull('expiry_date')->whereDate('expiry_date', '<', $today);
        $expiringQuery = Product::with('category')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', $today)
            ->whereDate('expiry_date', '<=', $today->copy()->addDays(30));

        $reportDeliveryQuery = ReportDelivery::whereIn('status', [
            ReportDelivery::STATUS_PENDING,
            ReportDelivery::STATUS_FAILED,
        ]);

        $failedSmsQuery = SmsLog::with('patient')->where('success', false);

        $approvalCounts = [
            'discount' => DiscountApprovalRequest::where('status', DiscountApprovalRequest::STATUS_PENDING)->count(),
            'refund' => RefundLog::pendingCount(),
            'clearance' => ClearanceRevokeLog::pendingCount(),
            'password' => PasswordResetRequest::pendingCount(),
            'spectacle' => LensOrder::where('renewal_approval_status', 'pending')->count(),
        ];

        $summaryCards = [
            [
                'label' => 'Patients Awaiting',
                'count' => $awaitingQuery->count(),
                'tone' => 'primary',
                'icon' => 'fa-user-clock',
                'route' => route('doctor.patient-awaiting'),
                'hint' => 'Paid clearances waiting for consultation',
            ],
            [
                'label' => 'Overdue / Missed',
                'count' => $overdueAppointmentsQuery->count() + $missedAppointmentsQuery->count(),
                'tone' => 'danger',
                'icon' => 'fa-calendar-times',
                'route' => route('secretary.appointments'),
                'hint' => 'Appointments needing follow-up',
            ],
            [
                'label' => 'Prescriptions',
                'count' => $pendingPrescriptionQuery->count(),
                'tone' => 'info',
                'icon' => 'fa-prescription-bottle-alt',
                'route' => route('cashier.seller-desk'),
                'hint' => 'Pending prescribed items',
            ],
            [
                'label' => 'Approvals',
                'count' => array_sum($approvalCounts),
                'tone' => 'warning',
                'icon' => 'fa-check-double',
                'route' => route('admin.approvals'),
                'hint' => 'Manager or admin decisions',
            ],
            [
                'label' => 'Inventory Alerts',
                'count' => $lowStockQuery->count() + $outOfStockQuery->count() + $expiredQuery->count() + $expiringQuery->count(),
                'tone' => 'purple',
                'icon' => 'fa-box-open',
                'route' => route('admin.inventory-alerts'),
                'hint' => 'Low, expired, or expiring stock',
            ],
            [
                'label' => 'Outstanding',
                'count' => $outstandingQuery->count(),
                'tone' => 'secondary',
                'icon' => 'fa-file-invoice-dollar',
                'route' => route('cashier.outstanding-balances'),
                'hint' => currency() . number_format((float) $outstandingQuery->sum(DB::raw('GREATEST(total_amount - amount_paid, 0)')), 2) . ' uncollected',
            ],
            [
                'label' => 'Report Outbox',
                'count' => $reportDeliveryQuery->count(),
                'tone' => 'teal',
                'icon' => 'fa-paper-plane',
                'route' => route('admin.settings', ['tab' => 'report']),
                'hint' => 'Pending or failed report delivery',
            ],
            [
                'label' => 'Failed SMS',
                'count' => $failedSmsQuery->count(),
                'tone' => 'dark',
                'icon' => 'fa-comment-slash',
                'route' => route('admin.sms-logs'),
                'hint' => 'Messages that did not send',
            ],
        ];

        return view('livewire.admin.clinical-task-center-component', [
            'summaryCards' => $summaryCards,
            'awaitingPatients' => $awaitingQuery->latest()->limit(6)->get(),
            'overdueAppointments' => $overdueAppointmentsQuery->orderBy('scheduled_at')->limit(6)->get(),
            'pendingPrescriptions' => $pendingPrescriptionQuery->latest()->limit(6)->get(),
            'outstandingSales' => $outstandingQuery->latest()->limit(6)->get(),
            'reportDeliveries' => $reportDeliveryQuery->latest()->limit(5)->get(),
            'failedSmsLogs' => $failedSmsQuery->latest()->limit(5)->get(),
            'approvalCounts' => $approvalCounts,
            'inventoryCounts' => [
                'low' => $lowStockQuery->count(),
                'out' => $outOfStockQuery->count(),
                'expired' => $expiredQuery->count(),
                'expiring' => $expiringQuery->count(),
            ],
        ])->layout('layouts.admin.admin-layout');
    }
}
