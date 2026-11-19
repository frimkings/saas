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
    public ?int $disputingPaymentId = null;
    public string $disputeReason = '';
    /** Agreed terms on a link: trade % (lens, frame, service) and credit limit, as typed. */
    public array $terms = ['lens' => '', 'frame' => '', 'service' => '', 'credit' => ''];
    /** Ordering page for a partner not on Nexus: a PIN typed by the manager (blank: one is made up), and the PIN just set, shown once. */
    public string $pagePin = '';
    public string $shownPin = '';

    protected $queryString = ['search' => ['except' => ''], 'statusFilter' => ['except' => 'active']];

    public function updated($name): void
    {
        if (in_array($name, ['search', 'statusFilter', 'owingOnly'], true)) $this->resetPage();
    }

    public function setStatus(string $status): void
    {
        $this->selected = [];
        abort_unless(in_array($status, ['active', 'archived', 'all'], true), 422);
        $this->statusFilter = $status;
        $this->resetPage();
    }

    public function openPartner(int $id): void
    {
        $this->viewPartnerId = OpticalPartnerClinic::findOrFail($id)->id;
        $this->reset(['pagePin', 'shownPin']);
        $this->showForm = false;
        $this->resetErrorBag();
        $this->fillTerms();
    }

    private function fillTerms(): void
    {
        $partner = $this->viewPartnerId ? OpticalPartnerClinic::find($this->viewPartnerId) : null;
        $show = fn ($value) => $value === null ? '' : rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
        $this->terms = [
            'lens' => $show($partner?->trade_lens_pct), 'frame' => $show($partner?->trade_frame_pct),
            'service' => $show($partner?->trade_service_pct), 'credit' => $partner?->credit_limit === null ? '' : number_format((float) $partner->credit_limit, 2, '.', ''),
        ];
    }

    /** Trade % and credit limit for a linked clinic (App\Services\OpticalLinks\OpticalLinkTermsService). */
    public function saveTerms(): void
    {
        $this->assertManager();
        $this->resetErrorBag();
        $partner = OpticalPartnerClinic::findOrFail((int) $this->viewPartnerId);
        abort_unless($partner->hasLink(), 422);
        app(\App\Services\OpticalLinks\OpticalLinkTermsService::class)->setTerms($partner, $this->terms, auth()->user());
        $this->fillTerms();
        session()->flash('success', 'Terms saved. They apply to new quotes for '.($partner->linkedClinic?->name ?? 'this clinic').', who can see them.');
    }

    public function closePartner(): void
    {
        $this->reset(['pagePin', 'shownPin']);
        $this->viewPartnerId = null;
        $this->showForm = false;
        $this->resetErrorBag();
    }

    /** The panel is closed in the browser (dismissLocal); tidy up when the server hears of it. */
    public function updatedViewPartnerId($value): void
    {
        if ($value === null) $this->resetErrorBag();
        else $this->fillTerms();
    }

    /** The form opens and closes in the browser (openLocal / dismissLocal); tidy up when the server hears it closed. */
    public function updatedShowForm($value): void
    {
        if (! $value) $this->resetErrorBag();
    }

    public function cancelForm(): void
    {
        // Editing returns to the partner's panel; adding closes.
        $this->showForm = false;
        $this->resetErrorBag();
    }

    /** Partners carry billing terms (credit on account), so only managers add, change or archive them. */
    private function assertManager(): void
    {
        abort_unless(\App\Support\OpticalAccess::isManager(auth()->user()), 403);
    }

    public function restore(int $id): void
    {
        $this->assertManager();
        app(ClinicAccessService::class)->assertWritable('optical');
        $partner = OpticalPartnerClinic::findOrFail($id);
        $partner->is_active = true;
        \App\Models\AuditTrail::recordSave($partner, 'optical.partner_clinic', 'partner clinic '.$partner->name.' (restored)');
        session()->flash('success', 'Partner clinic restored for new orders.');
    }

    public function add(): void
    {
        $this->assertManager();
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
        $this->assertManager();
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
        $this->assertManager();
        app(ClinicAccessService::class)->assertWritable('optical');
        // Check the name as it will be saved: " Westside " would pass the unique check, then hit the database's.
        $this->name = trim($this->name);
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
        ], ['name.unique' => 'A partner clinic with this name already exists (it may be archived).']);
        $partner = $this->editingId ? OpticalPartnerClinic::findOrFail($this->editingId) : new OpticalPartnerClinic();
        $partner->fill([
            'name' => trim($this->name), 'contact_person' => trim($this->contactPerson) ?: null,
            'phone' => trim($this->phone) ?: null, 'email' => trim($this->email) ?: null,
            'address' => trim($this->address) ?: null, 'billing_terms' => $this->billingTerms,
            'notification_phone' => trim($this->notificationPhone) ?: null, 'notify_via' => $this->notifyVia,
            'is_active' => $this->isActive,
        ]);
        \App\Models\AuditTrail::recordSave($partner, 'optical.partner_clinic', 'partner clinic '.$partner->name);
        $this->showForm = false;
        $this->viewPartnerId = $partner->id;
        session()->flash('success', 'Partner clinic saved.');
    }

    use Concerns\TicksRows;

    /** Archive every ticked partner (managers). Their orders stay available. */
    public function bulkArchive(): void
    {
        $this->assertManager();
        app(ClinicAccessService::class)->assertWritable('optical');
        $done = [];
        foreach (OpticalPartnerClinic::whereIn('id', $this->tickedIds())->where('is_active', true)->orderBy('name')->get() as $partner) {
            $partner->is_active = false;
            \App\Models\AuditTrail::recordSave($partner, 'optical.partner_clinic', 'partner clinic '.$partner->name.' (archived)');
            $done[] = $partner->name;
        }
        $this->report('Archive', $done, [], [], 'Existing orders remain available.');
    }

    /**
     * This month's statement for every ticked partner, as WhatsApp messages ready to send (one
     * button each). Partners with no WhatsApp number, or who asked not to be contacted, are listed.
     */
    public function bulkStatements(): void
    {
        $service = app(\App\Services\OpticalPartnerAccountService::class);
        [$from, $to] = [now()->startOfMonth(), now()];
        $links = $skipped = [];
        foreach (OpticalPartnerClinic::whereIn('id', $this->tickedIds())->orderBy('name')->get() as $partner) {
            $url = $partner->notify_via === 'none' ? null : \App\Support\Messaging\WhatsAppLink::to($partner->messagingPhone(),
                PartnerStatementComponent::message($partner, $service->statement($partner, $from, $to), $service->balance($partner), $from, $to));
            $url ? $links[] = ['url' => $url, 'label' => $partner->name] : $skipped[] = $partner->name.': '.($partner->notify_via === 'none' ? 'asked not to be contacted' : 'no WhatsApp number');
        }
        $this->bulkReport = ['title' => 'Statements for '.$from->format('M Y'), 'done' => array_map(fn ($link) => $link['label'], $links), 'skipped' => $skipped,
            'note' => $links ? 'Press each button to open its message in WhatsApp, then send.' : null, 'links' => $links];
    }

    public function archive(int $id): void
    {
        $this->assertManager();
        app(ClinicAccessService::class)->assertWritable('optical');
        $partner = OpticalPartnerClinic::findOrFail($id);
        $partner->is_active = false;
        \App\Models\AuditTrail::recordSave($partner, 'optical.partner_clinic', 'partner clinic '.$partner->name.' (archived)');
        session()->flash('success', 'Partner clinic archived. Existing orders remain available.');
    }

    /* ---------- Linking to a clinic on the platform (App\Services\OpticalLinks\OpticalLinkService) ---------- */

    public function createInvite(int $id): void
    {
        $this->assertManager();
        app(ClinicAccessService::class)->assertWritable('optical');
        app(\App\Services\OpticalLinks\OpticalLinkService::class)->createInvite(OpticalPartnerClinic::findOrFail($id), auth()->user());
        $this->viewPartnerId = $id;
        session()->flash('success', 'Invite created. Send the code or link to the clinic’s owner or manager.');
    }

    public function revokeInvite(int $id): void
    {
        $this->assertManager();
        app(\App\Services\OpticalLinks\OpticalLinkService::class)->revokeInvite(OpticalPartnerClinic::findOrFail($id));
        session()->flash('success', 'Invite cancelled.');
    }

    public function setLink(int $id, string $status): void
    {
        $this->assertManager();
        app(\App\Services\OpticalLinks\OpticalLinkService::class)->setLinkStatus(OpticalPartnerClinic::findOrFail($id), $status, auth()->user());
        session()->flash('success', match ($status) {
            'paused' => 'Link paused. The clinic can’t send new orders until you resume it.',
            'active' => 'Link resumed.',
            default => 'Unlinked. Jobs already sent stay on both sides.',
        });
    }

    /* ---------- Ordering page for a partner not on Nexus (App\Services\OpticalLinks\OpticalOrderPageService) ---------- */

    public function setOrderPage(int $id): void
    {
        $this->assertManager();
        $this->resetErrorBag();
        $partner = OpticalPartnerClinic::findOrFail($id);
        $first = ! $partner->hasOrderPage();
        $this->shownPin = app(\App\Services\OpticalLinks\OpticalOrderPageService::class)->enable($partner, auth()->user(), $this->pagePin);
        $this->pagePin = '';
        $this->viewPartnerId = $id;
        session()->flash('success', $first ? 'Ordering page turned on. Send the link and the PIN to the clinic, separately.' : 'New PIN set. The old PIN no longer works.');
    }

    /** Whether the partner's ordering page shows lens prices (after its trade %) in Check lenses. */
    public function toggleOrderPagePrices(int $id): void
    {
        $this->assertManager();
        app(\App\Services\ClinicAccessService::class)->assertWritable();
        $partner = OpticalPartnerClinic::findOrFail($id);
        $partner->forceFill(['page_show_prices' => ! $partner->page_show_prices])->save();
        \App\Models\AuditTrail::record('optical.order_page_prices', ($partner->page_show_prices ? 'Showing' : 'Hiding').' lens prices on the ordering page for '.$partner->name, $partner);
        session()->flash('success', $partner->page_show_prices ? 'Their page now shows lens prices.' : 'Their page no longer shows lens prices.');
    }

    public function newOrderPageLink(int $id): void
    {
        $this->assertManager();
        app(\App\Services\OpticalLinks\OpticalOrderPageService::class)->newLink(OpticalPartnerClinic::findOrFail($id), auth()->user());
        session()->flash('success', 'New link made. The old link no longer works; send the clinic the new one.');
    }

    public function disableOrderPage(int $id): void
    {
        $this->assertManager();
        app(\App\Services\OpticalLinks\OpticalOrderPageService::class)->disable(OpticalPartnerClinic::findOrFail($id), auth()->user());
        $this->shownPin = '';
        session()->flash('success', 'Ordering page turned off. Orders already sent stay in Incoming Orders.');
    }

    /* ---------- Payments a linked clinic recorded (App\Services\OpticalLinks\OpticalLinkPaymentService) ---------- */

    public function confirmLinkPayment(int $id): void
    {
        $payment = \App\Models\OpticalLinkPayment::forOptical((int) app(TenantContext::class)->clinicId())->findOrFail($id);
        $receipt = app(\App\Services\OpticalLinks\OpticalLinkPaymentService::class)->confirm($payment, auth()->user());
        session()->flash('success', 'Payment confirmed as receipt '.$receipt->receipt_number.'. The jobs it covers are settled on the account.');
    }

    public function startDispute(?int $id = null): void
    {
        $this->disputingPaymentId = $id;
        $this->disputeReason = '';
        $this->resetErrorBag();
    }

    public function disputeLinkPayment(): void
    {
        $this->resetErrorBag();
        $payment = \App\Models\OpticalLinkPayment::forOptical((int) app(TenantContext::class)->clinicId())->findOrFail((int) $this->disputingPaymentId);
        app(\App\Services\OpticalLinks\OpticalLinkPaymentService::class)->dispute($payment, $this->disputeReason, auth()->user());
        $this->reset(['disputingPaymentId', 'disputeReason']);
        session()->flash('success', 'The clinic sees that you have not received this payment, and why.');
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
            'tickedCount' => count($this->tickedIds()),
            'canManage' => \App\Support\Access\Access::can('optical.manage'),
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
            'canManage' => \App\Support\OpticalAccess::isManager(auth()->user()),
            'viewAging' => $view ? $accounts->aging($view) : [],
            'viewOrders' => $view ? \App\Models\LensOrder::where('partner_clinic_id', $view->id)->latest()->limit(8)->get() : collect(),
            'viewInvite' => $view ? app(\App\Services\OpticalLinks\OpticalLinkService::class)->openInvite($view) : null,
            'viewLinkOwed' => $view && $view->hasLink() ? \App\Services\OpticalLinks\OpticalLinkTermsService::owed($view) : null,
            'viewLinkPayments' => $view && $view->linked_clinic_id ? app(\App\Services\OpticalLinks\OpticalLinkPaymentService::class)->waitingForOptical($view->id) : collect(),
            'linkPaymentsWaiting' => \App\Services\OpticalLinks\OpticalLinkPaymentService::waitingCount() > 0
                ? \App\Models\OpticalLinkPayment::forOptical((int) app(TenantContext::class)->clinicId())->where('status', 'pending')->pluck('optical_partner_clinic_id')->countBy() : collect(),
        ])->layout('layouts.optical');
    }
}
