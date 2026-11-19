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
    /** What happened to the emailed link: sent, skipped or failed. */
    public ?string $linkStatus = null;

    public function mount(): void
    {
        abort_if(!\App\Support\Access\Access::can('approve.password_resets'), 403);
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
        abort_if(!\App\Support\Access\Access::can('approve.password_resets'), 403);
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
        abort_if(!\App\Support\Access\Access::can('approve.password_resets'), 403);
        $req = PasswordResetRequest::where('status', 'approved')->findOrFail($id);
        $this->showCode($req);
    }

    public function closeCode(): void
    {
        $this->reset('issuedCode', 'issuedFor', 'issuedUntil', 'linkStatus');
    }

    private function showCode(PasswordResetRequest $req): void
    {
        $code = $req->issueCode();
        $this->issuedCode = substr($code, 0, 3).' '.substr($code, 3);
        $this->issuedFor = $req->email;
        $this->issuedUntil = PasswordResetRequest::CODE_MINUTES.' minutes';
        $this->linkStatus = $this->emailLink($req);
    }

    /** Email the staff member a one-time link to their own inbox; the code stays the fallback. */
    private function emailLink(PasswordResetRequest $req): string
    {
        $token = $req->issueLink();
        $user = User::where('email', $req->email)->first();
        $clinic = app(\App\Support\Tenancy\TenantContext::class)->clinic();
        $name = $clinic?->name ?? config('app.name');
        $mail = fn () => new \App\Mail\OwnerNoticeMail($name, 'Reset your password',
            ($user?->name ? "Hello {$user->name}. " : '').'Your manager approved your password reset. Open the button below to choose a new password.',
            ['Account' => $req->email, 'Link works for' => PasswordResetRequest::LINK_MINUTES.' minutes, once'],
            'Choose a new password', route('password.offline.link', $token),
            'If you did not ask for this, ignore this email and tell your manager.',
            'Sent to you because a password reset for your account was approved.');
        $key = 'password-reset-link:'.$req->id.':'.now()->format('YmdHis');

        if ($clinic) {
            return app(\App\Services\OwnerMailer::class)->sendToAddress($clinic, $req->email, 'password_reset_link', $key, 'Reset your password - '.$name, $mail);
        }
        try {
            \Illuminate\Support\Facades\Mail::to($req->email)->send($mail()->subject('Reset your password - '.$name));
            return 'sent';
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Password reset link email failed.', ['error' => $e->getMessage()]);
            return 'failed';
        }
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
