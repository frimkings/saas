<?php

namespace App\Livewire\Admin;

use App\Models\CashierPatientClearance;
use App\Models\ClearanceRevokeLog;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class ClearanceRevokeApprovalsComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public string $activeTab  = 'pending';
    public string $search     = '';
    public string $fromDate   = '';
    public string $toDate     = '';

    public ?int   $rejectingLogId    = null;
    public string $rejectionReason   = '';
    public bool   $showRejectModal   = false;

    protected $rules = [
        'rejectionReason' => 'required|string|min:5|max:500',
    ];

    public function mount(): void
    {
        abort_if(
            !auth()->user()?->hasAnyRole(['Manager', 'Super Admin']) &&
            !auth()->user()?->can('approve clearance revoke'),
            403
        );

        $this->fromDate = now()->subDays(30)->toDateString();
        $this->toDate   = now()->toDateString();
    }

    public function updatedSearch(): void  { $this->resetPage(); }
    public function updatedFromDate(): void { $this->resetPage(); }
    public function updatedToDate(): void   { $this->resetPage(); }

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetPage();
    }

    public function approve(int $id): void
    {
        $log = ClearanceRevokeLog::with('clearance')->findOrFail($id);

        if ($log->status !== ClearanceRevokeLog::STATUS_PENDING) {
            $this->dispatch('notify', ...['type' => 'warning', 'message' => 'This request is no longer pending.']);
            return;
        }

        $clearance = $log->clearance;

        if (!$clearance) {
            $log->update([
                'status'      => ClearanceRevokeLog::STATUS_APPROVED,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);
            $this->dispatch('notify', ...['type' => 'info', 'message' => 'Approved — clearance record no longer exists.']);
            return;
        }

        if ($clearance->consultation()->exists()) {
            $this->dispatch('notify', ...[
                'type'    => 'error',
                'message' => 'Cannot approve — a consultation has since been linked to this clearance.',
            ]);
            return;
        }

        DB::transaction(function () use ($log, $clearance) {
            $log->update([
                'status'      => ClearanceRevokeLog::STATUS_APPROVED,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);
            $clearance->delete();
        });

        if ($log->requested_by) {
            NotificationService::send(
                $log->requested_by,
                'clearance_revoke_approved',
                'Revoke Request Approved',
                auth()->user()->name . ' approved your clearance revoke request for ' .
                    optional(optional($clearance)->patient)->name . '.',
                'fas fa-check-circle',
                'text-success',
                route('secretary.patient-clearance')
            );
        }

        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Clearance revoked and requester notified.']);
    }

    public function openRejectModal(int $id): void
    {
        $this->rejectingLogId  = $id;
        $this->rejectionReason = '';
        $this->showRejectModal = true;
        $this->resetErrorBag();
    }

    public function closeRejectModal(): void
    {
        $this->rejectingLogId  = null;
        $this->rejectionReason = '';
        $this->showRejectModal = false;
        $this->resetErrorBag();
    }

    public function confirmReject(): void
    {
        $this->validate(['rejectionReason' => 'required|string|min:5|max:500']);

        $log = ClearanceRevokeLog::with('clearance.patient')->findOrFail($this->rejectingLogId);

        if ($log->status !== ClearanceRevokeLog::STATUS_PENDING) {
            $this->closeRejectModal();
            $this->dispatch('notify', ...['type' => 'warning', 'message' => 'This request is no longer pending.']);
            return;
        }

        $log->update([
            'status'           => ClearanceRevokeLog::STATUS_REJECTED,
            'rejected_by'      => auth()->id(),
            'rejected_at'      => now(),
            'rejection_reason' => $this->rejectionReason,
        ]);

        if ($log->requested_by) {
            NotificationService::send(
                $log->requested_by,
                'clearance_revoke_rejected',
                'Revoke Request Rejected',
                auth()->user()->name . ' rejected your clearance revoke request: ' . $this->rejectionReason,
                'fas fa-times-circle',
                'text-danger',
                route('secretary.patient-clearance')
            );
        }

        $this->closeRejectModal();
        $this->dispatch('notify', ...['type' => 'info', 'message' => 'Revoke request rejected and requester notified.']);
    }

    public function render()
    {
        $pendingCount = ClearanceRevokeLog::pendingCount();

        $query = ClearanceRevokeLog::with(['clearance.patient', 'requestedBy', 'approvedBy', 'rejectedBy'])
            ->when($this->activeTab === 'pending', fn($q) => $q->where('status', ClearanceRevokeLog::STATUS_PENDING))
            ->when($this->activeTab !== 'pending', fn($q) => $q->whereIn('status', [
                ClearanceRevokeLog::STATUS_APPROVED,
                ClearanceRevokeLog::STATUS_REJECTED,
            ]))
            ->when($this->search, fn($q) => $q->where(function ($inner) {
                $inner->where('reason', 'like', '%' . $this->search . '%')
                      ->orWhereHas('clearance.patient', fn($p) =>
                          $p->where('name', 'like', '%' . $this->search . '%')
                      );
            }))
            ->when($this->activeTab !== 'pending', fn($q) =>
                $q->whereBetween('requested_at', [
                    $this->fromDate . ' 00:00:00',
                    $this->toDate   . ' 23:59:59',
                ])
            )
            ->latest('requested_at');

        return view('livewire.admin.clearance-revoke-approvals-component', [
            'logs'         => $query->paginate(15),
            'pendingCount' => $pendingCount,
        ])->layout('layouts.admin.admin-layout');
    }
}
