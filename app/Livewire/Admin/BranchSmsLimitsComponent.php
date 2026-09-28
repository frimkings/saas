<?php

namespace App\Livewire\Admin;

use App\Models\Branch;
use App\Services\Messaging\BranchSmsLimits;
use App\Services\Messaging\SmsCreditService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Settings > SMS (and optical Settings): how much of the clinic's SMS each branch may use.
 * Only the owner (Super Admin) changes limits; see App\Services\Messaging\BranchSmsLimits.
 */
class BranchSmsLimitsComponent extends Component
{
    /** Shown on the optical Settings page (optical styling). */
    #[Locked]
    public bool $optical = false;

    /** Branch id => SMS to add / new exact limit, as typed. */
    public array $add = [];
    public array $limit = [];

    public function mount(): void
    {
        $this->authorizeOwner();
    }

    public function addSms(int $branchId, BranchSmsLimits $limits): void
    {
        $this->authorizeOwner();
        $parts = (int) ($this->add[$branchId] ?? 0);
        $this->run(fn () => $limits->add($this->branch($branchId), $parts), "add.$branchId",
            fn ($branch) => "Added {$parts} SMS for {$branch->name}. It has " . ($branch->sms_limit - $branch->sms_used) . ' left.');
        unset($this->add[$branchId]);
    }

    public function setLimit(int $branchId, BranchSmsLimits $limits): void
    {
        $this->authorizeOwner();
        $value = trim((string) ($this->limit[$branchId] ?? ''));
        if (! ctype_digit($value)) {
            $this->addError("limit.$branchId", 'Enter a whole number, 0 or more.');
            return;
        }
        $this->run(fn () => $limits->set($this->branch($branchId), (int) $value), "limit.$branchId",
            fn ($branch) => "{$branch->name} can now use {$branch->sms_limit} SMS in total ({$branch->sms_used} used so far).");
        unset($this->limit[$branchId]);
    }

    public function removeLimit(int $branchId, BranchSmsLimits $limits): void
    {
        $this->authorizeOwner();
        $this->run(fn () => $limits->set($this->branch($branchId), null), "limit.$branchId",
            fn ($branch) => "{$branch->name} has no SMS limit now; it can use the clinic's credits freely.");
    }

    public function render()
    {
        $clinic = app(TenantContext::class)->requireClinic();

        return view($this->optical ? 'livewire.optical.branch-sms-limits-panel' : 'livewire.admin.branch-sms-limits-component', [
            'branches' => $clinic->branches()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(),
            'credits' => app(SmsCreditService::class)->balance($clinic->id),
            'hosted' => app(\App\Services\ClinicAccessService::class)->hosted($clinic),
        ]);
    }

    private function run(callable $change, string $errorKey, callable $message): void
    {
        $this->resetErrorBag();
        try {
            $branch = $change();
        } catch (ValidationException $e) {
            $this->addError($errorKey, collect($e->errors())->flatten()->first());
            return;
        }
        $this->dispatch('notify', type: 'success', message: $message($branch));
    }

    private function branch(int $branchId): Branch
    {
        return Branch::where('clinic_id', app(TenantContext::class)->requireClinic()->id)->findOrFail($branchId);
    }

    private function authorizeOwner(): void
    {
        abort_unless(auth()->user()?->hasRole('Super Admin'), 403);
    }
}
