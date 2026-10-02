<?php

namespace App\Livewire;

use App\Models\AttentionAction;
use App\Services\Reminders\AttentionItems;
use Livewire\Component;

/**
 * The "Needs attention" list for clinic or optical staff: on dashboards (compact, a few per
 * group) and as its own page. Each item can be called, opened, marked done or snoozed until
 * tomorrow, with an optional note; that clears it for the whole team.
 */
class AttentionPanelComponent extends Component
{
    public string $line = AttentionItems::CLINIC;
    public bool $compact = false;
    public bool $page = false;

    /** The item being marked done or snoozed: "rule|id|action". */
    public ?string $acting = null;
    public string $note = '';

    private const COMPACT_PER_GROUP = 3;

    public function mount(?string $line = null, bool $compact = false): void
    {
        $this->page = $line === null;
        $this->line = $line ?? (request()->routeIs('optical.*') ? AttentionItems::OPTICAL : AttentionItems::CLINIC);
        $this->line = $this->line === AttentionItems::OPTICAL ? AttentionItems::OPTICAL : AttentionItems::CLINIC;
        $this->compact = $compact;
    }

    public function start(string $rule, int $id, string $action): void
    {
        $this->acting = "{$rule}|{$id}|{$action}";
        $this->note = '';
    }

    public function cancel(): void
    {
        $this->acting = null;
        $this->note = '';
    }

    public function confirm(AttentionItems $items): void
    {
        if (!$this->acting) {
            return;
        }
        [$rule, $id, $action] = explode('|', $this->acting) + [null, null, null];
        $this->validate(['note' => 'nullable|string|max:500']);
        $items->act((string) $rule, (int) $id, (string) $action, $this->note);

        $this->dispatch('notify', ...['type' => 'success', 'message' => $action === AttentionAction::DONE ? 'Marked as dealt with.' : 'Snoozed until tomorrow.']);
        $this->cancel();
    }

    public function render(AttentionItems $items)
    {
        $groups = $items->groups($this->line);
        $total = array_sum(array_map(fn ($g) => $g['items']->count(), $groups));
        $view = view('livewire.attention-panel', [
            'groups'  => $groups,
            'total'   => $total,
            'perGroup' => $this->compact ? self::COMPACT_PER_GROUP : null,
            'allUrl'  => $this->line === AttentionItems::OPTICAL ? route('optical.attention') : route('attention'),
        ]);

        return $this->page
            ? $view->layout($this->line === AttentionItems::OPTICAL ? 'layouts.optical' : 'layouts.secretary.secretary-layout')
            : $view;
    }
}
