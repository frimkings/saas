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
        $this->selected = [];
    }

    private function isManager(): bool
    {
        return \App\Support\Access\Access::can('optical.manage');
    }

    public function releaseLenses(int $orderId): void
    {
        $released = app(OpticalJobTrackingService::class)->releaseHeldLenses($orderId);
        session()->flash('success', $released > 0
            ? "{$released} held ".\Illuminate\Support\Str::plural('lens', $released).' released for sale. The job takes a lens again at glazing if one is free.'
            : 'This job was not holding any lenses.');
    }

    /** Promising the customer a new date (and telling them) is front-desk work. */
    private function authorizeCustomerFacing(): void
    {
        abort_unless(\App\Support\OpticalAccess::desk(auth()->user()), 403, 'The front desk does this.');
    }

    use Concerns\TicksRows;

    /** '' | 'date' | 'close': the small form under the bar for a bulk new date or closing jobs. */
    public string $bulkMode = '';
    public string $bulkPickupDate = '';
    public string $bulkReason = '';

    public function openBulk(string $mode): void
    {
        abort_unless(in_array($mode, ['date', 'close'], true), 422);
        $mode === 'date' ? $this->authorizeCustomerFacing() : abort_unless($this->isManager(), 403);
        $this->bulkMode = $mode;
        $this->bulkPickupDate = today()->addDays(3)->toDateString();
        $this->bulkReason = '';
        $this->resetValidation();
    }

    private function tickedOpen()
    {
        return LensOrder::whereIn('status', OpticalJobTrackingService::OPEN)->whereIn('id', $this->tickedIds())->with(['patient', 'partnerClinic', 'labSupplier', 'lensLines'])->orderBy('id')->get();
    }

    /** Free the stock lenses every ticked job not yet started is holding (managers). */
    public function bulkReleaseLenses(): void
    {
        abort_unless($this->isManager(), 403);
        $tracking = app(OpticalJobTrackingService::class);
        $done = $skipped = $keep = [];
        foreach ($this->tickedOpen() as $order) {
            try {
                $released = $tracking->releaseHeldLenses($order->id);
                $released > 0 ? $done[] = $order->order_id : $skipped[] = $order->order_id.': not holding any lenses';
            } catch (\Illuminate\Validation\ValidationException $e) {
                $skipped[] = $order->order_id.': '.collect($e->errors())->flatten()->first();
                $keep[] = $order->id;
            }
        }
        $this->report('Release held lenses', $done, $skipped, $keep);
    }

    /** One new pickup date and reason for every ticked job; customers are texted when "Order Delay" SMS is on. */
    public function bulkNewDate(): void
    {
        $this->authorizeCustomerFacing();
        $this->validate(['bulkPickupDate' => 'required|date|after_or_equal:today', 'bulkReason' => 'required|string|min:3|max:500'],
            [], ['bulkPickupDate' => 'new pickup date', 'bulkReason' => 'reason']);
        $tracking = app(OpticalJobTrackingService::class);
        $done = $skipped = $keep = $links = [];
        $texted = 0;
        foreach ($this->tickedOpen() as $order) {
            try {
                $previous = $order->pickUpDate;
                $updated = $tracking->reschedule($order->id, $this->bulkPickupDate, null, $this->bulkReason)->fresh(['patient', 'partnerClinic']);
                $done[] = $order->order_id;
                if (app(\App\Services\Messaging\FollowUpSms::class)->orderDateChanged($updated, $previous)) $texted++;
            } catch (\Illuminate\Validation\ValidationException $e) {
                $skipped[] = $order->order_id.': '.collect($e->errors())->flatten()->first();
                $keep[] = $order->id;
            }
        }
        $this->bulkMode = '';
        $this->report('New pickup date', $done, $skipped, $keep,
            'Now due '.\Illuminate\Support\Carbon::parse($this->bulkPickupDate)->format('d M Y').'.'.($done ? ' '.$texted.' of '.count($done).' customers texted'.($texted < count($done) ? ' (use WhatsApp on each row for the rest).' : '.') : ''));
    }

    /** Close every ticked job as abandoned, with one reason (managers). */
    public function bulkClose(): void
    {
        abort_unless($this->isManager(), 403);
        $this->validate(['bulkReason' => 'required|string|min:10|max:500'], [], ['bulkReason' => 'reason']);
        $workflow = app(OpticalOrderWorkflowService::class);
        $done = $skipped = $keep = [];
        foreach ($this->tickedOpen() as $order) {
            try {
                $workflow->closeAbandoned($order->id, $this->bulkReason);
                $done[] = $order->order_id;
            } catch (\Illuminate\Validation\ValidationException $e) {
                $skipped[] = $order->order_id.': '.collect($e->errors())->flatten()->first();
                $keep[] = $order->id;
            }
        }
        $this->bulkMode = '';
        $this->report('Close as abandoned', $done, $skipped, $keep);
    }

    /** One WhatsApp per lab asking about all its ticked jobs. */
    public function chaseLabs(): void
    {
        $links = $skipped = [];
        $atLab = $this->tickedOpen()->where('status', 'Sent to Lab');
        foreach ($this->tickedOpen()->where('status', '!=', 'Sent to Lab') as $order) $skipped[] = $order->order_id.': not at an outside lab';
        foreach ($atLab->groupBy('lab_supplier_id') as $jobs) {
            $lab = $jobs->first()->labSupplier;
            if (! $lab) { foreach ($jobs as $order) $skipped[] = $order->order_id.': no lab recorded'; continue; }
            $list = $jobs->map(fn ($order) => '- '.$order->order_id.($order->sent_to_lab_at ? ' (sent '.$order->sent_to_lab_at->format('j M').($order->expected_back_at ? ', due '.$order->expected_back_at->format('j M') : '').')' : ''))->implode("\n");
            $url = \App\Support\Messaging\WhatsAppLink::to($lab->phone, 'Hello '.($lab->contact_person ?: $lab->name).', please can you update us on these jobs?'."\n".$list."\n".'When can we expect them back? — '.(app(\App\Support\Tenancy\TenantContext::class)->clinic()?->name ?? 'the optical shop'));
            $url ? $links[] = ['url' => $url, 'label' => 'Chase '.$lab->name.' ('.$jobs->count().')'] : $skipped[] = $lab->name.': no WhatsApp number';
        }
        $this->bulkReport = ['title' => 'Chase labs', 'done' => array_map(fn ($link) => $link['label'], $links), 'skipped' => $skipped,
            'note' => $links ? 'Open each message below and press send in WhatsApp.' : null, 'links' => $links];
    }

    public function openReschedule(int $orderId): void
    {
        $this->authorizeCustomerFacing();
        $order = LensOrder::whereIn('status', OpticalJobTrackingService::OPEN)->findOrFail($orderId);
        $this->rescheduleOrderId = $order->id;
        $this->newPickupDate = today()->addDays(3)->toDateString();
        $this->newLabDate = $order->status === 'Sent to Lab' ? today()->addDay()->toDateString() : '';
        $this->rescheduleReason = '';
        $this->resetValidation();
    }

    public function saveReschedule(): void
    {
        $this->authorizeCustomerFacing();
        $this->resetValidation();
        $previousDate = \App\Models\LensOrder::whereKey((int) $this->rescheduleOrderId)->value('pickUpDate');
        $order = app(OpticalJobTrackingService::class)->reschedule((int) $this->rescheduleOrderId, $this->newPickupDate, $this->newLabDate ?: null, $this->rescheduleReason);
        $this->rescheduleOrderId = null;
        $order = $order->fresh(['patient', 'partnerClinic']);
        // Texted automatically when the shop has "Order Delay" switched on; otherwise offer WhatsApp.
        $texted = app(\App\Services\Messaging\FollowUpSms::class)->orderDateChanged($order, $previousDate);
        session()->flash('success', "New pickup date for {$order->order_id}: ".\Illuminate\Support\Carbon::parse($order->pickUpDate)->format('d M Y').'.'
            .($texted ? ' The customer was texted the new date.' : ''));
        if (! $texted) session()->flash('toastLink', app(OpticalJobTrackingService::class)->customerDelayLink($order));
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
            'desk' => \App\Support\OpticalAccess::seesMoneyAndContacts(auth()->user()),
            'tickedCount' => count($this->tickedIds()),
            'rescheduleOrder' => $this->rescheduleOrderId ? LensOrder::find($this->rescheduleOrderId) : null,
            'abandonOrder' => $this->abandonOrderId ? LensOrder::find($this->abandonOrderId) : null,
        ])->layout('layouts.optical');
    }
}
