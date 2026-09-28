<?php

namespace App\Livewire\Optical;

use App\Livewire\Optical\Concerns\ManagesOrderPanel;
use App\Models\LensOrder;
use App\Models\OpticalSetting;
use App\Services\OpticalJobTrackingService;
use App\Services\OpticalOrderWorkflowService;
use Livewire\Component;

/**
 * Job tracking: one follow-up list of open jobs that are late, stuck or holding lenses,
 * each listed once with the reasons, worst first, and the actions to deal with it.
 * Lab turnaround figures are on the Reports page.
 */
class OpticalJobsComponent extends Component
{
    use ManagesOrderPanel;

    public string $filter = 'all';

    protected $queryString = ['filter' => ['except' => 'all']];

    public ?int $rescheduleOrderId = null;
    public string $newPickupDate = '';
    public string $newLabDate = '';
    public string $rescheduleReason = '';

    public ?int $abandonOrderId = null;
    public string $abandonReason = '';

    public function mount(): void
    {
        if (! array_key_exists($this->filter, OpticalJobTrackingService::FILTERS)) $this->filter = 'all';
    }

    public function setFilter(string $filter): void
    {
        abort_unless(array_key_exists($filter, OpticalJobTrackingService::FILTERS), 422);
        $this->filter = $filter;
    }

    private function isManager(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(['Manager', 'Super Admin']);
    }

    public function releaseLenses(int $orderId): void
    {
        $released = app(OpticalJobTrackingService::class)->releaseHeldLenses($orderId);
        session()->flash('success', $released > 0
            ? "{$released} held ".\Illuminate\Support\Str::plural('lens', $released).' released for sale. The job takes a lens again at glazing if one is free.'
            : 'This job was not holding any lenses.');
    }

    public function openReschedule(int $orderId): void
    {
        $order = LensOrder::whereIn('status', OpticalJobTrackingService::OPEN)->findOrFail($orderId);
        $this->rescheduleOrderId = $order->id;
        $this->newPickupDate = today()->addDays(3)->toDateString();
        $this->newLabDate = $order->status === 'Sent to Lab' ? today()->addDay()->toDateString() : '';
        $this->rescheduleReason = '';
        $this->resetValidation();
    }

    public function saveReschedule(): void
    {
        $this->resetValidation();
        $order = app(OpticalJobTrackingService::class)->reschedule((int) $this->rescheduleOrderId, $this->newPickupDate, $this->newLabDate ?: null, $this->rescheduleReason);
        $this->rescheduleOrderId = null;
        session()->flash('success', "New pickup date for {$order->order_id}: ".\Illuminate\Support\Carbon::parse($order->pickUpDate)->format('d M Y').'.');
        // Offer to tell the customer straight away, with the new date in the message.
        session()->flash('toastLink', app(OpticalJobTrackingService::class)->customerDelayLink($order->fresh(['patient', 'partnerClinic'])));
    }

    public function openAbandon(int $orderId): void
    {
        abort_unless($this->isManager(), 403);
        $this->abandonOrderId = LensOrder::whereIn('status', OpticalJobTrackingService::OPEN)->findOrFail($orderId)->id;
        $this->abandonReason = '';
        $this->resetValidation();
    }

    public function confirmAbandon(): void
    {
        $this->resetValidation();
        $order = app(OpticalOrderWorkflowService::class)->closeAbandoned((int) $this->abandonOrderId, $this->abandonReason);
        $this->abandonOrderId = null;
        session()->flash('success', "Job {$order->order_id} closed as abandoned.".((float) $order->cancellation_fee > 0 ? ' Deposit kept: '.currency().' '.number_format((float) $order->cancellation_fee, 2).'.' : ''));
    }

    public function closeForms(): void
    {
        $this->rescheduleOrderId = null;
        $this->abandonOrderId = null;
        $this->resetValidation();
    }

    /** The forms close in the browser (dismissLocal); tidy up when the server hears of it. */
    public function updatedRescheduleOrderId($value): void
    {
        if ($value === null) $this->resetValidation();
    }

    public function updatedAbandonOrderId($value): void
    {
        if ($value === null) $this->resetValidation();
    }

    public function render()
    {
        $tracking = app(OpticalJobTrackingService::class);
        $rows = $tracking->attention();
        $counts = collect(OpticalJobTrackingService::FILTERS)->keys()
            ->mapWithKeys(fn ($key) => [$key => $key === 'all' ? $rows->count() : $rows->filter(fn ($row) => in_array($key, $row['filters'], true))->count()]);
        $shown = $this->filter === 'all' ? $rows : $rows->filter(fn ($row) => in_array($this->filter, $row['filters'], true));

        return view('livewire.optical.optical-jobs-component', [
            'groups' => collect(OpticalJobTrackingService::BUCKETS)->map(fn ($label, $key) => ['label' => $label, 'rows' => $shown->where('bucket', $key)->values()])
                ->filter(fn ($group) => $group['rows']->isNotEmpty()),
            'counts' => $counts,
            'tracking' => $tracking,
            'stuckDays' => OpticalSetting::stuckJobDays(),
            'isManager' => $this->isManager(),
            'rescheduleOrder' => $this->rescheduleOrderId ? LensOrder::find($this->rescheduleOrderId) : null,
            'abandonOrder' => $this->abandonOrderId ? LensOrder::find($this->abandonOrderId) : null,
        ])->layout('layouts.optical');
    }
}
