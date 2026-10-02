<?php

namespace App\Livewire;

use App\Services\Reminders\AttentionItems;
use Livewire\Component;

/**
 * Needs attention → Tidy up: open orders more than the clinic's "stop chasing" days late or
 * uncollected, mostly glasses handed over before anyone recorded it. Anyone can tick them and
 * mark them collected; each change goes on the order's history.
 */
class OldOrdersComponent extends Component
{
    public string $line = AttentionItems::CLINIC;

    /** @var array<int, string> ticked order ids */
    public array $selected = [];
    public string $note = '';

    public function mount(): void
    {
        $this->line = request()->routeIs('optical.*') ? AttentionItems::OPTICAL : AttentionItems::CLINIC;
    }

    public function selectAll(AttentionItems $items): void
    {
        $this->selected = $items->staleOrders($this->line)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function selectNone(): void
    {
        $this->selected = [];
    }

    public function markCollected(AttentionItems $items): void
    {
        $this->validate(['selected' => 'required|array|min:1', 'note' => 'nullable|string|max:500'],
            ['selected.required' => 'Tick the orders the patients have collected.']);
        $count = $items->tidyCollected($this->line, $this->selected, $this->note);
        $this->reset(['selected', 'note']);
        $this->dispatch('notify', ...['type' => 'success', 'message' => $count . ' ' . str('order')->plural($count) . ' marked collected.']);
    }

    public function render(AttentionItems $items)
    {
        $orders = $items->staleOrders($this->line);

        return view('livewire.old-orders', [
            'orders'    => $orders,
            'staleDays' => AttentionItems::thresholds()['stale_days'],
            'backUrl'   => $this->line === AttentionItems::OPTICAL ? route('optical.attention') : route('attention'),
        ])->layout(AttentionPanelComponent::layoutFor($this->line));
    }
}
