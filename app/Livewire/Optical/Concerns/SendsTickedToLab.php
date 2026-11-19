<?php

namespace App\Livewire\Optical\Concerns;

use App\Livewire\Optical\OpticalLabWorkbenchComponent;
use App\Models\LensOrder;
use App\Models\Supplier;
use App\Services\OpticalOrderWorkflowService;
use App\Support\OpticalAccess;
use Illuminate\Validation\ValidationException;

/**
 * Send every ticked job waiting to start to one lab, due back on one date, then offer one WhatsApp
 * to the lab listing them all. Used with TicksRows (pages other than the workbench, which has its own).
 */
trait SendsTickedToLab
{
    public bool $showBulkLab = false;
    public string $bulkLabSupplierId = '';
    public string $bulkExpectedBack = '';

    public function openBulkSendToLab(): void
    {
        abort_unless(OpticalAccess::bench(auth()->user()), 403, 'Lab staff and managers do this.');
        $this->bulkLabSupplierId = '';
        $this->bulkExpectedBack = '';
        $this->resetValidation();
        $this->showBulkLab = true;
    }

    public function bulkSendToLab(): void
    {
        abort_unless(OpticalAccess::bench(auth()->user()), 403, 'Lab staff and managers do this.');
        $this->validate(['bulkLabSupplierId' => 'nullable|integer', 'bulkExpectedBack' => 'nullable|date|after_or_equal:today'],
            [], ['bulkLabSupplierId' => 'lab', 'bulkExpectedBack' => 'expected back date']);
        $workflow = app(OpticalOrderWorkflowService::class);
        $labId = $this->bulkLabSupplierId === '' ? null : (int) $this->bulkLabSupplierId;
        $done = $skipped = $keep = $sent = [];
        foreach (LensOrder::whereIn('id', $this->tickedIds())->orderBy('id')->get() as $order) {
            if ($order->status !== 'Pending') {
                $skipped[] = $order->order_id.': '.$order->status.', not waiting to start';
                $keep[] = $order->id;
                continue;
            }
            try {
                $sent[] = $workflow->transition($order->id, 'Sent to Lab', $labId, $this->bulkExpectedBack ?: null);
                $done[] = $order->order_id;
            } catch (ValidationException $e) {
                $skipped[] = $order->order_id.': '.collect($e->errors())->flatten()->first();
                $keep[] = $order->id;
            }
        }
        $this->showBulkLab = false;
        $lab = $labId ? Supplier::find($labId) : null;
        $link = $sent && $lab ? OpticalLabWorkbenchComponent::labMessageLink($lab, $sent) : null;
        $this->report('Send to lab', $done, $skipped, $keep,
            $sent ? 'Sent to '.($lab?->name ?? 'the lab').($sent[0]->expected_back_at ? ', due back '.$sent[0]->expected_back_at->format('d M') : '').'.'
                .($lab && ! $link ? ' No WhatsApp number for '.$lab->name.'.' : '') : null,
            $link ? [['url' => $link, 'label' => 'Message '.$lab->name.' the list']] : []);
    }
}
