<?php

namespace App\Livewire\Admin;

use App\Models\Branch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Services\SubscriptionService;
use Livewire\Component;

class BranchManagementComponent extends Component
{
    public string $code = '';
    public string $name = '';
    public string $address = '';
    public string $contact = '';
    public string $email = '';
    public string $timezone = 'UTC';

    public function save(): void
    {
        $clinic = $this->context()->requireClinic();
        $allowance = app(SubscriptionService::class)->branchAllowance($clinic);
        if (! $allowance['can_add']) {
            $message = ! $allowance['permitted']
                ? 'Your clinic subscription is not active. Contact the platform administrator.'
                : 'Your subscription branch limit has been reached. Upgrade the plan or purchase another branch.';
            $this->addError('code', $message);
            return;
        }
        $data = $this->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('branches')->where('clinic_id', $clinic->id)],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'timezone' => ['required', 'timezone'],
        ]);
        $branch = $clinic->branches()->create($data + ['is_active' => true, 'receipt_prefix' => strtoupper($data['code'])]);
        $branch->users()->attach(auth()->id(), ['status' => 'active', 'is_default' => false, 'joined_at' => now()]);
        foreach (auth()->user()->roles->pluck('id') as $roleId) {
            DB::table('branch_user_role')->insertOrIgnore([
                'branch_id' => $branch->id, 'user_id' => auth()->id(), 'role_id' => $roleId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->reset(['code', 'name', 'address', 'contact', 'email']);
        $this->dispatch('notify', type: 'success', message: 'Branch created successfully.');
    }

    public function toggle(int $id): void
    {
        $clinic = $this->context()->requireClinic();
        $branch = $clinic->branches()->whereKey($id)->firstOrFail();
        abort_if($branch->is_default && $branch->is_active, 422, 'The default branch cannot be disabled.');
        if (! $branch->is_active && ! app(SubscriptionService::class)->branchAllowance($clinic)['can_add']) {
            $this->dispatch('notify', type: 'error', message: 'Your subscription branch limit has been reached.');
            return;
        }
        $branch->update(['is_active' => ! $branch->is_active]);
    }

    public function render()
    {
        $clinic = $this->context()->requireClinic();
        $allowance = app(SubscriptionService::class)->branchAllowance($clinic);
        return view('livewire.admin.branch-management-component', [
            'clinic' => $clinic,
            'branches' => $clinic->branches()->withCount('users')->orderByDesc('is_default')->orderBy('name')->get(),
            'allowance' => $allowance,
        ])->layout('layouts.admin.admin-layout');
    }

    private function context(): TenantContext
    {
        return app(TenantContext::class)->ensureFor(auth()->user());
    }
}
