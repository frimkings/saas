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
        abort_if(!LicenseService::has(Feature::APPROVALS), 403, 'Approval workflows require a Pro license.');
        $user = auth()->user();
        abort_if(
            !$user?->hasAnyRole(['Manager', 'Super Admin']) &&
            !$user?->can('manage billing') &&
            !$user?->can('approve clearance revoke'),
            403
        );

        // Default to first tab the user can actually access
        if (!$this->canAccess($this->activeType)) {
            foreach (['discount', 'refund', 'revoke', 'password_reset', 'spectacle_renewal'] as $type) {
                if ($this->canAccess($type)) {
                    $this->activeType = $type;
                    break;
                }
            }
        }
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
        return match($type) {
            'discount', 'refund'  => $user?->hasAnyRole(['Manager', 'Super Admin']) || $user?->can('manage billing'),
            'revoke'              => $user?->hasAnyRole(['Manager', 'Super Admin']) || $user?->can('approve clearance revoke'),
            'password_reset',
            'spectacle_renewal'  => $user?->hasRole('Super Admin'),
            default              => false,
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
