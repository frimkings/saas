<?php

namespace App\Livewire;

use App\Models\AttentionAction;
use App\Services\Reminders\AttentionItems;
use Livewire\Component;

/**
 * The "Needs attention" list for clinic or optical staff: on dashboards (compact, a few per
 * group) and as its own page. Each item can be called, opened, marked done or snoozed until
 * tomorrow, with an optional note; that clears it for the whole team. A Super Admin can take
 * expired stock off the books from here.
 */
class AttentionPanelComponent extends Component
{
    public string $line = AttentionItems::CLINIC;
    public bool $compact = false;
    public bool $page = false;

    /** The item being acted on: "rule|type|id|action" (action: done, snoozed or writeoff). */
    public ?string $acting = null;
    public string $note = '';

    private const COMPACT_PER_GROUP = 3;
    private const WRITE_OFF = 'writeoff';

    public function mount(?string $line = null, bool $compact = false): void
    {
        $this->page = $line === null;
        $this->line = $line ?? (request()->routeIs('optical.*') ? AttentionItems::OPTICAL : AttentionItems::CLINIC);
        $this->line = $this->line === AttentionItems::OPTICAL ? AttentionItems::OPTICAL : AttentionItems::CLINIC;
        $this->compact = $compact;
    }

    public function start(string $rule, string $type, int $id, string $action): void
    {
        $this->acting = "{$rule}|{$type}|{$id}|{$action}";
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
        [$rule, $type, $id, $action] = explode('|', $this->acting) + [null, null, null, null];
        $this->validate(['note' => 'nullable|string|max:500']);

        if ($action === self::WRITE_OFF) {
            $removed = $items->writeOff((string) $type, (int) $id, $this->note);
            $message = $removed > 0 ? "Removed {$removed} from stock." : 'Cleared: none of this batch was left in stock.';
        } else {
            $items->act((string) $rule, (int) $id, (string) $action, $this->note, (string) $type);
            $message = $action === AttentionAction::DONE ? 'Marked as dealt with.' : 'Snoozed until tomorrow.';
        }

        $this->dispatch('notify', ...['type' => 'success', 'message' => $message]);
        $this->cancel();
    }

    public function render(AttentionItems $items)
    {
        $groups = $items->groups($this->line);
        $total = array_sum(array_map(fn ($g) => $g['items']->count(), $groups));
        $optical = $this->line === AttentionItems::OPTICAL;
        $view = view('livewire.attention-panel', [
            'groups'   => $groups,
            'total'    => $total,
            'perGroup' => $this->compact ? self::COMPACT_PER_GROUP : null,
            'allUrl'   => $optical ? route('optical.attention') : route('attention'),
            'stale'    => $items->counts($this->line)['stale'],
            'staleDays' => AttentionItems::thresholds()['stale_days'],
            'tidyUrl'  => $optical ? route('optical.attention.old-orders') : route('attention.old-orders'),
            'canWriteOff' => (bool) auth()->user()?->hasRole('Super Admin'),
        ]);

        return $this->page ? $view->layout(self::layoutFor($this->line)) : $view;
    }

    /** The page frame that matches the person's own menu. */
    public static function layoutFor(string $line): string
    {
        if ($line === AttentionItems::OPTICAL) {
            return 'layouts.optical';
        }
        $user = auth()->user();
        if ($user?->hasAnyRole(['Super Admin', 'Manager'])) {
            return 'layouts.admin.admin-layout';
        }

        return $user?->hasRole('Doctor') && ! $user->hasRole('Secretary') ? 'layouts.doctor.doctor-layout' : 'layouts.secretary.secretary-layout';
    }
}
