<?php

namespace App\Livewire\Optical;

use App\Models\OpticalOrderRequest;
use App\Services\ClinicAccessService;
use App\Services\OpticalLinks\OpticalLinkService;
use App\Support\Tenancy\TenantContext;
use Livewire\Component;
use Livewire\WithPagination;

/** Spectacle orders sent in by linked clinics, waiting to be turned into partner jobs. */
class IncomingRequestsComponent extends Component
{
    use WithPagination;

    public string $tab = 'new';
    public ?int $viewId = null;
    public string $question = '';
    public string $rejectReason = '';
    public string $action = '';

    protected $queryString = ['tab' => ['except' => 'new']];

    public const TABS = ['new' => 'New', 'quotes' => 'Quotes', 'query' => 'Waiting on the clinic', 'done' => 'Done'];

    /** Which requests each tab lists. A declined quote stays under Quotes until its quotation is cancelled. */
    private function scopeTab($query, string $tab)
    {
        return match ($tab) {
            'new' => $query->where('status', 'sent'),
            'query' => $query->where('status', 'query'),
            'quotes' => $query->where(fn ($q) => $q->whereIn('status', ['quoted', 'approved'])
                ->orWhere(fn ($q) => $q->where('status', 'quote_declined')->whereNull('order_cancelled_at'))),
            default => $query->where(fn ($q) => $q->whereIn('status', ['accepted', 'rejected', 'cancelled'])
                ->orWhere(fn ($q) => $q->where('status', 'quote_declined')->whereNotNull('order_cancelled_at'))),
        };
    }

    public function setTab(string $tab): void
    {
        abort_unless(array_key_exists($tab, self::TABS), 422);
        $this->tab = $tab;
        $this->viewId = null;
        $this->resetPage();
    }

    public function open(int $id): void
    {
        $this->viewId = app(OpticalLinkService::class)->incomingRequest($id)->id;
        $this->reset(['question', 'rejectReason', 'action']);
        $this->resetErrorBag();
    }

    public function close(): void
    {
        $this->reset(['viewId', 'question', 'rejectReason', 'action']);
    }

    public function startAction(string $action): void
    {
        abort_unless(in_array($action, ['', 'query', 'reject'], true), 422);
        $this->action = $action;
        $this->resetErrorBag();
    }

    public function accept()
    {
        $request = app(OpticalLinkService::class)->incomingRequest((int) $this->viewId);
        abort_unless($request->status === 'sent', 422);
        // A remake opens the remake form for the original job, answering the clinic's request.
        if ($request->isRemake()) {
            $original = $request->remakeOf;
            abort_unless($original?->lens_order_id, 404);

            return $this->redirectRoute('optical.orders.create', ['remake_of' => $original->lens_order_id, 'request_id' => $request->id], navigate: true);
        }

        return $this->redirectRoute('optical.orders.create', ['request_id' => $request->id], navigate: true);
    }

    /** The clinic approved the quote: the quotation becomes a job. */
    public function startJob(): void
    {
        $request = app(OpticalLinkService::class)->incomingRequest((int) $this->viewId);
        $order = app(OpticalLinkService::class)->startJob($request);
        session()->flash('success', "Job {$order->order_id} started for {$request->senderLabel()}. It is on their account at the approved price.");
    }

    /** Withdraw a quote (or tidy one the clinic declined): its quotation is cancelled. */
    public function cancelQuote(): void
    {
        $request = app(OpticalLinkService::class)->incomingRequest((int) $this->viewId);
        abort_unless(in_array($request->status, ['quoted', 'approved', 'quote_declined'], true) && $request->lens_order_id, 422);
        $order = \App\Models\LensOrder::find($request->lens_order_id);
        abort_unless($order && $order->status === 'Quotation', 422, 'This quote has already become a job.');
        app(\App\Services\OpticalOrderWorkflowService::class)->cancel($order->id);
        session()->flash('success', $request->status === 'quote_declined' ? 'Quotation cancelled.' : 'Quote withdrawn. The clinic sees that you withdrew it.');
    }

    public function sendQuestion(): void
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        $request = app(OpticalLinkService::class)->incomingRequest((int) $this->viewId);
        app(OpticalLinkService::class)->raiseQuery($request, $this->question, auth()->user());
        $this->reset(['question', 'action']);
        session()->flash('success', 'Question sent. The request moves back to New when the clinic answers.');
    }

    public function reject(): void
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        $request = app(OpticalLinkService::class)->incomingRequest((int) $this->viewId);
        app(OpticalLinkService::class)->reject($request, $this->rejectReason, auth()->user());
        $this->reset(['rejectReason', 'action']);
        session()->flash('success', 'Request turned down. The clinic sees your reason.');
    }

    public function render()
    {
        $clinicId = (int) app(TenantContext::class)->clinicId();
        $base = OpticalOrderRequest::forOptical($clinicId)->with(['senderClinic:id,name', 'partner:id,name']);
        $counts = collect(self::TABS)->map(fn ($label, $tab) => $this->scopeTab(clone $base, $tab)->count());
        $requests = $this->scopeTab(clone $base, $this->tab)
            ->orderByRaw("urgency = 'urgent' desc")->orderByDesc('status_updated_at')->paginate(15);

        return view('livewire.optical.incoming-requests-component', [
            'requests' => $requests,
            'counts' => $counts,
            'viewRequest' => $this->viewId ? (clone $base)->with('sender:id,name', 'remakeOf')->find($this->viewId) : null,
        ])->layout('layouts.optical');
    }
}
