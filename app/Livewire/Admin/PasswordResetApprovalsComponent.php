<?php

namespace App\Livewire\Admin;

use App\Models\PasswordResetRequest;
use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;

class PasswordResetApprovalsComponent extends Component
{
    use WithPagination;

    public $filterStatus = 'pending';
    public $noteInput    = '';
    public $confirmId    = null;
    public $confirmAction = null; // 'approve' | 'reject'
    /** The code just issued, shown once for the manager to hand over in person. */
    public ?string $issuedCode = null;
    public ?string $issuedFor = null;
    public ?string $issuedUntil = null;

    public function mount(): void
    {
        abort_if(!auth()->user()?->hasRole('Super Admin'), 403);
    }

    public function updatingFilterStatus()
    {
        $this->resetPage();
    }

    public function openConfirm($id, $action)
    {
        $this->confirmId     = $id;
        $this->confirmAction = $action;
        $this->noteInput     = '';
    }

    public function cancelConfirm()
    {
        $this->confirmId     = null;
        $this->confirmAction = null;
        $this->noteInput     = '';
    }

    public function execute()
    {
        abort_if(!auth()->user()?->hasRole('Super Admin'), 403);
        $req = PasswordResetRequest::findOrFail($this->confirmId);

        if ($this->confirmAction === 'approve') {
            $req->update([
                'status'      => 'approved',
                'approved_by' => auth()->id(),
                'admin_note'  => $this->noteInput ?: null,
                'actioned_at' => now(),
            ]);
            $this->showCode($req);
        } else {
            $req->update([
                'status'      => 'rejected',
                'approved_by' => auth()->id(),
                'admin_note'  => $this->noteInput ?: null,
                'actioned_at' => now(),
            ]);
            $this->dispatch('notify', ...['type' => 'error', 'message' => "Reset request for {$req->email} has been rejected."]);
        }

        $this->cancelConfirm();
    }

    /** A fresh code for an approved request (the first was lost, ran out, or came before codes). */
    public function newCode($id): void
    {
        abort_if(!auth()->user()?->hasRole('Super Admin'), 403);
        $req = PasswordResetRequest::where('status', 'approved')->findOrFail($id);
        $this->showCode($req);
    }

    public function closeCode(): void
    {
        $this->reset('issuedCode', 'issuedFor', 'issuedUntil');
    }

    private function showCode(PasswordResetRequest $req): void
    {
        $code = $req->issueCode();
        $this->issuedCode = substr($code, 0, 3).' '.substr($code, 3);
        $this->issuedFor = $req->email;
        $this->issuedUntil = PasswordResetRequest::CODE_MINUTES.' minutes';
    }

    public function render()
    {
        $requests = PasswordResetRequest::with('actionedBy')
            ->when($this->filterStatus, fn($q) => $q->where('status', $this->filterStatus))
            ->latest()
            ->paginate(15);

        $counts = [
            'pending'   => PasswordResetRequest::where('status', 'pending')->count(),
            'approved'  => PasswordResetRequest::where('status', 'approved')->count(),
            'rejected'  => PasswordResetRequest::where('status', 'rejected')->count(),
            'completed' => PasswordResetRequest::where('status', 'completed')->count(),
        ];

        return view('livewire.admin.password-reset-approvals-component', compact('requests', 'counts'))
            ->layout('layouts.admin.admin-layout');
    }
}
