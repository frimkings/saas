<?php

namespace App\Livewire\Optical;

use App\Models\OpticalPartnerClinic;
use App\Services\ClinicAccessService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class PartnerClinicsComponent extends Component
{
    use WithPagination;

    public string $search = '';
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $contactPerson = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';
    public string $billingTerms = 'pay_on_order';
    public string $notificationPhone = '';
    public string $notifyVia = 'sms';
    public bool $isActive = true;
    public string $statusFilter = 'active';
    public bool $owingOnly = false;
    public ?int $viewPartnerId = null;

    protected $queryString = ['search' => ['except' => ''], 'statusFilter' => ['except' => 'active']];

    public function updated($name): void
    {
        if (in_array($name, ['search', 'statusFilter', 'owingOnly'], true)) $this->resetPage();
    }

    public function setStatus(string $status): void
    {
        abort_unless(in_array($status, ['active', 'archived', 'all'], true), 422);
        $this->statusFilter = $status;
        $this->resetPage();
    }

    public function openPartner(int $id): void
    {
        $this->viewPartnerId = OpticalPartnerClinic::findOrFail($id)->id;
        $this->showForm = false;
        $this->resetErrorBag();
    }

    public function closePartner(): void
    {
        $this->viewPartnerId = null;
        $this->showForm = false;
        $this->resetErrorBag();
    }

    public function cancelForm(): void
    {
        // Editing returns to the partner's panel; adding closes.
        $this->showForm = false;
        $this->resetErrorBag();
    }

    public function restore(int $id): void
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        OpticalPartnerClinic::findOrFail($id)->update(['is_active' => true]);
        session()->flash('success', 'Partner clinic restored for new orders.');
    }

    public function add(): void
    {
        $this->reset(['editingId', 'name', 'contactPerson', 'phone', 'email', 'address', 'notificationPhone']);
        $this->billingTerms = 'pay_on_order';
        $this->notifyVia = 'sms';
        $this->isActive = true;
        $this->viewPartnerId = null;
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $partner = OpticalPartnerClinic::findOrFail($id);
        $this->editingId = $partner->id;
        $this->name = $partner->name;
        $this->contactPerson = $partner->contact_person ?? '';
        $this->phone = $partner->phone ?? '';
        $this->email = $partner->email ?? '';
        $this->address = $partner->address ?? '';
        $this->billingTerms = $partner->billing_terms;
        $this->notificationPhone = $partner->notification_phone ?? '';
        $this->notifyVia = $partner->notify_via ?: 'sms';
        $this->isActive = $partner->is_active;
        $this->viewPartnerId = $partner->id;
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(): void
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('optical_partner_clinics', 'name')
                ->where('clinic_id', app(TenantContext::class)->clinicId())->ignore($this->editingId)],
            'contactPerson' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:2000',
            'billingTerms' => 'required|in:pay_on_order,on_account',
            'notificationPhone' => 'nullable|string|max:50',
            'notifyVia' => 'required|in:'.implode(',', array_keys(OpticalPartnerClinic::NOTIFY_VIA)),
            'isActive' => 'boolean',
        ]);
        $partner = $this->editingId ? OpticalPartnerClinic::findOrFail($this->editingId) : new OpticalPartnerClinic();
        $partner->fill([
            'name' => trim($this->name), 'contact_person' => trim($this->contactPerson) ?: null,
            'phone' => trim($this->phone) ?: null, 'email' => trim($this->email) ?: null,
            'address' => trim($this->address) ?: null, 'billing_terms' => $this->billingTerms,
            'notification_phone' => trim($this->notificationPhone) ?: null, 'notify_via' => $this->notifyVia,
            'is_active' => $this->isActive,
        ])->save();
        $this->showForm = false;
        $this->viewPartnerId = $partner->id;
        session()->flash('success', 'Partner clinic saved.');
    }

    public function archive(int $id): void
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        OpticalPartnerClinic::findOrFail($id)->update(['is_active' => false]);
        session()->flash('success', 'Partner clinic archived. Existing orders remain available.');
    }

    public function render()
    {
        $accounts = app(\App\Services\OpticalPartnerAccountService::class);
        $all = OpticalPartnerClinic::orderBy('name')->get();
        $owed = $all->mapWithKeys(fn ($partner) => [$partner->id => $accounts->balance($partner)]);
        $term = mb_strtolower(trim($this->search));

        $filtered = $all
            ->when($this->statusFilter === 'active', fn ($c) => $c->where('is_active', true))
            ->when($this->statusFilter === 'archived', fn ($c) => $c->where('is_active', false))
            ->when($this->owingOnly, fn ($c) => $c->filter(fn ($p) => $owed[$p->id] > 0))
            ->when($term !== '', fn ($c) => $c->filter(fn ($p) => str_contains(mb_strtolower(implode(' ', [$p->name, $p->contact_person, $p->phone, $p->email])), $term)))
            ->values();
        $page = $this->getPage();
        $partners = new \Illuminate\Pagination\LengthAwarePaginator($filtered->forPage($page, 10), $filtered->count(), 10, $page, ['path' => request()->url()]);

        // Jobs still in the workshop or waiting for pickup, per partner.
        $jobs = \App\Models\LensOrder::whereNotNull('partner_clinic_id')->whereIn('status', ['Pending', 'Sent to Lab', 'In Production', 'Ready for Collection', 'Ready'])
            ->get(['id', 'partner_clinic_id', 'status'])->groupBy('partner_clinic_id');

        $view = $this->viewPartnerId ? OpticalPartnerClinic::find($this->viewPartnerId) : null;

        return view('livewire.optical.partner-clinics-component', [
            'partners' => $partners,
            'balances' => $owed,
            'jobs' => $jobs,
            'counts' => ['active' => $all->where('is_active', true)->count(), 'archived' => $all->where('is_active', false)->count(), 'all' => $all->count()],
            'totals' => [
                'owed' => $owed->sum(),
                'owing' => $owed->filter(fn ($v) => $v > 0)->count(),
                'inWork' => $jobs->flatten()->whereNotIn('status', ['Ready for Collection', 'Ready'])->count(),
                'ready' => $jobs->flatten()->whereIn('status', ['Ready for Collection', 'Ready'])->count(),
            ],
            'viewPartner' => $view,
            'viewAging' => $view ? $accounts->aging($view) : [],
            'viewOrders' => $view ? \App\Models\LensOrder::where('partner_clinic_id', $view->id)->latest()->limit(8)->get() : collect(),
        ])->layout('layouts.optical');
    }
}
