<?php

namespace App\Livewire\Secretary;

use App\Models\OpticalOrderRequest;
use App\Services\ClinicAccessService;
use App\Services\OpticalLinks\OpticalLinkService;
use App\Support\Tenancy\TenantContext;
use Livewire\Component;
use Livewire\WithPagination;

/** Spectacle orders this clinic sent to linked optical centres, and where each one is. */
class SentToOpticalComponent extends Component
{
    use WithPagination;

    public string $tab = 'open';
    public string $search = '';
    public ?int $answeringId = null;
    public string $answer = '';
    public ?int $decliningId = null;
    public string $declineReason = '';
    public ?int $remakeId = null;
    public string $remakeReason = '';
    public string $remakeNote = '';

    protected $queryString = ['tab' => ['except' => 'open'], 'search' => ['except' => '']];

    public const TABS = ['open' => ['sent', 'query', 'quoted', 'approved'], 'accepted' => ['accepted'], 'closed' => ['rejected', 'cancelled', 'quote_declined']];

    public function setTab(string $tab): void
    {
        abort_unless(array_key_exists($tab, self::TABS), 422);
        $this->tab = $tab;
        $this->answeringId = null;
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function startAnswer(int $id): void
    {
        $this->answeringId = $id;
        $this->answer = '';
        $this->resetErrorBag();
    }

    public function sendAnswer(): void
    {
        app(ClinicAccessService::class)->assertWritable();
        $request = OpticalOrderRequest::forSender($this->clinicId())->findOrFail((int) $this->answeringId);
        app(OpticalLinkService::class)->answerQuery($request, $this->answer, auth()->user());
        $this->reset(['answeringId', 'answer']);
        session()->flash('success', 'Answer sent. The optical centre can now accept the order.');
    }

    public function cancel(int $id): void
    {
        app(ClinicAccessService::class)->assertWritable();
        $request = OpticalOrderRequest::forSender($this->clinicId())->findOrFail($id);
        app(OpticalLinkService::class)->cancel($request, auth()->user());
        session()->flash('success', 'Request cancelled. The refraction is back in Refractions Needing Order.');
    }

    public function approveQuote(int $id): void
    {
        $request = OpticalOrderRequest::forSender($this->clinicId())->findOrFail($id);
        app(OpticalLinkService::class)->approveQuote($request, auth()->user());
        session()->flash('success', 'Quote approved. The optical centre starts the job and bills it to your account at this price.');
    }

    public function startDecline(?int $id = null): void
    {
        $this->decliningId = $id;
        $this->declineReason = '';
        $this->resetErrorBag();
    }

    public function declineQuote(): void
    {
        $this->resetErrorBag();
        $request = OpticalOrderRequest::forSender($this->clinicId())->findOrFail((int) $this->decliningId);
        app(OpticalLinkService::class)->declineQuote($request, $this->declineReason, auth()->user());
        $this->reset(['decliningId', 'declineReason']);
        session()->flash('success', 'Quote declined. The refraction is back in Refractions Needing Order.');
    }

    public function askNewQuote(int $id): void
    {
        $request = OpticalOrderRequest::forSender($this->clinicId())->findOrFail($id);
        app(OpticalLinkService::class)->askNewQuote($request, auth()->user());
        session()->flash('success', 'Sent back to the optical centre for a new quote.');
    }

    public function startRemake(?int $id = null): void
    {
        $this->remakeId = $id;
        $this->remakeReason = '';
        $this->remakeNote = '';
        $this->resetErrorBag();
    }

    public function sendRemake(): void
    {
        $this->resetErrorBag();
        $request = OpticalOrderRequest::forSender($this->clinicId())->findOrFail((int) $this->remakeId);
        app(OpticalLinkService::class)->requestRemake($request, $this->remakeReason, $this->remakeNote, auth()->user());
        $this->reset(['remakeId', 'remakeReason', 'remakeNote']);
        $this->tab = 'open';
        session()->flash('success', 'Remake asked for. The optical centre decides whether it is free or charged; a charged one comes to you as a quote.');
    }

    private function clinicId(): int
    {
        return (int) app(TenantContext::class)->clinicId();
    }

    public function render()
    {
        $base = OpticalOrderRequest::forSender($this->clinicId())->with(['opticalClinic:id,name', 'sender:id,name']);
        $counts = collect(self::TABS)->map(fn ($statuses) => (clone $base)->whereIn('status', $statuses)->count());
        $term = trim($this->search);
        $requests = (clone $base)->whereIn('status', self::TABS[$this->tab])
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('patient_name', 'like', "%{$term}%")
                ->orWhere('sender_reference', 'like', "%{$term}%")->orWhere('order_number', 'like', "%{$term}%")))
            ->orderByRaw("status in ('query', 'quoted') desc")->orderByDesc('status_updated_at')->paginate(15);

        return view('livewire.secretary.sent-to-optical-component', [
            'requests' => $requests,
            'counts' => $counts,
            'hasLinks' => app(OpticalLinkService::class)->linksForSender()->isNotEmpty(),
            'canManageLinks' => app(OpticalLinkService::class)->canManageLinks(auth()->user()),
            'remakeReasons' => \App\Models\LensOrder::REMAKE_REASONS,
        ])->layout('layouts.clinic', ['menu' => 'reception']);
    }
}
