<?php

namespace App\Livewire\Admin;

use App\Models\ClearanceRevokeLog;
use App\Services\LicenseService;
use App\Support\Feature;
use App\Models\DiscountApprovalRequest;
use App\Models\LensOrder;
use App\Models\PasswordResetRequest;
use App\Models\RefundLog;
use Livewire\Component;

class AllApprovalsComponent extends Component
{
    public string $activeType = 'discount';

    protected $queryString = [
        'activeType' => ['except' => 'discount', 'as' => 'type'],
    ];

    public function mount(): void
    {
        $user = auth()->user();
        abort_if(
            !$user?->hasAnyRole(['Manager', 'Super Admin']) &&
            !$user?->can('manage billing') &&
            !$user?->can('approve clearance revoke'),
            403
        );

        // Default to first tab the user can actually access
        if (!$this->canAccess($this->activeType)) {
            foreach (['discount', 'refund', 'correction', 'revoke', 'password_reset', 'spectacle_renewal'] as $type) {
                if ($this->canAccess($type)) {
                    $this->activeType = $type;
                    break;
                }
            }
        }
        abort_unless($this->canAccess($this->activeType), 403, 'Approval workflows require a Pro plan.');
    }

    public function switchType(string $type): void
    {
        if ($this->canAccess($type)) {
            $this->activeType = $type;
        }
    }

    public function canAccess(string $type): bool
    {
        $user = auth()->user();
        // Without approvals, discounts, refunds, corrections and revokes apply when asked for; the page keeps
        // password resets, and anything left pending from before so it can still be decided.
        if (in_array($type, ['discount', 'refund', 'correction', 'revoke'], true) && !LicenseService::has(Feature::APPROVALS) && !$this->hasPending($type)) {
            return false;
        }
        return match($type) {
            'discount', 'refund'  => $user?->hasAnyRole(['Manager', 'Super Admin']) || $user?->can('manage billing'),
            'revoke'              => $user?->hasAnyRole(['Manager', 'Super Admin']) || $user?->can('approve clearance revoke'),
            'password_reset',
            'correction',
            'spectacle_renewal'  => $user?->hasRole('Super Admin'),
            default              => false,
        };
    }

    private function hasPending(string $type): bool
    {
        return match ($type) {
            'discount' => DiscountApprovalRequest::where('status', DiscountApprovalRequest::STATUS_PENDING)->exists(),
            'refund' => RefundLog::pendingCount() > 0,
            'correction' => \App\Models\PaymentCorrection::pendingCount() > 0,
            'revoke' => ClearanceRevokeLog::pendingCount() > 0,
            default => false,
        };
    }

    public function render()
    {
        $user = auth()->user();

        $counts = [
            'discount'       => $this->canAccess('discount')
                ? DiscountApprovalRequest::where('status', DiscountApprovalRequest::STATUS_PENDING)->count()
                : 0,
            'refund'         => $this->canAccess('refund')
                ? RefundLog::pendingCount()
                : 0,
            'correction'     => $this->canAccess('correction')
                ? \App\Models\PaymentCorrection::pendingCount()
                : 0,
            'revoke'         => $this->canAccess('revoke')
                ? ClearanceRevokeLog::pendingCount()
                : 0,
            'password_reset' => $this->canAccess('password_reset')
                ? PasswordResetRequest::pendingCount()
                : 0,
            'spectacle_renewal' => $this->canAccess('spectacle_renewal')
                ? LensOrder::where('renewal_approval_status', 'pending')->count()
                : 0,
        ];

        return view('livewire.admin.all-approvals-component', [
            'counts' => $counts,
            'total'  => array_sum($counts),
        ])->layout('layouts.admin.admin-layout');
    }
}
